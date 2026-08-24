<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_font\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\neo_build\Event\NeoBuildEvent;
use Drupal\neo_build\NeoBuildCollection;
use Drupal\neo_font\EventSubscriber\NeoBuildDeclarationEventSubscriber;
use Drupal\neo_font\EventSubscriber\NeoBuildEventSubscriber;
use Drupal\neo_font\FontDeclarationProblem;
use Drupal\neo_font\FontPluginManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Tests the prepare-time refusal of a font declaration that cannot be built.
 *
 * The half of the trade that faces the author. Discovery logs a bad declaration
 * and drops the font so the page still renders; this subscriber runs the same
 * check over the declaration files at build time and refuses the build, at the
 * console the author is already standing at.
 *
 * A unit test against a mocked plugin manager, because the subject here is what
 * the subscriber does with a list of problems — which severities stop a build,
 * and whether the message names all of them. What produces the list is the
 * check's own subject.
 *
 * @see \Drupal\Tests\neo_font\Kernel\FontDeclarationCheckTest
 */
#[Group('neo_font')]
final class NeoBuildDeclarationEventSubscriberTest extends UnitTestCase {

  use FontRoleResolverMockTrait;

  /**
   * Tests that a refusal fails the build, naming every one it found.
   *
   * Every part of the message is asserted separately, because each answers a
   * different question an author has: whose file, which entry, and what is
   * wrong with it. And every refusal is asserted, not the first: a theme that
   * got two face paths wrong should learn both from one build.
   */
  public function testFailsTheBuildOnRefusalNamingEveryExtensionFontAndReason(): void {
    $subscriber = $this->subscriber([
      FontDeclarationProblem::refusal('acme_theme', 'brand_sans', 'declares no font family, so there is no font stack to build from it.'),
      FontDeclarationProblem::refusal('acme_theme', 'brand_serif', 'has a font face at position %face whose source %src is not on disk: nothing exists at %path.', [
        '%face' => '0',
        '%src' => 'fonts/Brand-Serif.woff2',
        '%path' => '/app/web/themes/acme_theme/fonts/Brand-Serif.woff2',
      ]),
      FontDeclarationProblem::refusal('widget_module', 'widget_mono', 'declares the font type %type, which is not supported. Declare one of: @types.', [
        '%type' => 'bitmap',
        '@types' => 'local, google, generic',
      ]),
    ]);

    try {
      $subscriber->onBuild($this->event());
      $this->fail('A refusal fails the build.');
    }
    catch (\InvalidArgumentException $e) {
      $message = $e->getMessage();
    }

    // Each refusal names the extension whose file declared it.
    $this->assertStringContainsString('acme_theme', $message);
    $this->assertStringContainsString('widget_module', $message);

    // Each names the font key as the YAML spells it, underscores and all.
    $this->assertStringContainsString('brand_sans', $message);
    $this->assertStringContainsString('brand_serif', $message);
    $this->assertStringContainsString('widget_mono', $message);

    // And each names its own reason, with its placeholders filled in.
    $this->assertStringContainsString('declares no font family', $message);
    $this->assertStringContainsString('/app/web/themes/acme_theme/fonts/Brand-Serif.woff2', $message);
    $this->assertStringContainsString('bitmap', $message);
  }

  /**
   * Tests that a report alone lets the build proceed.
   *
   * The other side of the severity split, and the reason the split exists at
   * all. A report is a problem the font survives — it is built correctly and
   * only the author's expectation is wrong — so it is already logged at
   * discovery and has no business failing a build. Nothing is surfaced through
   * the prepare result either: that is `neo_build`'s, it carries no severity by
   * design, and widening the build-event surface is out of scope for every plan
   * that touches it.
   */
  public function testLetsTheBuildProceedWhenTheOnlyProblemsAreReports(): void {
    $subscriber = $this->subscriber([
      FontDeclarationProblem::report('acme_theme', 'brand_sans', 'declares the selector %selector, which is also the name of the %role font role.', [
        '%selector' => 'heading',
        '%role' => 'heading',
      ]),
      FontDeclarationProblem::report('widget_module', 'widget_mono', 'declares the selector %selector, which is also the name of the %role font role.', [
        '%selector' => 'ui',
        '%role' => 'ui',
      ]),
    ]);

    $event = $this->event();
    $subscriber->onBuild($event);

    // The build proceeds, and the subscriber leaves the collection exactly as
    // it found it — emitting into it belongs to the two subscribers that do.
    $this->assertSame(
      $this->event()->getCollection()->getTailwindTheme(),
      $event->getCollection()->getTailwindTheme(),
    );
  }

  /**
   * Tests that it is a separate class, refusing ahead of the ones that emit.
   *
   * Two decisions, and each is load-bearing. The emitting subscribers inject
   * the role resolver and nothing else — the `neo-font-role-resolver` plan's
   * decision — so a check needing the plugin manager had to become its own
   * class rather than a third job for one of them. And a refusal has to happen
   * before anything a refused declaration could have contributed reaches the
   * collection, which is what the priority buys. Both are asserted through a
   * real dispatcher carrying both subscribers, because "runs first" is a
   * property of the pair rather than of either file's frontmatter.
   */
  public function testItRefusesAheadOfTheSubscribersThatEmit(): void {
    $dispatcher = new EventDispatcher();
    $dispatcher->addSubscriber($this->subscriber([
      FontDeclarationProblem::refusal('acme_theme', 'brand_sans', 'declares no font family, so there is no font stack to build from it.'),
    ]));
    $dispatcher->addSubscriber(new NeoBuildEventSubscriber($this->mockRoleResolver(
      ['brand_serif' => ['selector' => 'brand-serif', 'property' => "'Brand Serif', serif"]],
      ['heading' => 'brand_serif'],
    )));

    $event = $this->event();
    try {
      $dispatcher->dispatch($event, NeoBuildEvent::EVENT_NAME);
      $this->fail('A refusal fails the build.');
    }
    catch (\InvalidArgumentException) {
      // The refusal is the subject of another test; what matters here is when.
    }

    // The emitting subscriber never ran, so nothing reached the collection.
    $this->assertSame(
      $this->event()->getCollection()->getTailwindTheme(),
      $event->getCollection()->getTailwindTheme(),
    );

    // And it is its own class, holding the plugin manager and nothing else.
    $parameters = (new \ReflectionClass(NeoBuildDeclarationEventSubscriber::class))->getConstructor()?->getParameters() ?? [];
    $this->assertCount(1, $parameters);
    $this->assertSame(FontPluginManagerInterface::class, (string) $parameters[0]->getType());
  }

  /**
   * Builds the subscriber over a manager answering with a fixed problem list.
   *
   * @param list<\Drupal\neo_font\FontDeclarationProblem> $problems
   *   What the font declaration check finds.
   *
   * @return \Drupal\neo_font\EventSubscriber\NeoBuildDeclarationEventSubscriber
   *   The subscriber under test.
   */
  private function subscriber(array $problems): NeoBuildDeclarationEventSubscriber {
    $manager = $this->createMock(FontPluginManagerInterface::class);
    $manager->method('checkDeclarations')->willReturn($problems);
    return new NeoBuildDeclarationEventSubscriber($manager);
  }

  /**
   * A build event over a collection needing no container and no filesystem.
   *
   * @return \Drupal\neo_build\Event\NeoBuildEvent
   *   The event the subscriber is handed.
   */
  private function event(): NeoBuildEvent {
    return new NeoBuildEvent(new NeoBuildCollection(3000, FALSE, '/app', '/app/web', '/app/web/modules/contrib/neo'));
  }

}
