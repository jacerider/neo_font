<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_font\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\neo_font\FontPluginManager;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;

/**
 * Tests what a dropped font definition does to the set discovery returns.
 *
 * Definition processing decides *whether* a declaration is refused; this class
 * is about the other half — what the refusal costs. A **font declaration
 * refusal** removes that one definition from the set and nothing else, so the
 * assertions here are about the definitions that were never wrong: they are
 * still there, still resolved, still sorted, and a font that named the dropped
 * one as its generic is unchanged.
 *
 * That is the point of the whole plan. Until it, one bad declaration threw out
 * of the discovery pass and took every extension's fonts with it, on every page
 * render — so a theme's mistyped `src:` cost a site every font it had.
 *
 * The seam is the manager's own definition gathering rather than
 * `processDefinition()`, because "dropped" is not observable on a single
 * definition passed by reference: it is an absence from the set. Discovery
 * builds its own YAML discovery over the module and theme directory lists, so
 * these tests hand the mocked module handler a directory this test wrote — a
 * real `*.neo.font.yml` parsed by the real discovery, with no container and no
 * database. The cache backend is a mock whose `get()` returns nothing, which is
 * what makes every call a cold pass rather than a cached one.
 */
#[Group('neo_font')]
final class FontDeclarationDropTest extends UnitTestCase {

  /**
   * The directory this test writes its declaration files into.
   *
   * Not `$root`, which is the Drupal root and belongs to the base class.
   */
  private string $declarationRoot;

