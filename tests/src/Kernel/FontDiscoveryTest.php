<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_font\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_font\FontPluginManagerInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests what font discovery produces over the module's fixture extensions.
 *
 * Discovery is about what *extensions* declared, so none of it is reachable
 * from an object a test constructs: the manager builds its own YAML discovery
 * over the real module and theme directory lists. These tests run the real
 * manager out of the container against `neo_font_test` and
 * `neo_font_test_theme`, the two fixture extensions this module ships for
 * exactly this purpose.
 *
 * The module list is wider than `neo_font.info.yml`'s dependency line, and has
 * to be: the settings repository service inherits from `neo_settings`, and both
 * build subscribers name a `neo_build` class from a static method the container
 * calls while compiling. The missing `neo_build` dependency is a finding for
 * the backlog, not something these tests work around.
 */
#[Group('neo_font')]
final class FontDiscoveryTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'neo_build',
    'neo_settings',
    'neo_font',
    'neo_font_test',
  ];

  /**
   * The font fixture theme.
   */
  private const FIXTURE_THEME = 'neo_font_test_theme';

  /**
   * The font fixture module.
   */
  private const FIXTURE_MODULE = 'neo_font_test';

  /**
   * Tests that it discovers every font a fixture module declares.
   */
  public function testDiscoversEveryFontDeclaredByTheFixtureModule(): void {
    $this->assertSame(
      ['fixture_generic', 'fixture_google', 'fixture_local'],
      $this->declaredBy(self::FIXTURE_MODULE),
    );
  }

  /**
   * Tests that it discovers every font a fixture theme declares.
   */
  public function testDiscoversEveryFontDeclaredByTheFixtureTheme(): void {
    $this->installFixtureTheme();

    $this->assertSame(
      ['fixture_theme_local'],
      $this->declaredBy(self::FIXTURE_THEME),
    );
  }

  /**
   * Tests that it replaces a named generic with that generic's fallback stack.
   */
  public function testReplacesNamedGenericWithThatGenericsFallbackStack(): void {
    // The declaration names another definition by its discovery key. The key
    // is the raw YAML key, not the derived id, which is what the resolution
    // loop looks up.
    $this->assertSame('fixture_generic', $this->declaredGeneric('fixture_google'));

    $definitions = $this->manager()->getDefinitions();

    // What a consumer gets is the stack, never the name it was declared under.
    $this->assertSame(
      $this->declaredGeneric('fixture_generic'),
      $definitions['fixture_google']['generic'],
    );
  }

  /**
   * Tests that it leaves a generic font's own fallback stack untouched.
   */
  public function testLeavesGenericFontsOwnFallbackStackUntouched(): void {
    $definitions = $this->manager()->getDefinitions();

    // Byte for byte what the fixture declared: the resolution loop skips a
    // definition whose own type is generic, so the one place a raw stack is
    // written is the one place discovery must not rewrite.
    $this->assertSame(
      $this->declaredGeneric('fixture_generic'),
      $definitions['fixture_generic']['generic'],
    );
  }

  /**
   * Tests that it returns definitions in natural label order.
   */
  public function testReturnsDefinitionsInNaturalLabelOrder(): void {
    $this->installFixtureTheme();

    $labels = $this->fixtureLabelsInReturnedOrder();

    // Not the order either fixture declared them in, and not the order a plain
    // string comparison would produce: `Fixture Alpha 2` before
    // `Fixture Alpha 10` is the pair only natural comparison gets right.
    $this->assertSame([
      'fixture_local' => 'Fixture Alpha 1',
      'fixture_generic' => 'Fixture Alpha 2',
      'fixture_theme_local' => 'Fixture Alpha 3',
      'fixture_google' => 'Fixture Alpha 10',
    ], $labels);

    $byPlainComparison = $labels;
    uasort($byPlainComparison, 'strcasecmp');
    $this->assertNotSame(
      array_keys($labels),
      array_keys($byPlainComparison),
      'The fixture labels are chosen so a plain string comparison sorts them differently; if this passes, the sort is no longer being proved.'
    );
  }

  /**
   * The font plugin manager, as a site gets it.
   *
   * @return \Drupal\neo_font\FontPluginManagerInterface
   *   The manager under test.
   */
  private function manager(): FontPluginManagerInterface {
    $manager = $this->container->get('plugin.manager.neo_font');
    assert($manager instanceof FontPluginManagerInterface);
    return $manager;
  }

  /**
   * The fixture fonts' labels, in the order discovery handed them back.
   *
   * Filtered down to the two fixture extensions, which preserves their relative
   * order within the full set: the module's own fonts sort in among them, and
   * pinning their positions too would make this test fail every time `neo_font`
   * adds or renames a font of its own.
   *
   * @return array<string, string>
   *   The labels, keyed by discovery key, in the returned order.
   */
  private function fixtureLabelsInReturnedOrder(): array {
    $labels = [];
    foreach ($this->manager()->getDefinitions() as $key => $definition) {
      if (in_array($definition['provider'] ?? NULL, [self::FIXTURE_MODULE, self::FIXTURE_THEME], TRUE)) {
        $labels[(string) $key] = (string) $definition['label'];
      }
    }
    return $labels;
  }

  /**
   * The `generic` value a fixture font declares, read from the fixture's YAML.
   *
   * Read off disk rather than restated as a constant on purpose: the point of
   * the generic-resolution criteria is the difference between what an extension
   * wrote and what a consumer is handed, and a test that hard-codes both halves
   * cannot tell them apart.
   *
   * @param string $key
   *   The fixture module's discovery key for the font.
   *
   * @return string
   *   The raw declared generic.
   */
  private function declaredGeneric(string $key): string {
    $path = $this->container->get('extension.list.module')->getPath(self::FIXTURE_MODULE);
    $declared = Yaml::decode((string) file_get_contents($this->root . '/' . $path . '/' . self::FIXTURE_MODULE . '.neo.font.yml'));
    $this->assertArrayHasKey($key, $declared, sprintf('The fixture module declares the font %s.', $key));
    return (string) $declared[$key]['generic'];
  }

  /**
   * Installs the fixture theme.
   *
   * A theme only reaches discovery once it is installed: the manager builds its
   * YAML discovery over `ThemeHandlerInterface::getThemeDirectories()`, which
   * lists installed themes, and `providerExists()` asks the same handler. So a
   * test that wants the theme's fonts has to install it, and one that does not
   * gets a module-only definition set.
   */
  private function installFixtureTheme(): void {
    $this->container->get('theme_installer')->install([self::FIXTURE_THEME]);
  }

  /**
   * The plugin ids discovery found for one provider, alphabetically.
   *
   * Sorted, because the order definitions come back in is criterion 5's
   * subject and asserting it here would couple every other test to it.
   *
   * @param string $provider
   *   The extension the definitions must have come from.
   *
   * @return list<string>
   *   The discovered plugin ids provided by that extension.
   */
  private function declaredBy(string $provider): array {
    $ids = [];
    foreach ($this->manager()->getDefinitions() as $key => $definition) {
      if (($definition['provider'] ?? NULL) === $provider) {
        $ids[] = (string) $key;
      }
    }
    sort($ids);
    return $ids;
  }

}
