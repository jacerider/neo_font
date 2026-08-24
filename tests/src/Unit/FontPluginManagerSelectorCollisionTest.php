<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_font\Unit;

use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\neo_font\FontDefault;
use Drupal\neo_font\FontPluginManager;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * Tests definition processing, up to but not including its local branch.
 *
 * This class opened over the selector–role collision report and now carries the
 * rest of the method beside it, because both are the same seam: one class over
 * `processDefinition()` rather than two that construct the same manager.
 *
 * **The collision report.** A font's selector and the five font roles share one
 * keyspace in the emitted Tailwind theme, so a font declaring the selector `ui`
 * writes the same key as the `ui` role and one of the two is silently lost.
 * Discovery already refuses a definition whose derived id is a role name; the
 * collision tests pin the matching report for the selector, which is the half
 * that actually reaches CSS.
 *
 * Reporting, not refusing: the promotion to a refusal has to reach sites in a
 * later release than the warning, so processing here must still return the
 * colliding definition exactly as it does today. The message deliberately says
 * nothing about which of the two entries wins — that is the role resolver's
 * subject, and this wording has to stay true either side of it.
 *
 * **The refusals and the derivations.** Three refusals run before the id guard
 * — no family, no font type, a font type outside the supported list — and three
 * derivations run after it: the definition id is the plugin id with underscores
 * replaced by hyphens, the label falls back to the family, and the selector
 * falls back to the definition id. The derivations are where this module's
 * history has already cost something: the id/plugin-id split needed a bug fix,
 * and the selector fallback is what makes a selector–role collision reachable
 * only from an explicitly written selector. Last comes the branch that returns
 * a generic font before any local processing happens.
 *
 * These characterise what the module does today and change no production code,
 * so a behaviour that turns out to be wrong is recorded rather than repaired
 * here.
 *
 * Local-font processing itself is a kernel test — it calls `base_path()` and
 * asks the real extension handlers whether a provider exists.
 *
 * @see \Drupal\Tests\neo_font\Kernel\LocalFontProcessingTest
 *
 * Definition processing is public and takes the definition by reference, so it
 * is called directly on a constructed manager. Every dependency is an
 * interface, so no container and no database is reached; the two constant lists
 * reach the translation service, which is why a translation stub is set.
 */
#[Group('neo_font')]
final class FontPluginManagerSelectorCollisionTest extends UnitTestCase {

