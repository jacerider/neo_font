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
 * Tests that a font selector claiming a font role's name is reported.
 *
 * A font's selector and the five font roles share one keyspace in the emitted
 * Tailwind theme, so a font declaring the selector `ui` writes the same key as
 * the `ui` role and one of the two is silently lost. Discovery already refuses
 * a definition whose derived id is a role name; these pin the matching report
 * for the selector, which is the half that actually reaches CSS.
 *
 * Reporting, not refusing: the promotion to a refusal has to reach sites in a
 * later release than the warning, so processing here must still return the
 * colliding definition exactly as it does today. The message deliberately says
 * nothing about which of the two entries wins — that is the role resolver's
 * subject, and this wording has to stay true either side of it.
 *
 * Every dependency is an interface, so no container and no database is reached.
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
    $definition = [
      'label' => 'Inter',
      'type' => 'google',
      'family' => 'Inter',
      'provider' => 'neo_font',
    ];
    if ($selector !== NULL) {
      $definition['selector'] = $selector;
    }
    return $definition;
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

}
