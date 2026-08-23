<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_font\Unit;

use Drupal\neo_build\Event\NeoBuildInlineEvent;
use Drupal\neo_build\Scope;
use Drupal\neo_font\EventSubscriber\NeoBuildInlineEventSubscriber;
use Drupal\neo_font\FontRoleResolverInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the inline CSS the inline build subscriber emits.
 *
 * The subscriber is now a consumer of the role resolver and nothing else. The
 * assertions here are the event's own CSS data, so "the same `font-{selector}`
 * utilities, the same `--font-{role}-family` variables and the same
 * `@font-face` rules come out the other side" is checkable rather than taken
 * on trust.
 */
#[Group('neo_font')]
final class NeoBuildInlineEventSubscriberTest extends UnitTestCase {

  use FontRoleResolverMockTrait;

  /**
   * Two font faces, shaped as `FontInterface::getFontFaces()` returns them.
   */
  private const INTER_FACES = [
    'Inter-400-normal-swap-latin' => [
      'font-family' => "'Inter'",
      'src' => "url('/fonts/Inter/Inter-latin.woff2') format('woff2')",
      'font-weight' => '400',
      'font-style' => 'normal',
      'font-display' => 'swap',
    ],
    'Inter-400-italic-swap-latin' => [
      'font-family' => "'Inter'",
      'src' => "url('/fonts/Inter/Inter-latin-italic.woff2') format('woff2')",
      'font-weight' => '400',
      'font-style' => 'italic',
      'font-display' => 'swap',
    ],
  ];

  /**
   * One font face for a second font.
   */
  private const ALEO_FACES = [
    'Aleo-700-normal-swap-' => [
      'font-family' => "'Aleo Sans'",
      'src' => "url('/fonts/Aleo/Aleo-bold.woff2') format('woff2')",
      'font-weight' => '700',
      'font-style' => 'normal',
      'font-display' => 'swap',
    ],
  ];

  /**
   * Runs the subscriber over a freshly built inline event.
   *
   * @param \Drupal\neo_font\FontRoleResolverInterface $resolver
   *   The resolver the subscriber consumes.
   *
   * @return \Drupal\neo_build\Event\NeoBuildInlineEvent
   *   The event the subscriber has answered.
   */
  private function handle(FontRoleResolverInterface $resolver): NeoBuildInlineEvent {
    $event = new NeoBuildInlineEvent(Scope::Front, FALSE);
    (new NeoBuildInlineEventSubscriber($resolver))->onInlineBuild($event);
    return $event;
  }

  /**
   * One font face for a font deliberately left serving no role.
   */
  private const ZED_FACES = [
    'Zed Mono-400-normal-swap-' => [
      'font-family' => "'Zed Mono'",
      'src' => "url('/fonts/Zed/ZedMono-regular.woff2') format('woff2')",
      'font-weight' => '400',
      'font-style' => 'normal',
      'font-display' => 'swap',
    ],
  ];

  /**
   * Builds the standard three-font fixture.
   *
   * Every plugin id differs from its selector, because the two are different
   * things and only the selector reaches CSS. One font is left out of every
   * role map a test passes, so that "every font" and "every role" cannot be
   * satisfied by the same loop.
   *
   * @param array<string, string> $roleMap
   *   The plugin id serving each role, keyed by role.
   *
   * @return \Drupal\neo_font\FontRoleResolverInterface
   *   The mocked resolver.
   */
  private function threeFonts(array $roleMap): FontRoleResolverInterface {
    return $this->mockRoleResolver([
      'inter_sans' => [
        'selector' => 'inter',
        'property' => "'Inter', sans-serif",
        'faces' => self::INTER_FACES,
      ],
      'aleo_sans' => [
        'selector' => 'aleo',
        'property' => "'Aleo Sans', serif",
        'faces' => self::ALEO_FACES,
      ],
      'zed_mono' => [
        'selector' => 'zed-mono',
        'property' => "'Zed Mono', monospace",
        'faces' => self::ZED_FACES,
      ],
    ], $roleMap);
  }

  /**
   * Tests that each font declares its family under its own selector class.
   */
  public function testEachFontDeclaresItsFamilyUnderItsSelectorClass(): void {
    $data = $this->handle($this->threeFonts([]))->getData();

    $this->assertSame(['font-family' => "'Inter', sans-serif"], $data['.font-inter'] ?? NULL);
    $this->assertSame(['font-family' => "'Aleo Sans', serif"], $data['.font-aleo'] ?? NULL);
    $this->assertSame(['font-family' => "'Zed Mono', monospace"], $data['.font-zed-mono'] ?? NULL);
  }

  /**
   * Tests the role variables and the faces of every font.
   *
   * A font that serves no role still emits its faces — the reason the resolver
   * hands back a list keyed by plugin id rather than a role map alone. The
   * `zed_mono` fixture serves no role here and its face must still be there.
   */
  public function testEveryRoleSetsItsVariableAndEveryFaceIsEmitted(): void {
    $event = $this->handle($this->threeFonts(['primary' => 'inter_sans', 'heading' => 'aleo_sans']));
    $data = $event->getData();

    $this->assertSame([
      '--font-primary-family' => "'Inter', sans-serif",
      '--font-heading-family' => "'Aleo Sans', serif",
    ], $data[':root'] ?? NULL);

    // One entry per face of every font — including the role-less one — keyed
    // by the face's src as before.
    $expected = [];
    foreach (array_merge(self::INTER_FACES, self::ALEO_FACES, self::ZED_FACES) as $face) {
      $expected[$face['src']] = $face;
    }
    $this->assertSame($expected, $data['@font-face'] ?? NULL);

    $this->assertContains('config:neo_font.settings', $event->getCacheTags());
  }

  /**
   * Tests that an unresolved role sets no variable.
   */
  public function testAnUnresolvedRoleSetsNoVariable(): void {
    $data = $this->handle($this->threeFonts(['primary' => 'inter_sans']))->getData();

    $this->assertSame(['--font-primary-family'], array_keys($data[':root'] ?? []));
  }

  /**
   * Tests that the subscriber consumes the resolver and nothing else.
   */
  public function testTheConstructorTakesTheResolverAloneAndReadsNothing(): void {
    $this->assertConstructorTakesTheResolverAlone(NeoBuildInlineEventSubscriber::class);

    [$resolver, $calls] = $this->recordingRoleResolver();
    $subscriber = new NeoBuildInlineEventSubscriber($resolver);
    $this->assertCount(0, $calls, 'Constructing the subscriber resolves nothing.');

    $subscriber->onInlineBuild(new NeoBuildInlineEvent(Scope::Front, FALSE));
    $this->assertSame(['getFonts', 'getRoleMap'], (array) $calls);
  }

}
