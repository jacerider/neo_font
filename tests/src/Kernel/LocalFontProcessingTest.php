<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_font\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_font\FontPluginManager;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the successful half of the local branch of definition processing.
 *
 * Local-font processing resolves the extension a font was declared by and
 * rewrites every font face's source into a path a browser can fetch. That
 * rewrite is what this class covers, and it is a kernel test rather than a unit
 * test for two reasons: it calls `base_path()`, which is bootstrap global
 * state, and provider resolution asks the real module and theme handlers
 * whether an extension exists. Both are real here and would be stubs in a unit
 * test — and a stubbed `base_path()` would be testing the stub.
 *
 * The branch's four **font declaration refusals** — an unresolvable provider, a
 * font declaring no faces, a face declaring no source, and a source that is not
 * on disk — are *not* here. Every one of them ends before the rewrite, so none
 * of them reaches `base_path()`, and a refusal is now a logged drop rather than
 * a throw: it is observable on a mocked logger with no container at all.
 *
 * @see \Drupal\Tests\neo_font\Unit\FontDeclarationDropTest
 *
 * Definition processing is reached by calling it directly on the manager from
 * the container, with hand-built definitions whose provider names one of the
 * fixture extensions. That is deliberate rather than convenient: it is the only
 * way to hand the branch a declaration the fixtures do not carry.
 *
 * The module list matches @see \Drupal\Tests\neo_font\Kernel\FontDiscoveryTest
 * and is wider than `neo_font.info.yml`'s dependency line for the same reason:
 * the settings repository inherits from `neo_settings`, and both build
 * subscribers name a `neo_build` class from a static method the container calls
 * while compiling. The undeclared `neo_build` dependency is a finding for the
 * backlog, not something these tests work around.
 */
#[Group('neo_font')]
final class LocalFontProcessingTest extends KernelTestBase {

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
   * The font fixture module.
   */
  private const FIXTURE_MODULE = 'neo_font_test';

  /**
   * The font fixture theme.
   */
  private const FIXTURE_THEME = 'neo_font_test_theme';

  /**
   * The face source the fixture module ships a placeholder for.
   */
  private const MODULE_FACE = 'fonts/fixture-local.woff2';

  /**
   * The face source the fixture theme ships a placeholder for.
   */
  private const THEME_FACE = 'fonts/fixture-theme-local.woff2';

  /**
   * The plugin id every hand-built definition is processed under.
   *
   * Not a font role name, and not a font either fixture declares: an id equal
   * to a role name is refused by a guard that runs before the local branch is
   * ever reached, which would make every refusal here pass for the wrong
   * reason.
   */
  private const PLUGIN_ID = 'fixture_hand_built';

  /**
   * Tests that it rewrites a face source beneath the declaring module's path.
   */
  public function testRewritesFaceSourceToDeclaringModulesPathBeneathBasePath(): void {
    $path = $this->modulePath(self::FIXTURE_MODULE);
    $this->assertFileExists(
      $this->root . '/' . $path . '/' . self::MODULE_FACE,
      'The fixture module ships the placeholder its declared face points at.'
    );

    $definition = self::localFont(self::FIXTURE_MODULE, [self::face(self::MODULE_FACE)]);
    $this->manager()->processDefinition($definition, self::PLUGIN_ID);

    $src = $definition['faces'][0]['src'];

    // The declaring extension's path, with the declared source appended, under
    // the site's base path — not the relative source the extension wrote.
    $this->assertSame(base_path() . $path . '/' . self::MODULE_FACE, $src);
    $this->assertNotSame(self::MODULE_FACE, $src, 'The declared source is rewritten, not kept.');
    $this->assertStringStartsWith('/', $src, 'The rewritten source is a root-relative URL a browser can fetch.');
    $this->assertStringContainsString($path, $src, 'The rewrite names the path of the extension that declared the font.');

    // The rewrite touches the source and nothing else on the face.
    $this->assertSame(
      ['style' => 'normal', 'weight' => 400, 'format' => 'woff2'],
      array_diff_key($definition['faces'][0], ['src' => NULL]),
      'Only the source is rewritten; the rest of the face is left as declared.'
    );
  }

