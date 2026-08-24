<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_font\Kernel;

use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_font\FontPluginManager;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the local branch of definition processing.
 *
 * Local-font processing resolves the extension a font was declared by, refuses
 * four shapes, and rewrites every font face's source into a path a browser can
 * fetch. It is a kernel test rather than a unit test for two reasons: the
 * rewrite calls `base_path()`, which is bootstrap global state, and provider
 * resolution asks the real module and theme handlers whether an extension
 * exists. Both are real here and would be stubs in a unit test — and a stubbed
 * `base_path()` would be testing the stub.
 *
 * Definition processing is reached by calling it directly on the manager from
 * the container, with hand-built definitions whose provider names one of the
 * fixture extensions. That is deliberate rather than convenient. The refusals
 * cannot be declared in the fixtures, because a single bad declaration throws
 * out of the whole discovery pass and would take every other kernel test in
 * this module with it; and calling directly is the only way to reach the branch
 * where the provider is neither a module nor a theme, since a definition that
 * arrived through discovery always has a real one.
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
   * Tests that it refuses a provider that is neither module nor theme.
   */
  public function testRefusesProviderThatIsNeitherInstalledModuleNorInstalledTheme(): void {
    // An extension name belonging to nothing at all.
    $definition = self::localFont('neo_font_no_such_extension', [self::face(self::MODULE_FACE)]);
    $this->assertRefuses(
      $definition,
      'could not determine provider location',
      'A provider naming no extension at all is refused.'
    );

    // And a provider that is on disk but not installed. The handlers the code
    // asks answer for *installed* extensions, so the fixture theme is an
    // unresolvable provider right up until it is installed — which is the same
    // refusal, reached by the shape a site is far likelier to hit.
    $uninstalled = self::localFont(self::FIXTURE_THEME, [self::face(self::THEME_FACE)]);
    $this->assertRefuses(
      $uninstalled,
      'could not determine provider location',
      'A provider that is present on disk but not installed is refused.'
    );

    // The same declaration resolves once the theme is installed, so the refusal
    // above is about installation and not about the declaration.
    $this->installFixtureTheme();
    $installed = self::localFont(self::FIXTURE_THEME, [self::face(self::THEME_FACE)]);
    $this->manager()->processDefinition($installed, self::PLUGIN_ID);
    $this->assertStringStartsWith('/', $installed['faces'][0]['src']);
  }

  /**
   * Tests that it refuses a local font that declares no faces.
   */
  public function testRefusesLocalFontThatDeclaresNoFaces(): void {
    // A local font is nothing but its faces — there is no source to rewrite and
    // no `@font-face` rule to emit — so declaring none is refused rather than
    // quietly producing a font that can never load.
    $this->assertRefuses(
      self::localFont(self::FIXTURE_MODULE, NULL),
      'definition "local.faces" is required',
      'A local font declaring no faces key at all is refused.'
    );

    $this->assertRefuses(
      self::localFont(self::FIXTURE_MODULE, []),
      'definition "local.faces" is required',
      'A local font declaring an empty faces list is refused.'
    );
  }

  /**
   * Tests that it refuses a face that declares no source.
   */
  public function testRefusesFaceThatDeclaresNoSource(): void {
    $this->assertRefuses(
      self::localFont(self::FIXTURE_MODULE, [self::face(NULL)]),
      'definition "faces.*.src" is required',
      'A face declaring no src key at all is refused.'
    );

    $this->assertRefuses(
      self::localFont(self::FIXTURE_MODULE, [self::face('')]),
      'definition "faces.*.src" is required',
      'A face declaring an empty src is refused.'
    );

    // Every face is checked, not just the first: a font whose second face lost
    // its source is refused as surely as one whose only face did.
    $this->assertRefuses(
      self::localFont(self::FIXTURE_MODULE, [self::face(self::MODULE_FACE), self::face(NULL)]),
      'definition "faces.*.src" is required',
      'A later face declaring no src is refused, not skipped.'
    );
  }

  /**
   * Tests that it refuses a missing source, naming the path it looked for.
   */
  public function testRefusesFaceWhoseSourceDoesNotExistOnDiskNamingThePath(): void {
    $missing = 'fonts/fixture-missing.woff2';
    $path = $this->modulePath(self::FIXTURE_MODULE);
    $this->assertFileDoesNotExist(
      $this->root . '/' . $path . '/' . $missing,
      'The face this test declares points at nothing, which is the whole subject.'
    );

    $definition = self::localFont(self::FIXTURE_MODULE, [self::face($missing)]);

    try {
      $this->manager()->processDefinition($definition, self::PLUGIN_ID);
      $this->fail('A face whose source is not on disk is refused.');
    }
    catch (PluginException $e) {
      $message = $e->getMessage();
      $this->assertStringContainsString('references a font file that does not exist', $message);
      $this->assertStringContainsString(self::PLUGIN_ID, $message, 'The refusal names the font it refused.');

      // This is the one a theme author actually trips, so the message has to
      // carry the resolved path rather than the fragment they wrote: the
      // fragment is already in front of them, and the resolved path is what
      // tells them which directory the module went looking in.
      $this->assertStringContainsString($path . '/' . $missing, $message, 'The refusal names the resolved path it looked for.');

      // Resolved against the Drupal root, not the filesystem root the check
      // itself used and not the base path the rewrite would have added.
      $this->assertStringNotContainsString($this->root, $message, 'The named path is relative to the Drupal root.');
    }
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
   * Asserts that processing a definition is refused, and how.
   *
   * The refusals are `PluginException`s thrown out of discovery, so what a
   * site sees is the message — asserting only the exception class would pass
   * for whichever refusal happened to fire first.
   *
   * @param array<string, mixed> $definition
   *   The definition to process. Passed by value: it is refused, so nothing
   *   the call would have written to it is of interest.
   * @param string $expected
   *   A fragment the refusal's message must contain.
   * @param string $message
   *   The assertion message.
   */
  private function assertRefuses(array $definition, string $expected, string $message): void {
    try {
      $this->manager()->processDefinition($definition, self::PLUGIN_ID);
      $this->fail($message);
    }
    catch (PluginException $e) {
      $this->assertStringContainsString($expected, $e->getMessage(), $message);
      $this->assertStringContainsString(self::PLUGIN_ID, $e->getMessage(), 'The refusal names the font it refused.');
    }
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
