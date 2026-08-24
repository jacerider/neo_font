<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_font\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_font\FontPluginManagerInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the head links the module attaches when a Google font is discovered.
 *
 * This is the attachment half of the Google font link, and the module's only
 * page-render behaviour. The other half — the stylesheet URL the manager
 * composes — is a unit test, because the manager serves its definitions from a
 * cache backend a test can stub.
 *
 * @see \Drupal\Tests\neo_font\Unit\GoogleFontUrlTest
 *
 * **Why this needs a container.** The attachment is `hook_page_attachments()`
 * in `neo_font.module`: a procedural function that resolves the plugin manager
 * out of the container itself. There is no object to construct and no
 * dependency to inject, so it is exercised where the container is real and the
 * fonts come from real extensions — the fixture module declares the Google font
 * this needs, and `neo_font` itself declares none, which is what makes the
 * empty case reachable at all.
 *
 * The hook is invoked exactly the way core invokes it, through
 * `invokeAllWith()` with the attachments array bound by reference, filtered to
 * this module so that `system`'s own implementation is not mistaken for it.
 *
 * The hook is tested as it stands. A later plan may move this array behind a
 * method on the manager; if it does, it inherits these tests.
 *
 * The module list matches @see \Drupal\Tests\neo_font\Kernel\FontDiscoveryTest
 * and is wider than `neo_font.info.yml`'s dependency line: the settings
 * repository inherits from `neo_settings`, and both build subscribers name a
 * `neo_build` class from a static method the container calls while compiling.
 * The undeclared `neo_build` dependency is a finding for the backlog, not
 * something these tests work around.
 */
#[Group('neo_font')]
final class GoogleFontLinkAttachmentTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * The fixture module is deliberately absent: it is the only extension here
   * declaring a Google font, so leaving it out is what gives the empty case a
   * site with none. The test that wants one enables it.
   */
  protected static $modules = [
    'system',
    'neo_build',
    'neo_settings',
    'neo_font',
  ];

  /**
   * The font fixture module, which declares the one Google font in play.
   */
  private const FIXTURE_MODULE = 'neo_font_test';

  /**
   * The hook under test.
   */
  private const HOOK = 'page_attachments';

  /**
   * Tests that it attaches the stylesheet link and both preconnect hints.
   */
  public function testAttachesStylesheetLinkAndBothPreconnectHintsForGoogleFont(): void {
    $this->enableModules([self::FIXTURE_MODULE]);

    // The href is the composed URL, read from the manager rather than restated
    // here: the two halves of this feature have to stay the same string, and a
    // literal would let them drift apart silently.
    $url = $this->manager()->getGoogleUrl();
    $this->assertIsString($url, 'The fixture module declares a Google font, so a URL is composed.');
    $this->assertStringContainsString(
      'family=Fixture+Google:wght@400;700',
      $url,
      'The URL carries the fixture font, so these links are the fixture\'s and not some other extension\'s.'
    );

    $attachments = $this->attachments();

    $this->assertSame(
      ['#attached'],
      array_keys($attachments),
      'The hook sets #attached and nothing else; core refuses any other key.'
    );
    $this->assertSame(
      [
        // The stylesheet itself.
        [
          [
            '#tag' => 'link',
            '#attributes' => [
              'rel' => 'stylesheet',
              'media' => 'all',
              'href' => $url,
            ],
          ],
          'googlefont',
        ],
        // A preconnect to the host the stylesheet is fetched from.
        [
          [
            '#tag' => 'link',
            '#attributes' => [
              'href' => 'https://fonts.googleapis.com',
              'rel' => 'preconnect',
            ],
          ],
          'googleapis',
        ],
        // And one to the host the font files themselves are fetched from,
        // which is a different origin and so is crossorigin.
        [
          [
            '#tag' => 'link',
            '#attributes' => [
              'href' => 'https://fonts.gstatic.com',
              'rel' => 'preconnect',
              'crossorigin' => 'anonymous',
            ],
          ],
          'gstatic',
        ],
      ],
      $attachments['#attached']['html_head'],
      'Three head links, each under its own key: the stylesheet and the two preconnect hints.'
    );
  }

  /**
   * Tests that it attaches nothing when no Google font is discovered.
   */
  public function testAttachesNothingWhenNoGoogleFontIsDiscovered(): void {
    // `neo_font` ships four generics and one local font and no Google font at
    // all, so a site running the module alone is the empty case.
    $this->assertNull(
      $this->manager()->getGoogleUrl(),
      'Without the fixture module, nothing installed declares a Google font.'
    );

    // The hook is registered and did run — otherwise the assertion below would
    // pass on a hook that was never invoked, which is the one way this
    // criterion can be green for the wrong reason.
    $this->assertContains(
      'neo_font',
      $this->modulesImplementingHook(),
      sprintf('neo_font implements hook_%s().', self::HOOK)
    );

    // Nothing at all: not an `#attached` holding an empty `html_head`, and not
    // an `html_head` holding no links. A site with no Google font gets a page
    // render array the module never touched.
    $this->assertSame([], $this->attachments(), 'No Google font, no attachment.');
  }

  /**
   * Runs the module's page attachment hook and returns what it attached.
   *
   * Invoked the way core invokes it — `invokeAllWith()`, with the attachments
   * array bound into the closure by reference — and narrowed to this module so
   * that another module's implementation cannot stand in for it.
   *
   * @return array<string, mixed>
   *   The attachments this module added.
   */
  private function attachments(): array {
    $attachments = [];
    $this->container->get('module_handler')->invokeAllWith(
      self::HOOK,
      function (callable $hook, string $module) use (&$attachments): void {
        if ($module !== 'neo_font') {
          return;
        }
        $hook($attachments);
      }
    );
    return $attachments;
  }

  /**
   * The modules implementing the hook under test.
   *
   * @return list<string>
   *   The implementing module names.
   */
  private function modulesImplementingHook(): array {
    $modules = [];
    $this->container->get('module_handler')->invokeAllWith(
      self::HOOK,
      function (callable $hook, string $module) use (&$modules): void {
        $modules[] = $module;
      }
    );
    return $modules;
  }

  /**
   * The font plugin manager, as the hook resolves it.
   *
   * @return \Drupal\neo_font\FontPluginManagerInterface
   *   The manager the attachment reads its URL from.
   */
  private function manager(): FontPluginManagerInterface {
    $manager = $this->container->get('plugin.manager.neo_font');
    assert($manager instanceof FontPluginManagerInterface);
    return $manager;
  }

}