  /**
   * Tests that it rewrites a face source beneath the declaring theme's path.
   */
  public function testRewritesFaceSourceToDeclaringThemesPathBeneathBasePath(): void {
    // A theme provider arrives through the theme handler rather than the module
    // handler, and that handler only knows an installed theme — so a theme
    // declaring fonts is a different branch of provider resolution, not the
    // same branch with a different name.
    $this->installFixtureTheme();

    $path = $this->themePath(self::FIXTURE_THEME);
    $this->assertFileExists(
      $this->root . '/' . $path . '/' . self::THEME_FACE,
      'The fixture theme ships the placeholder its declared face points at.'
    );

    $definition = self::localFont(self::FIXTURE_THEME, [self::face(self::THEME_FACE)]);
    $this->manager()->processDefinition($definition, self::PLUGIN_ID);

    $src = $definition['faces'][0]['src'];

    $this->assertSame(base_path() . $path . '/' . self::THEME_FACE, $src);
    $this->assertNotSame(self::THEME_FACE, $src, 'The declared source is rewritten, not kept.');
    $this->assertStringStartsWith('/', $src, 'The rewritten source is a root-relative URL a browser can fetch.');
    $this->assertStringContainsString($path, $src, 'The rewrite names the path of the extension that declared the font.');
  }

  /**
   * Tests that a sound local font is still resolved and rewritten.
   */
  public function testStillResolvesProviderAndRewritesSourceOfSoundLocalFont(): void {
    $manager = $this->manager();

    // A declaration the local branch refuses, processed first on the same
    // manager. It is here because of what it used to cost: the refusal threw,
    // so nothing behind it in the pass was processed at all. Now it is logged
    // and dropped, and the rest of this test is what the font behind it gets.
    $refused = self::localFont('neo_font_no_such_extension', [self::face(self::MODULE_FACE)]);
    $manager->processDefinition($refused, self::PLUGIN_ID);

    // Provider resolution: a module provider, resolved through the module
    // handler, with every face rewritten and not merely the first.
    $path = $this->modulePath(self::FIXTURE_MODULE);
    $sound = self::localFont(self::FIXTURE_MODULE, [
      self::face(self::MODULE_FACE),
      self::face(self::MODULE_FACE),
    ]);
    $manager->processDefinition($sound, self::PLUGIN_ID);

    $this->assertCount(2, $sound['faces']);
    foreach ($sound['faces'] as $delta => $face) {
      $this->assertSame(
        base_path() . $path . '/' . self::MODULE_FACE,
        $face['src'],
        sprintf('Face %d of a sound local font is rewritten against the declaring module\'s path.', $delta)
      );
    }

    // And a theme provider, which arrives through the theme handler instead —
    // a different branch of provider resolution, not the same branch with a
    // different name.
    $this->installFixtureTheme();
    $theme_path = $this->themePath(self::FIXTURE_THEME);
    $themed = self::localFont(self::FIXTURE_THEME, [self::face(self::THEME_FACE)]);
    $this->manager()->processDefinition($themed, self::PLUGIN_ID);

    $this->assertSame(
      base_path() . $theme_path . '/' . self::THEME_FACE,
      $themed['faces'][0]['src'],
      'A sound theme-provided local font is resolved and rewritten too.'
    );
  }

  /**
   * The font plugin manager, as a site gets it.
   *
   * @return \Drupal\neo_font\FontPluginManager
   *   The manager under test.
   */
  private function manager(): FontPluginManager {
    $manager = $this->container->get('plugin.manager.neo_font');
    assert($manager instanceof FontPluginManager);
    return $manager;
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
      'label' => 'Fixture Hand Built',
      'type' => 'local',
      'family' => 'Fixture Hand Built',
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
   * The path of an installed module, relative to the Drupal root.
   *
   * Read through the extension list rather than the module handler the code
   * under test uses, so the assertion is not the production lookup compared
   * with itself.
   *
   * @param string $module
   *   The module name.
   *
   * @return string
   *   The module's path.
   */
  private function modulePath(string $module): string {
    return $this->container->get('extension.list.module')->getPath($module);
  }

  /**
   * The path of a theme, relative to the Drupal root.
   *
   * @param string $theme
   *   The theme name.
   *
   * @return string
   *   The theme's path.
   */
  private function themePath(string $theme): string {
    return $this->container->get('extension.list.theme')->getPath($theme);
  }

  /**
   * Installs the fixture theme.
   *
   * Provider resolution asks `ThemeHandlerInterface::themeExists()`, which
   * knows installed themes only — so a theme provider is unresolvable until the
   * theme is installed, and a test that wants the theme branch has to install
   * it.
   */
  private function installFixtureTheme(): void {
    $this->container->get('theme_installer')->install([self::FIXTURE_THEME]);
  }

}
