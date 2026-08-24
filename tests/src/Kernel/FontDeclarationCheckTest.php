<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_font\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_font\FontDeclarationProblem;
use Drupal\neo_font\FontPluginManagerInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the font declaration check the prepare-time refusal runs.
 *
 * The check is the other half of the trade this plan makes. Discovery stopped
 * refusing a declaration it cannot process — it logs it and drops the font, so
 * a mistyped face path costs a font rather than every page on the site — and
 * this is where the same finding becomes loud again, at the console of the
 * author who caused it.
 *
 * It is a kernel test because the check reads the real declaration files over
 * the real extension list. That is not an optimisation detail: a refusal
 * removes the definition from the cached set, so a cache-served set cannot
 * report what is missing from it, and only a real discovery over real
 * extensions can prove the difference.
 *
 * Both fixture modules are enabled together here on purpose. Every test written
 * before this plan assumes each font `neo_font_test` declares survives
 * processing, and none of them may see the broken fixture; this one asserts the
 * opposite pairing — that a file full of refused declarations costs the set
 * exactly those declarations and nothing else.
 *
 * @see \Drupal\Tests\neo_font\Unit\NeoBuildDeclarationEventSubscriberTest
 */
#[Group('neo_font')]
final class FontDeclarationCheckTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * Wider than `neo_font.info.yml` used to be: the settings repository inherits
   * from `neo_settings`, and three build subscribers now name a `neo_build`
   * class from a static method the container calls while compiling.
   */
  protected static $modules = [
    'system',
    'neo_build',
    'neo_settings',
    'neo_font',
    'neo_font_test',
  ];

  /**
   * The broken-declaration fixture module.
   *
   * Installed per test rather than in the module list, because one criterion is
   * about what the check says on a site whose declarations are all sound, and
   * that is only checkable while it is absent.
   */
  private const BROKEN_FIXTURE = 'neo_font_test_broken';

  /**
   * Tests that it reports every problem across every declared font.
   *
   * One refusal per condition the module can find, plus a second face on the
   * last of them, because stopping at the first problem is exactly the failure
   * this criterion exists to rule out — a theme that got two face paths wrong
   * learns both from one pass.
   */
  public function testReportsEveryProblemAcrossEveryDeclaredFont(): void {
    $this->installBrokenFixture();

    $problems = $this->check();

    $fonts = array_map(static fn (FontDeclarationProblem $problem): string => $problem->font, $problems);
    sort($fonts);

    $this->assertSame([
      'broken_face_without_source',
      'broken_missing_source',
      'broken_missing_source',
      'broken_no_faces',
      'broken_no_family',
      'broken_no_type',
      'broken_provider',
      'broken_unsupported_type',
      'heading',
    ], $fonts);
  }

  /**
   * Tests that it reads the declaration files, not the cached definitions.
   *
   * The reason this is not an optimisation detail. A refused declaration is
   * dropped from the set discovery returns, so by the time anything has asked
   * for a definition the evidence of the refusal is gone from the cache — and a
   * check reading the cache would report a clean site while the author's font
   * is missing from every page. The check therefore re-reads the files, and the
   * assertion is the pair: absent from the definitions, still reported by the
   * check.
   */
  public function testReadsTheDeclarationFilesRatherThanTheCachedDefinitions(): void {
    $this->installBrokenFixture();
    $manager = $this->manager();

    // A cold check, before anything at all has asked for a definition.
    $cold = $this->fontsIn($manager->checkDeclarations());
    $this->assertNotEmpty($cold, 'The broken fixture gives the check something to find.');

    // Warm the discovery cache. Every refusal is dropped on the way through,
    // which is precisely what a cache-served set can no longer report.
    $definitions = $manager->getDefinitions();
    foreach (array_unique($cold) as $font) {
      $this->assertArrayNotHasKey($font, $definitions, sprintf('The refused font %s was dropped from the definition set.', $font));
    }

    // The same check over a warm cache still finds every one of them.
    $this->assertSame($cold, $this->fontsIn($manager->checkDeclarations()));
  }

  /**
   * Tests that it reports nothing when every declared font is sound.
   *
   * The state every real site is in, and the one the whole trade depends on:
   * `neo_font`'s own five declarations and the clean fixture's three are all
   * sound, so prepare has to stay silent over them. A check that reported
   * anything here would fail every build on every site.
   */
  public function testReportsNothingWhenEveryDeclaredFontIsSound(): void {
    // The broken fixture is deliberately not installed.
    $this->assertSame([], $this->check());
  }

  /**
   * The font keys a list of problems names, sorted.
   *
   * @param list<\Drupal\neo_font\FontDeclarationProblem> $problems
   *   The problems to read.
   *
   * @return list<string>
   *   The font keys, sorted, one entry per problem.
   */
  private function fontsIn(array $problems): array {
    $fonts = array_map(static fn (FontDeclarationProblem $problem): string => $problem->font, $problems);
    sort($fonts);
    return $fonts;
  }

  /**
   * Tests that the other fonts discover normally while the fixture is enabled.
   *
   * The runtime half of the trade, proved on the same file the check refuses.
   * Until this plan, one wrong `src:` threw out of the whole discovery pass, so
   * a theme's typo cost the site every font every extension had declared — on
   * every page render, because the Google font link resolves the definition set
   * inside `hook_page_attachments`. Eight refused declarations now cost exactly
   * eight fonts.
   */
  public function testDiscoversTheOtherFontsNormallyWhileTheBrokenFixtureIsEnabled(): void {
    $this->installBrokenFixture();

    $definitions = $this->manager()->getDefinitions();

    // Every sound declaration is still there: the module's own five and the
    // clean fixture's three.
    foreach (['sans', 'serif', 'mono', 'cursive', 'inter', 'fixture_generic', 'fixture_local', 'fixture_google'] as $key) {
      $this->assertArrayHasKey($key, $definitions, sprintf('The sound font %s survives a pass over broken declarations.', $key));
    }

    // And nothing the broken fixture declared does.
    $broken = array_values(array_filter(
      array_keys($definitions),
      static fn ($key): bool => str_starts_with((string) $key, 'broken_'),
    ));
    $this->assertSame([], $broken);
    $this->assertArrayNotHasKey('heading', $definitions);

    // The pass finished, rather than surviving as far as the first refusal:
    // generic resolution still replaced a named generic with its stack, and the
    // local branch still rewrote a sound font's face source.
    $this->assertSame($definitions['fixture_generic']['generic'], $definitions['fixture_google']['generic']);
    $this->assertIsArray($definitions['fixture_local']['faces']);
    $this->assertStringEndsWith(
      'neo_font_test/fonts/fixture-local.woff2',
      (string) $definitions['fixture_local']['faces'][0]['src'],
    );
  }

  /**
   * Installs the broken-declaration fixture module.
   *
   * A declaration file only reaches the check once its extension is installed,
   * because the check reads the same module and theme directory lists discovery
   * does.
   */
  private function installBrokenFixture(): void {
    $this->enableModules([self::BROKEN_FIXTURE]);
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
   * Runs the font declaration check.
   *
   * @return list<\Drupal\neo_font\FontDeclarationProblem>
   *   Every problem the check found, in the order it found them.
   */
  private function check(): array {
    return $this->manager()->checkDeclarations();
  }

}
