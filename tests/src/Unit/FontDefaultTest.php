<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_font\Unit;

use Drupal\neo_font\FontDefault;
use Drupal\neo_font\FontInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the default font plugin class.
 *
 * Everything this class covers reads the plugin definition and nothing else, so
 * every test constructs the plugin directly from a definition array: no
 * container, no database, no discovery. The definitions are written as
 * definition processing leaves them — a resolved `generic`, a derived
 * `selector` — because that is the only shape a plugin is ever constructed
 * over.
 *
 * Three behaviours live here. The **font stack** is the quoted family followed
 * by the generic's fallback stack. **Font faces** are merged on the eight
 * properties that identify a rule and their sources concatenated. And the admin
 * preview is the only place a **font selector** reaches an admin-facing render
 * array.
 *
 * One face behaviour is characterised rather than endorsed: `display` honours a
 * legacy `swap` key as an alias before falling back to `swap` as a value.
 */
#[Group('neo_font')]
final class FontDefaultTest extends UnitTestCase {

  /**
   * The plugin id every font under test is constructed under.
   */
  private const PLUGIN_ID = 'brand_sans';

  /**
   * A face declaration agreeing on every part of the merge key but the family.
   *
   * Written out rather than defaulted into the face helper: a test that varies
   * one part of the key has to be able to see the other four.
   *
   * @var array<string, string|int>
   */
  private const LATIN_400 = [
    'weight' => 400,
    'style' => 'normal',
    'display' => 'swap',
    'unicode' => 'U+0000-00FF',
  ];

  /**
   * The three override properties, which are part of the merge key.
   *
   * @var array<string, string>
   */
  private const OVERRIDES = [
    'ascent-override' => '90%',
    'descent-override' => '22%',
    'line-gap-override' => '0%',
  ];

  /**
   * Tests that the selector is readable through the font interface.
   */
  public function testGetSelectorIsReadableThroughTheInterface(): void {
    $this->assertTrue(
      (new \ReflectionClass(FontInterface::class))->hasMethod('getSelector'),
      'The selector is exposed on FontInterface, not only on FontDefault.'
    );

    $font = new FontDefault([], 'aleo_sans', [
      'id' => 'aleo-sans',
      'label' => 'Aleo Sans',
      'type' => 'local',
      'generic' => 'sans',
      'selector' => 'aleo',
      'family' => 'Aleo Sans',
    ]);

    // Read through the interface, which is the point: no consumer should have
    // to reach into the definition array for the selector.
    $this->assertSame('aleo', $this->readSelector($font));
  }

  /**
   * Tests that the stack is the quoted family then the generic's fallback.
   */
  public function testBuildsFontStackFromQuotedFamilyThenGenericFallbackStack(): void {
    // `generic` already holds a fallback stack by the time a plugin is
    // constructed: generic resolution replaces the named generic with the
    // stack it resolves to while the definition is still being processed.
    $font = self::font([
      'family' => 'Aleo Sans',
      'generic' => 'ui-sans-serif, system-ui, sans-serif',
    ]);

    $this->assertSame(
      "'Aleo Sans', ui-sans-serif, system-ui, sans-serif",
      $font->getPropertyValue(),
      'The stack is the quoted family followed by the generic fallback stack.'
    );

    // Each half is dropped rather than emitted empty when it is missing, so a
    // stack never carries a stray quote pair or a leading comma.
    $this->assertSame(
      "'Aleo Sans'",
      self::font(['family' => 'Aleo Sans'])->getPropertyValue(),
      'A font declaring no generic contributes only its quoted family.'
    );
    $this->assertSame(
      'ui-sans-serif, system-ui, sans-serif',
      self::font(['generic' => 'ui-sans-serif, system-ui, sans-serif'])->getPropertyValue(),
      'A font declaring no family contributes only the generic fallback stack.'
    );
    $this->assertSame(
      '',
      self::font([])->getPropertyValue(),
      'A font declaring neither half has an empty stack rather than an empty entry.'
    );
  }

  /**
   * Tests that only the part of a family before a colon reaches the stack.
   */
  public function testTakesOnlyTheFamilyPartBeforeTheColonIntoTheStack(): void {
    // A Google font declares its family with the variable-axis suffix its own
    // URL needs. The suffix belongs in the request, never in the stack a
    // browser matches against an installed face.
    $font = self::font([
      'family' => 'Inter:ital,opsz,wght@0,14..32,100..900;1,14..32,100..900',
      'generic' => 'ui-sans-serif, system-ui, sans-serif',
    ]);

    $stack = $font->getPropertyValue();

    $this->assertSame(
      "'Inter', ui-sans-serif, system-ui, sans-serif",
      $stack,
      'Only the family name before the colon reaches the stack.'
    );
    $this->assertStringNotContainsString(':', $stack, 'No colon-separated suffix survives into the stack.');
    $this->assertStringNotContainsString('@', $stack, 'No axis specification survives into the stack.');

    // A family carrying no colon is taken whole, suffix handling and all.
    $this->assertSame(
      "'Aleo Sans'",
      self::font(['family' => 'Aleo Sans'])->getPropertyValue(),
      'A family with no colon is taken whole.'
    );
  }

