<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_font\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Extension\Extension;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\neo_font\FontPluginManager;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

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
 *
 * **The local branch's four refusals live here too.** A local font resolves its
 * provider to an extension directory and rewrites every face's source against
 * it, and four things can go wrong on the way: a provider that is neither an
 * installed module nor an installed theme, a font declaring no faces, a face
 * declaring no source, and a source that is not on disk. All four end *before*
 * the rewrite, which is the one part of the branch that calls `base_path()` —
 * bootstrap global state, and the reason the successful rewrite is a kernel
 * test and these are not. Provider resolution asks the module and theme
 * handlers, both interfaces, and the existence check reads the app root, which
 * is a constructor string this test points at a directory it owns.
 *
 * @see \Drupal\Tests\neo_font\Kernel\LocalFontProcessingTest
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
   * Everything the manager logged during the current pass.
   *
   * A refusal is not a throw: definition processing logs it and the definition
   * is dropped, so at the `processDefinition()` seam the log line is what a
   * refusal looks like.
   *
   * @var \ArrayObject<int, array{level: mixed, message: string, context: array<string, mixed>}>
   */
  private \ArrayObject $records;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->records = new \ArrayObject();
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
    $relative = 'pass-' . ++$this->passes;
    $paths = [];
    $directories = [];
    foreach ($extensions as $extension => $declarations) {
      $paths[$extension] = $relative . '/' . $extension;
      $directory = $this->declarationRoot . '/' . $paths[$extension];
      mkdir($directory, 0777, TRUE);
      file_put_contents($directory . '/' . $extension . '.info.yml', "name: " . $extension . "\ntype: module\n");
      file_put_contents($directory . '/' . $extension . '.neo.font.yml', Yaml::encode($declarations));
      $directories[$extension] = $directory;
    }

    return $this->manager($paths, [], $directories)->getDefinitions();
  }

  /**
   * Builds a manager whose extension handlers answer for named extensions.
   *
   * The app root is the directory this test owns, so an extension path is
   * relative to it and a face source resolves to a real file this test either
   * wrote or deliberately did not.
   *
   * @param array<string, string> $modules
   *   Installed module paths, keyed by module name, relative to the app root.
   * @param array<string, string> $themes
   *   Installed theme paths, keyed by theme name, relative to the app root.
   * @param array<string, string> $directories
   *   Absolute directories the YAML discovery reads declarations from, keyed by
   *   module name. Empty for a test that calls definition processing directly.
   *
   * @return \Drupal\neo_font\FontPluginManager
   *   The manager under test, logging into this test's record.
   */
  private function manager(array $modules = [], array $themes = [], array $directories = []): FontPluginManager {
    $root = $this->declarationRoot;

    $module_handler = $this->createMock(ModuleHandlerInterface::class);
    $module_handler->method('getModuleDirectories')->willReturn($directories);
    $module_handler->method('moduleExists')
      ->willReturnCallback(static fn (string $module): bool => isset($modules[$module]));
    $module_handler->method('getModule')
      ->willReturnCallback(static fn (string $module): Extension => new Extension($root, 'module', $modules[$module] . '/' . $module . '.info.yml'));

    $theme_handler = $this->createMock(ThemeHandlerInterface::class);
    $theme_handler->method('getThemeDirectories')->willReturn([]);
    $theme_handler->method('themeExists')
      ->willReturnCallback(static fn (string $theme): bool => isset($themes[$theme]));
    $theme_handler->method('getTheme')
      ->willReturnCallback(static fn (string $theme): Extension => new Extension($root, 'theme', $themes[$theme] . '/' . $theme . '.info.yml'));

    $manager = new FontPluginManager(
      $root,
      $module_handler,
      $theme_handler,
      $this->createMock(CacheBackendInterface::class),
      $this->collectingLogger(),
    );
    $manager->setStringTranslation($this->getStringTranslationStub());

    return $manager;
  }

  /**
   * A logger appending everything it is handed to this test's record.
   *
   * @return \Psr\Log\AbstractLogger
   *   The collecting logger.
   */
  private function collectingLogger(): AbstractLogger {
    return new class($this->records) extends AbstractLogger {

      /**
       * Constructs the collecting logger.
       *
       * @param \ArrayObject<int, array{level: mixed, message: string, context: array<string, mixed>}> $records
       *   The record every logged message is appended to.
       */
      public function __construct(private readonly \ArrayObject $records) {}

      /**
       * {@inheritdoc}
       */
      public function log($level, string|\Stringable $message, array $context = []): void {
        /** @var array{level: mixed, message: string, context: array<string, mixed>} $record */
        $record = [
          'level' => $level,
          'message' => (string) $message,
          'context' => $context,
        ];
        $this->records[] = $record;
      }

    };
  }

  /**
   * Starts a fresh log record, so an assertion sees only its own pass.
   */
  private function freshRecord(): void {
    $this->records = new \ArrayObject();
  }

  /**
   * Every refusal logged during the current pass, rendered.
   *
   * Filtered on the level rather than the wording, because the severity split
   * — refusals at error, reports at warning — is the whole mechanism.
   *
   * @return list<string>
   *   The rendered refusal messages, in the order they were logged.
   */
  private function refusals(): array {
    $rendered = [];
    foreach ($this->records as $record) {
      if ($record['level'] !== LogLevel::ERROR) {
        continue;
      }
      $replacements = [];
      foreach ($record['context'] as $placeholder => $value) {
        if (is_scalar($value) || $value instanceof \Stringable) {
          $replacements[$placeholder] = (string) $value;
        }
      }
      $rendered[] = strtr($record['message'], $replacements);
    }
    return $rendered;
  }

  /**
   * Writes an extension directory shipping the given files, and returns it.
   *
   * @param string $extension
   *   The extension name.
   * @param string ...$files
   *   Files to write beneath it, relative to the extension's own directory.
   *
   * @return string
   *   The extension's path, relative to the app root.
   */
  private function extensionShipping(string $extension, string ...$files): string {
    $path = 'extensions/' . $extension;
    $directory = $this->declarationRoot . '/' . $path;
    if (!is_dir($directory)) {
      mkdir($directory, 0777, TRUE);
    }
    // An `Extension` refuses to be built for an info file that is not there,
    // and the extension handlers hand one back for every provider they
    // resolve — so an extension a test invents has to look like one on disk.
    file_put_contents($directory . '/' . $extension . '.info.yml', "name: " . $extension . "\ntype: module\n");
    foreach ($files as $file) {
      $absolute = $directory . '/' . $file;
      if (!is_dir(dirname($absolute))) {
        mkdir(dirname($absolute), 0777, TRUE);
      }
      // A deliberately empty placeholder: the check is for existence, not for
      // parseability.
      file_put_contents($absolute, '');
    }
    return $path;
  }

  /**
   * Builds an unprocessed local font definition.
   *
   * @param string $provider
   *   The extension the definition claims to have been declared by.
   * @param array<int, array<string, mixed>>|null $faces
   *   The faces to declare, or NULL to declare no `faces` key at all.
   *
   * @return array<string, mixed>
   *   A local font definition, before processing.
   */
  private static function localFont(string $provider, ?array $faces): array {
    $definition = [
      'label' => 'Brand Local',
      'type' => 'local',
      'family' => 'Brand Local',
      'provider' => $provider,
    ];
    if ($faces !== NULL) {
      $definition['faces'] = $faces;
    }
    return $definition;
  }

  /**
   * Builds one font face.
   *
   * @param string|null $src
   *   The source to declare, or NULL to declare no `src` key at all.
   *
   * @return array<string, mixed>
   *   A font face, before processing.
   */
  private static function face(?string $src): array {
    $face = [
      'style' => 'normal',
      'weight' => 400,
      'format' => 'woff2',
    ];
    if ($src !== NULL) {
      $face['src'] = $src;
    }
    return $face;
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
   * Tests that it drops a local font whose provider resolves to no extension.
   */
  public function testDropsLocalFontWhoseProviderIsNeitherInstalledModuleNorInstalledTheme(): void {
    $module = $this->extensionShipping('brand_module', 'fonts/brand.woff2');
    $theme = $this->extensionShipping('brand_theme', 'fonts/brand.woff2');
    $installed = [['brand_module' => $module], ['brand_theme' => $theme]];

    // A provider no installed extension answers to. There is no directory to
    // resolve the declared sources against, so nothing about the font can be
    // built and it is dropped rather than half-processed.
    $definition = self::localFont('brand_absent_extension', [self::face('fonts/brand.woff2')]);
    $this->manager(...$installed)->processDefinition($definition, 'brand_local');

    $refusals = $this->refusals();
    $this->assertCount(1, $refusals, 'A provider that is neither an installed module nor an installed theme is refused.');
    $this->assertStringContainsString('brand_absent_extension', $refusals[0], 'The refusal names the provider it could not resolve.');
    $this->assertStringContainsString('brand_local', $refusals[0], 'The refusal names the font key.');
    $this->assertMatchesRegularExpression('/\binstalled module\b/i', $refusals[0]);
    $this->assertMatchesRegularExpression('/\binstalled theme\b/i', $refusals[0]);
    $this->assertSame(
      'fonts/brand.woff2',
      $definition['faces'][0]['src'],
      'A font whose provider never resolved has no source rewritten.'
    );

    // Both branches that do resolve, proved by the directory the *next* check
    // looks in: a resolved provider is an extension path, and that path is
    // what a missing file is reported against. Asserting it this way keeps
    // the successful rewrite — the one part of the branch that reaches
    // base_path() — out of a unit test.
    foreach (['brand_module' => $module, 'brand_theme' => $theme] as $provider => $path) {
      $this->freshRecord();
      $declared = self::localFont($provider, [self::face('fonts/absent.woff2')]);
      $this->manager(...$installed)->processDefinition($declared, 'brand_local');

      $refusal = $this->refusals()[0] ?? '';
      $this->assertStringNotContainsString(
        'installed module',
        $refusal,
        sprintf('The provider "%s" resolves, so it is not refused for its provider.', $provider)
      );
      $this->assertStringContainsString(
        $path . '/fonts/absent.woff2',
        $refusal,
        sprintf('The provider "%s" resolved to its own extension directory.', $provider)
      );
    }
  }

  /**
   * Tests that it drops a local font that declares no faces.
   */
  public function testDropsLocalFontThatDeclaresNoFaces(): void {
    $module = $this->extensionShipping('brand_module', 'fonts/brand.woff2');

    // A local font is nothing but its faces — there is no source to resolve
    // and no font-face rule to emit — so declaring none is refused rather
    // than quietly producing a font that can never load.
    $cases = [
      'no faces key at all' => NULL,
      'an empty faces list' => [],
    ];
    foreach ($cases as $description => $faces) {
      $this->freshRecord();
      $definition = self::localFont('brand_module', $faces);
      $this->manager(['brand_module' => $module])->processDefinition($definition, 'brand_local');

      $refusals = $this->refusals();
      $this->assertCount(1, $refusals, sprintf('A local font declaring %s is refused.', $description));
      $this->assertStringContainsString('brand_local', $refusals[0], 'The refusal names the font key.');
      $this->assertMatchesRegularExpression('/\bfaces\b/i', $refusals[0], 'The refusal says the font declared no faces.');
    }
  }

  /**
   * Tests that it drops a local font with a face that declares no source.
   */
  public function testDropsLocalFontWithFaceThatDeclaresNoSource(): void {
    $module = $this->extensionShipping('brand_module', 'fonts/brand.woff2');

    $cases = [
      'no src key at all' => [self::face(NULL)],
      'an empty src' => [self::face('')],
      // Every face is checked, not only the first: a font whose second face
      // lost its source is refused as surely as one whose only face did.
      'a later face with no src' => [self::face('fonts/brand.woff2'), self::face(NULL)],
    ];
    foreach ($cases as $description => $faces) {
      $this->freshRecord();
      $definition = self::localFont('brand_module', $faces);
      $this->manager(['brand_module' => $module])->processDefinition($definition, 'brand_local');

      $refusals = $this->refusals();
      $this->assertCount(1, $refusals, sprintf('A local font with a face declaring %s is refused.', $description));
      $this->assertStringContainsString('brand_local', $refusals[0], 'The refusal names the font key.');
      $this->assertMatchesRegularExpression('/\bsource\b/i', $refusals[0], 'The refusal says the face declared no source.');

      // The sound face beside the broken one is not rewritten either. The
      // font is dropped whole, so nothing it declared reaches CSS — and a
      // refused declaration never reaches the rewrite at all.
      $this->assertSame(
        $faces[0]['src'] ?? NULL,
        $definition['faces'][0]['src'] ?? NULL,
        'A refused local font has no face rewritten, not even a sound one.'
      );
    }
  }

  /**
   * Tests that a face pointing at nothing is dropped, naming the path.
   */
  public function testDropsLocalFontWithFaceWhoseSourceIsNotOnDiskNamingThePath(): void {
    $module = $this->extensionShipping('brand_module', 'fonts/brand.woff2');
    $mistyped = 'fonts/brand-mistpyed.woff2';
    $this->assertFileDoesNotExist(
      $this->declarationRoot . '/' . $module . '/' . $mistyped,
      'The face this test declares points at nothing, which is the whole subject.'
    );

    $definition = self::localFont('brand_module', [self::face($mistyped)]);
    $this->manager(['brand_module' => $module])->processDefinition($definition, 'brand_local');

    $refusals = $this->refusals();
    $this->assertCount(1, $refusals, 'A face whose source is not on disk is refused.');
    $this->assertStringContainsString('brand_local', $refusals[0], 'The refusal names the font key.');
    $this->assertStringContainsString($mistyped, $refusals[0], 'The refusal names the source as the author wrote it.');

    // The fragment the author wrote is already in front of them; what they
    // cannot see is the directory it resolved against, which is the whole
    // reason the file was not found. A message without the path costs them a
    // grep, and this is the refusal a theme author actually trips.
    $this->assertStringContainsString(
      $this->declarationRoot . '/' . $module . '/' . $mistyped,
      $refusals[0],
      'The refusal names the absolute path the existence check looked at.'
    );

    // Three wrong paths cost one pass rather than three builds: every face
    // pointing at nothing is named, not just the first one met.
    $this->freshRecord();
    $three = self::localFont('brand_module', [
      self::face('fonts/one.woff2'),
      self::face('fonts/two.woff2'),
      self::face('fonts/three.woff2'),
    ]);
    $this->manager(['brand_module' => $module])->processDefinition($three, 'brand_local');

    $refusals = $this->refusals();
    $this->assertCount(3, $refusals, 'Every face pointing at nothing is refused, not just the first.');
    foreach (['one', 'two', 'three'] as $index => $name) {
      $this->assertStringContainsString('fonts/' . $name . '.woff2', $refusals[$index]);
    }

    // And the drop itself, over the whole set. This is the reported friction
    // in full: one mistyped `src:` in one theme's file used to throw out of
    // the discovery pass on every page render, taking every other extension's
    // fonts with it. Now it costs its own font and nothing else.
    $definitions = $this->definitionsFor([
      'brand_theme' => [
        'brand_local' => [
          'label' => 'Brand Local',
          'family' => 'Brand Local',
          'type' => 'local',
          'faces' => [['src' => $mistyped, 'format' => 'woff2']],
        ],
        'brand_sound' => self::soundFont(),
      ],
      'other_theme' => [
        'other_sound' => self::soundFont(['label' => 'Other Sound']),
      ],
    ]);

    $this->assertArrayNotHasKey('brand_local', $definitions, 'The local font whose face points at nothing is dropped from the set.');
    $this->assertArrayHasKey('brand_sound', $definitions, 'The sound font beside it in the same file survives.');
    $this->assertArrayHasKey('other_sound', $definitions, 'The other extension\'s font survives too.');
  }

  /**
   * Tests that nothing throws out of the local branch, however malformed.
   */
  public function testThrowsNothingOutOfTheLocalBranchForAnyDeclaration(): void {
    $module = $this->extensionShipping('brand_module', 'fonts/brand.woff2');
    $theme = $this->extensionShipping('brand_theme', 'fonts/brand.woff2');

    // Every shape the local branch can be handed, including values that are
    // not even the type the branch reads. None of these can end a request:
    // the Google font link resolves the whole definition set inside
    // hook_page_attachments, so a throw here is a stack trace on every page
    // render of every site.
    $malformed = [
      'a provider no extension answers to' => self::localFont('brand_absent_extension', [self::face('fonts/brand.woff2')]),
      'no provider at all' => ['type' => 'local', 'family' => 'Brand', 'label' => 'Brand'],
      'a provider that is not a string' => ['type' => 'local', 'family' => 'Brand', 'provider' => ['brand_module']],
      'no faces' => self::localFont('brand_module', NULL),
      'an empty faces list' => self::localFont('brand_module', []),
      'a faces value that is not a list' => [
        'type' => 'local',
        'family' => 'Brand',
        'provider' => 'brand_module',
        'faces' => 'fonts/brand.woff2',
      ],
      'a face that is not an array' => [
        'type' => 'local',
        'family' => 'Brand',
        'provider' => 'brand_module',
        'faces' => ['fonts/brand.woff2'],
      ],
      'a face with no source' => self::localFont('brand_module', [self::face(NULL)]),
      'a face whose source is not a string' => [
        'type' => 'local',
        'family' => 'Brand',
        'provider' => 'brand_module',
        'faces' => [['src' => ['fonts/brand.woff2']]],
      ],
      'a source that is not on disk' => self::localFont('brand_module', [self::face('fonts/absent.woff2')]),
      'a theme provider and nothing else right' => [
        'type' => 'local',
        'family' => 'Brand',
        'provider' => 'brand_theme',
        'faces' => [[]],
      ],
      'every one of them at once' => [
        'type' => 'local',
        'family' => '',
        'provider' => 'brand_absent_extension',
        'faces' => 'not a list',
      ],
    ];

    foreach ($malformed as $description => $definition) {
      $this->freshRecord();
      $this->manager(['brand_module' => $module], ['brand_theme' => $theme])
        ->processDefinition($definition, 'brand_local');

      $this->assertNotEmpty(
        $this->refusals(),
        sprintf('A local declaration with %s is refused through the log rather than thrown out of the pass.', $description)
      );
    }
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
