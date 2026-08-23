<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_font\Unit;

use Drupal\neo_build\Event\NeoBuildEvent;
use Drupal\neo_build\NeoBuildCollection;
use Drupal\neo_font\EventSubscriber\NeoBuildEventSubscriber;
use Drupal\neo_font\FontRoleResolverInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the Tailwind theme the build subscriber emits.
 *
 * The subscriber is now a consumer of the role resolver and nothing else: it
 * iterates the font list once for its per-font entries, then the role map for
 * its per-role entries. The assertions here are the emitted payload itself,
 * which is what makes "the same Tailwind theme comes out the other side" a
 * checkable claim rather than a promise.
 */
#[Group('neo_font')]
final class NeoBuildEventSubscriberTest extends UnitTestCase {

  use FontRoleResolverMockTrait;

  /**
   * Builds a collection from scalars, as the preparer does.
   *
   * @return \Drupal\neo_build\NeoBuildCollection
   *   A collection needing no container and no filesystem.
   */
  private function collection(): NeoBuildCollection {
    return new NeoBuildCollection(3000, FALSE, '/app', '/app/web', '/app/web/modules/contrib/neo');
  }

  /**
   * Runs the subscriber over a collection and returns the font family entries.
   *
   * @param \Drupal\neo_font\FontRoleResolverInterface $resolver
   *   The resolver the subscriber consumes.
   *
   * @return array<string, mixed>
   *   The `fontFamily` fragment of the collection's Tailwind theme.
   */
  private function fontFamily(FontRoleResolverInterface $resolver): array {
    $collection = $this->collection();
    (new NeoBuildEventSubscriber($resolver))->onBuild(new NeoBuildEvent($collection));
    $theme = $collection->getTailwindTheme();
    $this->assertArrayHasKey('fontFamily', $theme);
    $this->assertIsArray($theme['fontFamily']);
    return $theme['fontFamily'];
  }

  /**
   * Tests that every font contributes one entry under its own selector.
   *
   * The font stack is split on ', ' exactly as before, so `font-{selector}`
   * resolves to the same list Tailwind was given previously.
   */
  public function testEachFontAddsOneFontFamilyEntryKeyedByItsSelector(): void {
    $resolver = $this->mockRoleResolver(
      [
        'aleo_sans' => ['selector' => 'aleo', 'property' => "'Aleo Sans', sans-serif"],
        'inter' => ['selector' => 'inter', 'property' => "'Inter', sans-serif"],
        'zed_mono' => ['selector' => 'zed-mono', 'property' => "'Zed Mono', monospace"],
      ],
      []
    );

    $this->assertSame([
      'aleo' => ["'Aleo Sans'", 'sans-serif'],
      'inter' => ["'Inter'", 'sans-serif'],
      'zed-mono' => ["'Zed Mono'", 'monospace'],
    ], $this->fontFamily($resolver));
  }

  /**
   * Tests that each resolved role adds its token after the per-font entries.
   *
   * A role token points at the CSS variable the inline subscriber sets, so the
   * two halves of the module agree without either knowing the other.
   */
  public function testEveryResolvedRoleAddsItsTokenAfterThePerFontEntries(): void {
    $resolver = $this->mockRoleResolver(
      [
        'aleo_sans' => ['selector' => 'aleo', 'property' => "'Aleo Sans', sans-serif"],
        'inter' => ['selector' => 'inter', 'property' => "'Inter', sans-serif"],
        'zed_mono' => ['selector' => 'zed-mono', 'property' => "'Zed Mono', monospace"],
      ],
      ['primary' => 'inter', 'heading' => 'aleo_sans', 'ui' => 'zed_mono']
    );

    $fontFamily = $this->fontFamily($resolver);

    // Per-font entries first, per-role entries last.
    $this->assertSame(
      ['aleo', 'inter', 'zed-mono', 'primary', 'heading', 'ui'],
      array_keys($fontFamily)
    );
    $this->assertSame('var(--font-primary-family)', $fontFamily['primary']);
    $this->assertSame('var(--font-heading-family)', $fontFamily['heading']);
    $this->assertSame('var(--font-ui-family)', $fontFamily['ui']);
  }

  /**
   * Tests that an unresolved role contributes no entry at all.
   */
  public function testAnUnresolvedRoleAddsNoToken(): void {
    $resolver = $this->mockRoleResolver(
      ['inter' => ['selector' => 'inter', 'property' => "'Inter', sans-serif"]],
      ['primary' => 'inter']
    );

    $this->assertSame(['inter', 'primary'], array_keys($this->fontFamily($resolver)));
  }

  /**
   * Tests that a role token beats a font selector of the same name.
   *
   * This is the one place emission order is observable, and writing the roles
   * last is what makes the admin setting win — the outcome the setting exists
   * to produce. The collision itself is another candidate's problem; this pins
   * only which side of it the new order lands on.
   */
  public function testRoleTokensWinOverMatchingFontSelectors(): void {
    $resolver = $this->mockRoleResolver(
      [
        'inter' => ['selector' => 'inter', 'property' => "'Inter', sans-serif"],
        'roboto' => ['selector' => 'ui', 'property' => "'Roboto', sans-serif"],
      ],
      ['ui' => 'inter']
    );

    $this->assertSame('var(--font-ui-family)', $this->fontFamily($resolver)['ui']);
  }

  /**
   * Tests that the subscriber consumes the resolver and nothing else.
   *
   * It used to inject the font plugin manager and the settings repository, and
   * to snapshot the active settings in its constructor — so building the
   * service did config I/O and could answer a build event with a stale
   * snapshot.
   */
  public function testTheConstructorTakesTheResolverAloneAndReadsNothing(): void {
    $this->assertConstructorTakesTheResolverAlone(NeoBuildEventSubscriber::class);

    [$resolver, $calls] = $this->recordingRoleResolver();
    $subscriber = new NeoBuildEventSubscriber($resolver);
    $this->assertCount(0, $calls, 'Constructing the subscriber resolves nothing.');

    $subscriber->onBuild(new NeoBuildEvent($this->collection()));
    $this->assertSame(['getFonts', 'getRoleMap'], (array) $calls);
  }

}