  /**
   * Tests that faces agreeing on the whole merge key become one rule.
   */
  public function testMergesFacesAgreeingOnEveryKeyPartIntoOneRuleWithBothSources(): void {
    $font = self::font([
      'family' => 'Aleo Sans',
      'faces' => [
        self::face('/fonts/aleo-latin.woff2', self::LATIN_400),
        self::face('/fonts/aleo-latin.woff', ['format' => 'woff'] + self::LATIN_400),
      ],
    ]);

    $faces = $font->getFontFaces();

    $this->assertCount(1, $faces, 'Two faces agreeing on every key part become one rule.');
    $this->assertSame(
      ['Aleo Sans-400-normal-swap-U+0000-00FF---'],
      array_keys($faces),
      'The rule is keyed on family, weight, style, display, unicode range and the three overrides.'
    );

    $rule = reset($faces);
    $this->assertSame(
      "url('/fonts/aleo-latin.woff2') format('woff2'),\nurl('/fonts/aleo-latin.woff') format('woff')",
      $rule['src'],
      'The merged rule carries both sources, in declaration order.'
    );

    // Disagreeing on any one part of the key is enough to keep two faces apart.
    // Family is part of the key too, but it is read from the definition rather
    // than the face, so every face of one font agrees on it by construction.
    foreach ([
      'weight' => 700,
      'style' => 'italic',
      'display' => 'block',
      'unicode' => 'U+0100-024F',
      'ascent-override' => '95%',
      'descent-override' => '18%',
      'line-gap-override' => '4%',
    ] as $part => $value) {
      $split = self::font([
        'family' => 'Aleo Sans',
        'faces' => [
          self::face('/fonts/a.woff2', self::LATIN_400),
          self::face('/fonts/b.woff2', [$part => $value] + self::LATIN_400),
        ],
      ])->getFontFaces();
      $this->assertCount(2, $split, sprintf('Faces disagreeing on %s stay two rules.', $part));
    }
  }

  /**
   * Tests the three ways a face's font-display is resolved.
   */
  public function testReadsDisplayKeyFallingBackToLegacySwapKeyThenToSwap(): void {
    // The key a face declares today.
    $this->assertSame(
      'block',
      self::soleFace(['display' => 'block'])['font-display'],
      "A face's display key is what font-display is emitted from."
    );

    // A legacy alias, honoured for faces written before the display key
    // existed: `swap` there is a key whose value is the display value, not a
    // boolean. Ignoring the display key in favour of this one is exactly the
    // regression a past fix had to correct, which is why it gets its own
    // criterion rather than riding along on the merge-key test.
    $this->assertSame(
      'fallback',
      self::soleFace(['swap' => 'fallback'])['font-display'],
      'The legacy swap key is honoured as an alias for display.'
    );
    $this->assertSame(
      'optional',
      self::soleFace(['display' => 'optional', 'swap' => 'fallback'])['font-display'],
      'The display key wins over the legacy alias when a face declares both.'
    );

    // And with neither declared, `swap` as a value.
    $this->assertSame(
      'swap',
      self::soleFace([])['font-display'],
      'A face declaring neither key falls back to swap as a value.'
    );
  }

  /**
   * Tests that a face rule carries no property that resolved to empty.
   */
  public function testDropsPropertiesThatResolveToEmptyFromTheFaceRule(): void {
    // A face declaring nothing but a source: weight, style, unicode range and
    // all three overrides resolve to empty.
    $sparse = self::soleFace([]);

    $this->assertSame(
      ['font-family', 'src', 'font-display'],
      array_keys($sparse),
      'Only the properties that resolved to something are emitted.'
    );
    foreach ([
      'font-weight',
      'font-style',
      'ascent-override',
      'descent-override',
      'line-gap-override',
      'unicode-range',
    ] as $property) {
      $this->assertArrayNotHasKey(
        $property,
        $sparse,
        sprintf('%s is dropped rather than emitted blank.', $property)
      );
    }

    // Declared, they are all emitted — so the sparse rule above is short
    // because the properties resolved to empty, not because they are unknown.
    $full = self::soleFace(self::LATIN_400 + self::OVERRIDES);
    $this->assertSame(
      [
        'font-family',
        'src',
        'font-weight',
        'font-style',
        'font-display',
        'ascent-override',
        'descent-override',
        'line-gap-override',
        'unicode-range',
      ],
      array_keys($full),
      'A fully declared face emits every property.'
    );
  }

