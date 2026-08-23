<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_font\Unit;

use Drupal\neo_font\FontInterface;
use Drupal\neo_font\FontPluginManagerInterface;
use Drupal\neo_font\FontRoleResolver;
use Drupal\neo_settings\Plugin\SettingsInterface;
use Drupal\neo_settings\SettingsRepositoryInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Tests the font role resolver.
 *
 * The resolver is the module's single owner of the role map: which declared
 * font serves each of the five font roles. Both build subscribers used to
 * rebuild that rule inline, and the plugin-id-versus-definition-id split it
 * turns on has already been miscoded once, so these are the pins that keep it
 * written in one place and right.
 */
#[Group('neo_font')]
final class FontRoleResolverTest extends UnitTestCase {

  /**
   * The five font roles, as the plugin manager reports them.
   */
  private const ROLES = [
    'primary' => 'Primary',
    'secondary' => 'Secondary',
    'accent' => 'Accent',
    'heading' => 'Heading',
    'ui' => 'UI',
  ];

  /**
   * Builds a font plugin manager whose discovery returns the given definitions.
   *
   * Every definition is instantiated as its own font mock, so the caller can
   * assert which instance a role resolved to.
   *
   * @param array<string, array<string, mixed>> $definitions
   *   Font definitions, keyed by plugin id.
   *
   * @return array{0: \Drupal\neo_font\FontPluginManagerInterface&\PHPUnit\Framework\MockObject\MockObject, 1: array<string, \Drupal\neo_font\FontInterface&\PHPUnit\Framework\MockObject\MockObject>}
   *   The mocked plugin manager, and the font mock it returns per plugin id.
   */
  private function mockPluginManager(array $definitions): array {
    $fonts = [];
    foreach (array_keys($definitions) as $plugin_id) {
      $fonts[$plugin_id] = $this->createMock(FontInterface::class);
    }
    $manager = $this->createMock(FontPluginManagerInterface::class);
    $manager->method('getDefinitions')->willReturn($definitions);
    $manager->method('getSettingTypes')->willReturn(self::ROLES);
    $manager->method('createInstance')->willReturnCallback(
      static fn (string $plugin_id): FontInterface => $fonts[$plugin_id]
    );
    return [$manager, $fonts];
  }

  /**
   * Builds a settings repository returning the given role values.
   *
   * @param array<string, string> $values
   *   The configured font definition id, keyed by role.
   *
   * @return \Drupal\neo_settings\SettingsRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject
   *   The mocked settings repository.
   */
  private function mockSettingsRepository(array $values): MockObject {
    $settings = $this->createMock(SettingsInterface::class);
    $settings->method('getValue')->willReturnCallback(
      static function (string $key) use ($values): ?string {
        return $values[$key] ?? NULL;
      }
    );
    $repository = $this->createMock(SettingsRepositoryInterface::class);
    $repository->method('getActive')->willReturn($settings);
    return $repository;
  }

  /**
   * Tests that every discovered definition becomes one instance, in order.
   */
  public function testGetFontsReturnsOneInstancePerDefinitionKeyedByPluginId(): void {
    [$manager, $fonts] = $this->mockPluginManager([
      'aleo_sans' => ['id' => 'aleo-sans', 'selector' => 'aleo-sans'],
      'inter' => ['id' => 'inter', 'selector' => 'inter'],
      'zed_mono' => ['id' => 'zed-mono', 'selector' => 'zed-mono'],
    ]);
    $resolver = new FontRoleResolver($manager, $this->mockSettingsRepository([]));

    $result = $resolver->getFonts();

    $this->assertSame(['aleo_sans', 'inter', 'zed_mono'], array_keys($result));
    $this->assertSame($fonts['aleo_sans'], $result['aleo_sans']);
    $this->assertSame($fonts['inter'], $result['inter']);
    $this->assertSame($fonts['zed_mono'], $result['zed_mono']);
  }