  /**
   * Everything the manager logged while processing a definition.
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
  }

  /**
   * Builds a font plugin manager logging to this test's record.
   *
   * @return \Drupal\neo_font\FontPluginManager
   *   The manager under test.
   */
  private function manager(): FontPluginManager {
    $logger = new class($this->records) extends AbstractLogger {

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

    $manager = new FontPluginManager(
      '/var/www/html/web',
      $this->createMock(ModuleHandlerInterface::class),
      $this->createMock(ThemeHandlerInterface::class),
      $this->createMock(FileSystemInterface::class),
      $this->createMock(FileUrlGeneratorInterface::class),
      $this->createMock(CacheBackendInterface::class),
      $logger,
    );
    $manager->setStringTranslation($this->getStringTranslationStub());
    return $manager;
  }

  /**
   * Builds a Google font definition, which touches no filesystem.
   *
   * @param string|null $selector
   *   The selector to declare, or NULL to declare none and let the definition
   *   fall back to its id.
   *
   * @return array<string, mixed>
   *   An unprocessed font definition.
   */
  private static function googleFont(?string $selector = NULL): array {
    return self::declaredFont($selector === NULL ? [] : ['selector' => $selector]);
  }

  /**
   * Builds an unprocessed font definition from a complete Google one.
   *
   * Google is the type that reaches the end of processing without touching the
   * filesystem, so it is the base every definition here starts from.
   *
   * Declaring nothing is not the same as declaring an empty value only in the
   * source YAML: the manager's own defaults are merged in first, so a key
   * dropped here arrives at the guards as the empty string the defaults carry.
   * That is exactly the shape a font declaring no family, no type or no label
   * has, which is why the refusals and the fallbacks can be reached by
   * unsetting a key.
   *
   * @param array<string, mixed> $declared
   *   Keys to declare on top of the base, or NULL as a value to declare that
   *   key not at all.
   *
   * @return array<string, mixed>
   *   An unprocessed font definition.
   */
  private static function declaredFont(array $declared = []): array {
    $definition = [
      'label' => 'Inter',
      'type' => 'google',
      'family' => 'Inter',
      'provider' => 'neo_font',
    ];
    foreach ($declared as $key => $value) {
      if ($value === NULL) {
        unset($definition[$key]);
        continue;
      }
      $definition[$key] = $value;
    }
    return $definition;
  }

  /**
   * Asserts that processing a definition is refused, and how.
   *
   * The refusals are `PluginException`s thrown out of discovery, so what a site
   * sees is the message. Asserting only the exception class would let a test
   * pass for whichever refusal happened to fire first, which matters here: the
   * three refusals run in sequence over the same definition, and switching one
   * off drops the definition into the next one rather than through all of them.
   *
   * @param array<string, mixed> $definition
   *   The definition to process. Passed by value: it is refused, so nothing
   *   the call would have written to it is of interest.
   * @param string $plugin_id
   *   The plugin id to process the definition under.
   * @param string $expected
   *   A fragment the refusal's message must contain.
   * @param string $message
   *   The assertion message.
   *
   * @return string
   *   The refusal's message, for any further assertion the caller makes.
   */
  private function assertRefuses(array $definition, string $plugin_id, string $expected, string $message): string {
    try {
      $this->manager()->processDefinition($definition, $plugin_id);
    }
    catch (PluginException $e) {
      $this->assertStringContainsString($expected, $e->getMessage(), $message);
      $this->assertStringContainsString($plugin_id, $e->getMessage(), 'The refusal names the font it refused.');
      return $e->getMessage();
    }
    $this->fail($message);
  }

  /**
   * Returns every logged warning with its context substituted in.
   *
   * @return list<string>
   *   The rendered warning messages, in the order they were logged.
   */
  private function renderedWarnings(): array {
    $rendered = [];
    foreach ($this->records as $record) {
      if ($record['level'] !== LogLevel::WARNING) {
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
   * Tests that it refuses a definition that declares no family.
   */
  public function testRefusesDefinitionThatDeclaresNoFamily(): void {
    $this->assertRefuses(
      self::declaredFont(['family' => NULL]),
      'brand_sans',
      'definition "family" is required',
      'A font declaring no family is refused.'
    );

    // The guard is an emptiness check, not an isset(), so a family declared as
    // an empty string is the same refusal and not an accepted declaration.
    $this->assertRefuses(
      self::declaredFont(['family' => '']),
      'brand_sans',
      'definition "family" is required',
      'A font declaring an empty family is refused.'
    );
  }

  /**
   * Tests that it refuses a definition that declares no font type.
   */
  public function testRefusesDefinitionThatDeclaresNoFontType(): void {
    $undeclared = $this->assertRefuses(
      self::declaredFont(['type' => NULL]),
      'brand_sans',
      'definition "type" is required',
      'A font declaring no font type is refused.'
    );

    // Two refusals read the font type, one after the other, and an undeclared
    // type is empty rather than absent — so it would satisfy the unsupported
    // guard just as well. Naming the message is what keeps this criterion from
    // passing on the wrong refusal.
    $this->assertStringNotContainsString(
      'is not supported',
      $undeclared,
      'A missing font type is refused for being missing, not for being unsupported.'
    );

    $this->assertRefuses(
      self::declaredFont(['type' => '']),
      'brand_sans',
      'definition "type" is required',
      'A font declaring an empty font type is refused.'
    );
  }

  /**
   * Tests that it refuses a font type outside the supported types.
   */
  public function testRefusesFontTypeThatIsNotOneOfTheSupportedTypes(): void {
    $this->assertRefuses(
      self::declaredFont(['type' => 'svg']),
      'brand_sans',
      'definition "type" is not supported',
      'A font declaring a type outside the supported list is refused.'
    );

    // The guard reads the supported types rather than a literal, so a type the
    // list carries has to get through it. `local` is left out: it is supported
    // and would be refused a step later, by local processing, for a provider
    // that no extension answers to.
    $manager = $this->manager();
    foreach (['google', 'generic'] as $type) {
      $supported = self::declaredFont(['type' => $type]);
      $manager->processDefinition($supported, 'brand_sans');
      $this->assertSame(
        $type,
        $supported['type'],
        sprintf('A font declaring the supported type "%s" is processed, not refused.', $type)
      );
    }

    $this->assertArrayHasKey(
      'local',
      $manager->getSupportedTypes(),
      'The third supported type is reached through local processing, in its own test.'
    );
  }

  /**
   * Tests that it derives the definition id by hyphenating the plugin id.
   */
  public function testDerivesDefinitionIdFromPluginIdReplacingUnderscoresWithHyphens(): void {
    $manager = $this->manager();

    $definition = self::declaredFont();
    $manager->processDefinition($definition, 'brand_sans_display');
    $this->assertSame('brand-sans-display', $definition['id'], 'Every underscore becomes a hyphen.');
    $this->assertStringNotContainsString(
      '_',
      (string) $definition['id'],
      'No underscore survives into the id, which is the half that reaches CSS.'
    );

    // The two are not interchangeable, and the module has already paid once for
    // confusing them: the plugin id keeps its underscores and stays the key a
    // plugin is instantiated by, while everything addressed by id uses the
    // hyphenated form.
    $this->assertNotSame(
      'brand_sans_display',
      $definition['id'],
      'The id is a derivation of the plugin id, not the plugin id itself.'
    );

    // A plugin id carrying no underscore is its own id.
    $single = self::declaredFont();
    $manager->processDefinition($single, 'brand');
    $this->assertSame('brand', $single['id']);

    // The id is derived from the plugin id, never read from the declaration —
    // a font declaring its own id has it overwritten.
    $declared = self::declaredFont(['id' => 'declared_id']);
    $manager->processDefinition($declared, 'brand_sans');
    $this->assertSame(
      'brand-sans',
      $declared['id'],
      'A declared id loses to the one derived from the plugin id.'
    );
  }

  /**
   * Tests that it falls back to the family when no label is declared.
   */
  public function testFallsBackToFamilyWhenDefinitionDeclaresNoLabel(): void {
    $manager = $this->manager();

    $definition = self::declaredFont(['label' => NULL, 'family' => 'Source Sans 3']);
    $manager->processDefinition($definition, 'brand_sans');
    $this->assertSame(
      'Source Sans 3',
      $definition['label'],
      'A font declaring no label is labelled by its family.'
    );

    // The fallback runs on emptiness, so a label declared as an empty string
    // takes the family too rather than reaching the settings form blank.
    $blank = self::declaredFont(['label' => '', 'family' => 'Source Sans 3']);
    $manager->processDefinition($blank, 'brand_sans');
    $this->assertSame('Source Sans 3', $blank['label']);

    // A declared label wins: the fallback is a fallback, not a rewrite.
    $labelled = self::declaredFont(['label' => 'Brand Sans', 'family' => 'Source Sans 3']);
    $manager->processDefinition($labelled, 'brand_sans');
    $this->assertSame('Brand Sans', $labelled['label']);
    $this->assertSame('Source Sans 3', $labelled['family'], 'The family is left as declared.');
  }

  /**
   * Tests that it falls back to the definition id when no selector is declared.
   */
  public function testFallsBackToDefinitionIdWhenDefinitionDeclaresNoFontSelector(): void {
    $manager = $this->manager();

    $definition = self::declaredFont(['selector' => NULL]);
    $manager->processDefinition($definition, 'brand_sans');
    $this->assertSame(
      'brand-sans',
      $definition['selector'],
      'A font declaring no selector selects on its own id.'
    );
    $this->assertSame(
      $definition['id'],
      $definition['selector'],
      'The fallback is the derived id, hyphens and all.'
    );

    // The fallback runs on emptiness, so a selector declared as an empty string
    // takes the id too rather than emitting a `.font-` utility with no name.
    $blank = self::declaredFont(['selector' => '']);
    $manager->processDefinition($blank, 'brand_sans');
    $this->assertSame('brand-sans', $blank['selector']);

    // A declared selector wins. That is what confines a selector-role collision
    // to an explicitly written selector: a defaulted one is the id, and an id
    // equal to a role name is refused a line later.
    $declared = self::declaredFont(['selector' => 'brand']);
    $manager->processDefinition($declared, 'brand_sans');
    $this->assertSame('brand', $declared['selector']);
    $this->assertSame('brand-sans', $declared['id'], 'A declared selector does not become the id.');
  }

  /**
   * A declared selector equal to a role name is reported.
   */
  public function testDeclaredSelectorMatchingRoleNameIsReported(): void {
    $definition = self::googleFont('ui');
    $this->manager()->processDefinition($definition, 'brand_sans');

    $this->assertCount(
      1,
      $this->records,
      'Processing a font whose selector claims the "ui" role logs exactly one message.'
    );
    $this->assertSame(
      LogLevel::WARNING,
      $this->records[0]['level'],
      'The selector-role collision is reported as a warning.'
    );
  }

  /**
   * The report names the font, the selector and the colliding role.
   */
  public function testTheReportNamesTheFontSelectorAndRole(): void {
    $definition = self::googleFont('heading');
    $this->manager()->processDefinition($definition, 'brand_serif');

    $warnings = $this->renderedWarnings();
    $this->assertCount(1, $warnings);
    $message = $warnings[0];

    $this->assertStringContainsString('brand_serif', $message, 'The warning names the font.');
    $this->assertStringContainsString('heading', $message, 'The warning names the selector and the role it collides with.');
    $this->assertMatchesRegularExpression('/\brole\b/i', $message, 'The warning says the collision is with a font role.');
    $this->assertMatchesRegularExpression('/\brename\b/i', $message, 'The warning tells the author to rename the selector.');

    $context = $this->records[0]['context'];
    $this->assertSame(
      ['brand_serif', 'heading', 'heading'],
      [
        (string) $context['%font'],
        (string) $context['%selector'],
        (string) $context['%role'],
      ],
      'The font, the selector and the role are each named in their own placeholder.'
    );
  }

  /**
   * A selector matching no role name is not reported.
   */
  public function testSelectorMatchingNoRoleNameIsNotReported(): void {
    $declared = self::googleFont('brand');
    $this->manager()->processDefinition($declared, 'brand_sans');
    $this->assertSame(
      [],
      $this->records->getArrayCopy(),
      'A declared selector that is not a role name is not reported.'
    );

    // A definition declaring no selector takes its id as its selector, which
    // is the shape the id guard already covers — it must stay silent too.
    $defaulted = self::googleFont();
    $this->manager()->processDefinition($defaulted, 'brand_sans');
    $this->assertSame('brand-sans', $defaulted['selector']);
    $this->assertSame(
      [],
      $this->records->getArrayCopy(),
      'A defaulted selector that is not a role name is not reported.'
    );
  }

  /**
   * The colliding definition is reported, not refused.
   */
  public function testTheCollidingDefinitionIsReportedNotRefused(): void {
    $definition = self::googleFont('ui');
    $this->manager()->processDefinition($definition, 'brand_sans');

    $this->assertCount(1, $this->records, 'The collision is reported.');
    $this->assertEquals(
      [
        'id' => 'brand-sans',
        'label' => 'Inter',
        'type' => 'google',
        'generic' => 'sans',
        'selector' => 'ui',
        'family' => 'Inter',
        'class' => FontDefault::class,
        'provider' => 'neo_font',
      ],
      $definition,
      'Reporting the collision leaves the processed definition exactly as it is today.'
    );
  }

  /**
   * A definition whose derived id is a role name is still refused.
   */
  public function testDefinitionWhoseIdIsRoleNameIsStillRefused(): void {
    $definition = self::googleFont();

    try {
      $this->manager()->processDefinition($definition, 'ui');
      $this->fail('A font whose derived id is a role name is refused.');
    }
    catch (PluginException $e) {
      $this->assertStringContainsString('conflicts with a setting type', $e->getMessage());
    }

    $this->assertSame(
      [],
      $this->records->getArrayCopy(),
      'The id guard refuses first, so a defaulted selector never reaches the collision report.'
    );
  }

  /**
   * Tests that it returns a generic font without running local processing.
   */
  public function testReturnsGenericFontWithoutRunningLocalProcessing(): void {
    // A generic font carrying the two things local processing chokes on: a
    // provider no extension answers to, and a face pointing at a file that does
    // not exist. Neither is looked at, because generic returns first.
    $declared = [
      'label' => NULL,
      'type' => 'generic',
      'family' => 'ui-sans-serif, system-ui, sans-serif',
      'generic' => 'sans',
      'provider' => 'no_such_extension',
      'faces' => [['src' => 'fonts/does-not-exist.woff2']],
    ];

    $definition = self::declaredFont($declared);
    $this->manager()->processDefinition($definition, 'system_sans');

    $this->assertSame(
      [['src' => 'fonts/does-not-exist.woff2']],
      $definition['faces'],
      'The faces come back exactly as declared: no source was resolved or rewritten.'
    );
    $this->assertSame(
      'no_such_extension',
      $definition['provider'],
      'The provider was never resolved to an extension path.'
    );

    // Everything before the branch still ran, so a generic font is a processed
    // definition and not an unprocessed one that skipped the method.
    $this->assertSame('system-sans', $definition['id']);
    $this->assertSame('ui-sans-serif, system-ui, sans-serif', $definition['label']);
    $this->assertSame('system-sans', $definition['selector']);
    $this->assertSame([], $this->records->getArrayCopy(), 'Nothing was reported.');

    // The control: the same declaration as a local font is refused on the
    // provider it names. That refusal is what the generic branch spared it, and
    // without it this test would pass on a definition local processing simply
    // had nothing to say about.
    $this->assertRefuses(
      self::declaredFont(['type' => 'local'] + $declared),
      'system_sans',
      'could not determine provider location',
      'The same declaration typed local is refused, so the generic branch is what returned early.'
    );
  }

}