  /**
   * Tests that faces differing only in an override stay two rules.
   *
   * The three override properties are part of the merge key, so two faces a
   * reader would call different are two rules to the merge as well. They were
   * once outside it: two such faces merged, the first one's metrics were kept,
   * and the second one's file was served under them while its own metrics were
   * dropped on the floor. Each rule now carries the metrics it was declared
   * with, and its own source.
   */
  public function testKeepsFacesDifferingOnlyInAnOverrideAsSeparateRules(): void {
    $second = [
      'ascent-override' => '95%',
      'descent-override' => '18%',
      'line-gap-override' => '4%',
    ];

    $font = self::font([
      'family' => 'Aleo Sans',
      'faces' => [
        self::face('/fonts/first.woff2', self::LATIN_400 + self::OVERRIDES),
        self::face('/fonts/second.woff2', self::LATIN_400 + $second),
      ],
    ]);

    $faces = array_values($font->getFontFaces());

    $this->assertCount(
      2,
      $faces,
      'The overrides are part of the merge key, so faces differing there stay apart.'
    );

    // Each rule keeps the metrics it was declared with — the second face's are
    // no longer lost to the first face's rule.
    foreach ([0 => self::OVERRIDES, 1 => $second] as $index => $overrides) {
      foreach ($overrides as $property => $value) {
        $this->assertSame(
          $value,
          $faces[$index][$property],
          sprintf('Rule %d keeps its own %s.', $index, $property)
        );
      }
    }

    // And each keeps its own source, rather than one rule carrying both files
    // under one set of metrics.
    $this->assertSame("url('/fonts/first.woff2') format('woff2')", $faces[0]['src']);
    $this->assertSame("url('/fonts/second.woff2') format('woff2')", $faces[1]['src']);

    // Faces agreeing on their overrides still merge: it is disagreement that
    // splits them, not the presence of an override.
    $agreeing = self::font([
      'family' => 'Aleo Sans',
      'faces' => [
        self::face('/fonts/a.woff2', self::LATIN_400 + self::OVERRIDES),
        self::face('/fonts/b.woff2', self::LATIN_400 + self::OVERRIDES),
      ],
    ])->getFontFaces();

    $this->assertCount(1, $agreeing, 'Faces agreeing on their overrides merge as before.');
    $this->assertSame(
      "url('/fonts/a.woff2') format('woff2'),\nurl('/fonts/b.woff2') format('woff2')",
      reset($agreeing)['src'],
      'The merged rule carries both sources, in declaration order.'
    );
  }

  /**
   * Tests that the preview applies the font through its own selector.
   */
  public function testRendersPreviewCarryingTheSelectorInTheApplyingClass(): void {
    $preview = self::font([
      'family' => 'Aleo Sans',
      'selector' => 'aleo',
    ])->preview();

    $this->assertSame('container', $preview['#type']);

    $classes = $preview['#attributes']['class'];
    $this->assertSame(
      ['block text-3xl font-aleo'],
      $classes,
      'The preview applies the font through the font-{selector} utility.'
    );
    $this->assertStringContainsString(
      'font-aleo',
      implode(' ', $classes),
      'The selector reaches the class that applies the font.'
    );

    // The selector is read per font, not baked into the preview: a font with a
    // different selector is previewed under a different utility.
    $this->assertSame(
      ['block text-3xl font-brand'],
      self::font(['family' => 'Brand Sans'])->preview()['#attributes']['class'],
      'A different selector previews under a different utility.'
    );

    // The sample text is what the utility is applied to.
    $this->assertStringContainsString(
      'brown fox',
      (string) $preview['markup']['#markup'],
      'The preview carries sample text for the utility to style.'
    );
  }

  /**
   * Reads a font's selector knowing nothing but the interface.
   *
   * @param \Drupal\neo_font\FontInterface $font
   *   The font to read.
   *
   * @return string
   *   The font selector.
   */
  private function readSelector(FontInterface $font): string {
    return $font->getSelector();
  }

  /**
   * Builds a font face declaration.
   *
   * @param string $src
   *   The face source.
   * @param array<string, mixed> $face
   *   The face properties under test. They win over the defaults.
   *
   * @return array<string, mixed>
   *   The face declaration.
   */
  private static function face(string $src, array $face = []): array {
    return $face + ['src' => $src, 'format' => 'woff2'];
  }

  /**
   * Builds a font over a single face and returns the rule it produces.
   *
   * @param array<string, mixed> $face
   *   The face properties under test.
   *
   * @return array<string, string>
   *   The one font-face rule the font emits.
   */
  private static function soleFace(array $face): array {
    $faces = self::font([
      'family' => 'Aleo Sans',
      'faces' => [self::face('/fonts/aleo.woff2', $face)],
    ])->getFontFaces();
    return reset($faces);
  }

  /**
   * Builds a font plugin over a definition.
   *
   * The defaults carry no `family`, no `generic` and no `faces`, so a test can
   * assert what happens when one of them is absent by simply not passing it.
   *
   * @param array<string, mixed> $definition
   *   The definition properties under test, merged over the minimal shape
   *   definition processing always produces.
   *
   * @return \Drupal\neo_font\FontDefault
   *   The font plugin.
   */
  private static function font(array $definition): FontDefault {
    return new FontDefault([], self::PLUGIN_ID, $definition + [
      'id' => 'brand-sans',
      'label' => 'Brand Sans',
      'type' => 'local',
      'selector' => 'brand',
    ]);
  }

}