  /**
   * How many discovery passes this test has run.
   *
   * Each one gets its own directory, so nothing a previous pass wrote can be
   * read by the next one.
   */
  private int $passes = 0;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The discovery wraps every declared label in a TranslatableMarkup, and the
    // label sort casts it to a string, which reaches the translation service.
    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);

    $this->declarationRoot = sys_get_temp_dir() . '/neo-font-declarations-' . uniqid();
    mkdir($this->declarationRoot, 0777, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->deleteRecursive($this->declarationRoot);
    parent::tearDown();
  }

  /**
   * Removes a directory this test wrote, and everything under it.
   *
   * @param string $path
   *   The path to remove.
   */
  private function deleteRecursive(string $path): void {
    if (!is_dir($path)) {
      return;
    }
    foreach (scandir($path) ?: [] as $entry) {
      if ($entry === '.' || $entry === '..') {
        continue;
      }
      $child = $path . '/' . $entry;
      is_dir($child) ? $this->deleteRecursive($child) : unlink($child);
    }
    rmdir($path);
  }

  /**
   * Discovers the fonts a set of extensions declares.
   *
   * @param array<string, array<string, array<string, mixed>>> $extensions
   *   The declarations to write, keyed by extension name and then by the font
   *   key as the YAML spells it.
   *
   * @return array<string, array<string, mixed>>
   *   The definitions discovery returned, in the order it returned them.
   */
  private function definitionsFor(array $extensions): array {
    $pass = $this->declarationRoot . '/pass-' . ++$this->passes;
    $directories = [];
    foreach ($extensions as $extension => $declarations) {
      $directory = $pass . '/' . $extension;
      mkdir($directory, 0777, TRUE);
      file_put_contents($directory . '/' . $extension . '.neo.font.yml', Yaml::encode($declarations));
      $directories[$extension] = $directory;
    }

    $module_handler = $this->createMock(ModuleHandlerInterface::class);
    $module_handler->method('getModuleDirectories')->willReturn($directories);
    $module_handler->method('moduleExists')
      ->willReturnCallback(static fn (string $module): bool => isset($directories[$module]));

    $theme_handler = $this->createMock(ThemeHandlerInterface::class);
    $theme_handler->method('getThemeDirectories')->willReturn([]);

    $manager = new FontPluginManager(
      $this->declarationRoot,
      $module_handler,
      $theme_handler,
      $this->createMock(FileSystemInterface::class),
      $this->createMock(FileUrlGeneratorInterface::class),
      $this->createMock(CacheBackendInterface::class),
      new NullLogger(),
    );
    $manager->setStringTranslation($this->getStringTranslationStub());

    return $manager->getDefinitions();
  }

  /**
   * A declaration nothing is wrong with.
   *
   * @param array<string, mixed> $declared
   *   Keys to declare on top of the sound base.
   *
   * @return array<string, mixed>
   *   A font declaration as it would be written in a `*.neo.font.yml` file.
   */
  private static function soundFont(array $declared = []): array {
    return $declared + [
      'label' => 'Sound',
      'family' => 'Sound',
      'type' => 'google',
    ];
  }

  /**
   * Tests that it drops a definition the outer checks refuse.
   */
  public function testDropsDefinitionTheOuterChecksRefuse(): void {
    $refused = [
      'brand_no_family' => ['label' => 'Brand No Family', 'type' => 'google'],
      'brand_no_type' => ['label' => 'Brand No Type', 'family' => 'Brand'],
      'brand_bad_type' => ['label' => 'Brand Bad Type', 'family' => 'Brand', 'type' => 'svg'],
    ];

    foreach ($refused as $font => $declaration) {
      $definitions = $this->definitionsFor([
        'brand_theme' => [
          $font => $declaration,
          'brand_sound' => self::soundFont(),
        ],
      ]);

      $this->assertArrayNotHasKey(
        $font,
        $definitions,
        sprintf('The definition "%s" is dropped from the set discovery returns.', $font)
      );
      $this->assertArrayHasKey(
        'brand_sound',
        $definitions,
        sprintf('Dropping "%s" left the sound font beside it alone.', $font)
      );
    }
  }

  /**
   * Tests that it drops a definition whose derived id is a font role name.
   */
  public function testDropsDefinitionWhoseDerivedIdIsFontRoleName(): void {
    $definitions = $this->definitionsFor([
      'brand_theme' => [
        'ui' => self::soundFont(['label' => 'Claims The UI Role']),
        'brand_sound' => self::soundFont(),
      ],
    ]);

    // An id claiming a role name destroys that role's token, so the font
    // cannot be built even though everything else about it is sound.
    $this->assertArrayNotHasKey(
      'ui',
      $definitions,
      'A definition whose derived id is a font role name is dropped.'
    );
    $this->assertArrayHasKey('brand_sound', $definitions);
  }

  /**
   * Tests that it leaves every other declared font in the discovered set.
   */
  public function testLeavesEveryOtherDeclaredFontInTheDiscoveredSet(): void {
    $sound = [
      'brand_theme' => [
        'brand_generic' => [
          'label' => 'Fixture Alpha 2',
          'family' => 'Brand Generic',
          'type' => 'generic',
          'generic' => "ui-brand, 'Brand Fallback', sans-serif",
        ],
        'brand_google' => [
          'label' => 'Fixture Alpha 10',
          'family' => 'Brand Google',
          'type' => 'google',
          'generic' => 'brand_generic',
        ],
      ],
      'other_theme' => [
        'other_google' => self::soundFont(['label' => 'Fixture Alpha 3']),
      ],
    ];

    // The same declarations, with one font that cannot be built dropped in
    // among them — in the middle of one extension's file, so nothing about the
    // survivors' position or resolution is left untouched by accident.
    $broken = $sound;
    $broken['brand_theme'] = [
      'brand_broken' => ['label' => 'Fixture Alpha 1', 'type' => 'google'],
    ] + $broken['brand_theme'];

    $this->assertSame(
      self::summarise($this->definitionsFor($sound)),
      self::summarise($this->definitionsFor($broken)),
      'Every font but the dropped one came back exactly as it would have if the bad declaration had never been written.'
    );
  }

  /**
   * Tests that a dropped definition reaches neither resolution nor the sort.
   */
  public function testNeverLetsDroppedDefinitionReachGenericResolutionOrSort(): void {
    $definitions = $this->definitionsFor([
      'brand_theme' => [
        // A generic carrying a fallback stack and no family, so it is refused
        // — and named as another font's generic, so generic resolution would
        // substitute its stack if it were still in the set.
        'brand_broken_generic' => [
          'label' => 'AAA Broken Generic',
          'type' => 'generic',
          'generic' => "ui-broken, 'Broken Fallback', sans-serif",
        ],
        'brand_google' => [
          'label' => 'Fixture Alpha 10',
          'family' => 'Brand Google',
          'type' => 'google',
          'generic' => 'brand_broken_generic',
        ],
        'brand_sans' => self::soundFont(['label' => 'Fixture Alpha 2']),
      ],
    ]);

    // Both run after the drop, over the survivors only. The label sorts first
    // of the three under any comparison, so a dropped definition that reached
    // the sort would be the first key here.
    $this->assertSame(
      ['brand_sans', 'brand_google'],
      array_keys($definitions),
      'The label sort ordered the survivors and never saw the dropped definition.'
    );

    $this->assertSame(
      'brand_broken_generic',
      $definitions['brand_google']['generic'],
      'Generic resolution never saw the dropped definition, so the font that named it keeps the literal id — exactly as it does today for a generic that was never declared at all.'
    );
  }

  /**
   * Reduces a definition set to what these tests compare.
   *
   * A definition's label is a TranslatableMarkup by the time discovery is done
   * with it, and comparing two of those compares the translation service behind
   * them rather than the string in front. The order is preserved, because the
   * order is one of the things being asserted.
   *
   * @param array<string, array<string, mixed>> $definitions
   *   The definitions to reduce.
   *
   * @return array<string, array<string, string>>
   *   The comparable projection, in the order the definitions came back.
   */
  private static function summarise(array $definitions): array {
    $summary = [];
    foreach ($definitions as $plugin_id => $definition) {
      $summary[$plugin_id] = [
        'id' => (string) $definition['id'],
        'label' => (string) $definition['label'],
        'type' => (string) $definition['type'],
        'family' => (string) $definition['family'],
        'generic' => (string) $definition['generic'],
        'selector' => (string) $definition['selector'],
        'provider' => (string) $definition['provider'],
      ];
    }
    return $summary;
  }

}