  /**
   * Tests that each role resolves to the font the settings name.
   *
   * Settings name a font by its *definition* id, where a plugin id's
   * underscores have become hyphens, while the plugin manager instantiates by
   * plugin id. Getting that split wrong is the bug this seam exists to make
   * unrepeatable, so every fixture here has an underscore in its plugin id.
   */
  public function testGetRoleMapPairsEachRoleWithTheConfiguredFont(): void {
    [$manager, $fonts] = $this->mockPluginManager([
      'aleo_sans' => ['id' => 'aleo-sans', 'selector' => 'aleo-sans'],
      'inter' => ['id' => 'inter', 'selector' => 'inter'],
      'zed_mono' => ['id' => 'zed-mono', 'selector' => 'zed-mono'],
    ]);
    $repository = $this->mockSettingsRepository([
      'primary' => 'inter',
      'secondary' => 'aleo-sans',
      'accent' => 'zed-mono',
      'heading' => 'aleo-sans',
      'ui' => 'inter',
    ]);
    $resolver = new FontRoleResolver($manager, $repository);

    $result = $resolver->getRoleMap();

    $this->assertSame(
      ['primary', 'secondary', 'accent', 'heading', 'ui'],
      array_keys($result)
    );
    $this->assertSame($fonts['inter'], $result['primary']);
    $this->assertSame($fonts['aleo_sans'], $result['secondary']);
    $this->assertSame($fonts['zed_mono'], $result['accent']);
    $this->assertSame($fonts['aleo_sans'], $result['heading']);
    $this->assertSame($fonts['inter'], $result['ui']);
  }

  /**
   * Tests that an unresolvable role is absent rather than present and empty.
   *
   * Two ways a role fails to resolve: the configured font was removed, or the
   * setting names a plugin id where a definition id belongs. Both are left out
   * silently, exactly as before this seam existed.
   */
  public function testGetRoleMapOmitsRolesThatMatchNoFont(): void {
    [$manager, $fonts] = $this->mockPluginManager([
      'aleo_sans' => ['id' => 'aleo-sans', 'selector' => 'aleo-sans'],
      'inter' => ['id' => 'inter', 'selector' => 'inter'],
    ]);
    $repository = $this->mockSettingsRepository([
      'primary' => 'inter',
      // A font that was declared once and has since been removed.
      'secondary' => 'retired-serif',
      // The plugin id, where the definition id is what settings store.
      'accent' => 'aleo_sans',
      'heading' => 'aleo-sans',
      // Never configured at all.
      'ui' => '',
    ]);
    $resolver = new FontRoleResolver($manager, $repository);

    $result = $resolver->getRoleMap();

    $this->assertSame(['primary', 'heading'], array_keys($result));
    $this->assertArrayNotHasKey('secondary', $result);
    $this->assertArrayNotHasKey('accent', $result);
    $this->assertArrayNotHasKey('ui', $result);
    $this->assertSame($fonts['inter'], $result['primary']);
    $this->assertSame($fonts['aleo_sans'], $result['heading']);
  }

  /**
   * Tests that constructing the resolver does no settings work.
   *
   * Both build subscribers used to snapshot the active settings in their own
   * constructors, so building either service did config I/O and could answer a
   * build event with a stale snapshot. Settings are read at call time here.
   */
  public function testSettingsAreReadAtCallTimeAndNotOnConstruction(): void {
    [$manager] = $this->mockPluginManager([
      'inter' => ['id' => 'inter', 'selector' => 'inter'],
    ]);
    $settings = $this->createMock(SettingsInterface::class);
    $settings->method('getValue')->willReturn('inter');
    // A recorder rather than a counter, so that every read is evidence a test
    // can point at.
    /** @var \ArrayObject<int, string> $reads */
    $reads = new \ArrayObject();
    $repository = $this->createMock(SettingsRepositoryInterface::class);
    $repository->method('getActive')->willReturnCallback(
      static function () use ($reads, $settings): SettingsInterface {
        $reads[] = 'getActive';
        return $settings;
      }
    );

    $resolver = new FontRoleResolver($manager, $repository);
    $this->assertCount(0, $reads, 'Constructing the resolver reads no settings.');

    $resolver->getFonts();
    $this->assertCount(0, $reads, 'Listing the fonts reads no settings.');

    $resolver->getRoleMap();
    $this->assertCount(1, $reads, 'The role map reads the active settings.');
  }

}
