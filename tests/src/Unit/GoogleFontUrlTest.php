<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_font\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\neo_font\FontDefault;
use Drupal\neo_font\FontPluginManager;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;

/**
 * Tests the stylesheet URL the manager composes from its Google fonts.
 *
 * This is the composition half of the Google font link. The other half — the
 * three head links the module attaches on every page render — is a kernel test,
 * because the hook is procedural and resolves the manager out of the container.
 *
 * @see \Drupal\Tests\neo_font\Kernel\GoogleFontLinkAttachmentTest
 *
 * **Why this needs no container.** `getGoogleUrl()` reads the manager's
 * definitions, and `DefaultPluginManager` serves those from its cache backend
 * before discovery is ever consulted: `getDefinitions()` asks
 * `getCachedDefinitions()` first and only falls through to `findDefinitions()`
 * when the backend returns nothing. So a cache backend handing back a
 * definition set is the whole fixture — no extension list, no YAML on disk, no
 * database. The class is `final`, so no test subclass exists and none is
 * needed.
 *
 * The definitions handed to the backend are in their *processed* shape, which
 * is what a real cache entry holds: `getDefinitions()` returns cached data
 * verbatim, without re-running `processDefinition()` over it.
 *
 * These characterise what the module does today and change no production code.
 */
#[Group('neo_font')]
final class GoogleFontUrlTest extends UnitTestCase {

  /**
   * The cache key the manager stores and reads its definitions under.
   *
   * Set by `setCacheBackend()` in the manager's constructor. The stub answers
   * this key and nothing else, so a manager that stopped reading its
   * definitions from the cache would find an empty backend rather than
   * silently pass on a set it never asked for.
   */
  private const CACHE_KEY = 'neo_font_plugins';

  /**
   * The URL prefix every composed stylesheet URL carries.
   */
  private const PREFIX = 'https://fonts.googleapis.com/css2?';

  /**
   * The URL suffix every composed stylesheet URL carries.
   */
  private const SUFFIX = '&display=swap';

  /**
   * Tests that it composes a URL from a family, spaces replaced by plus signs.
   */
  public function testComposesStylesheetUrlFromFamilyReplacingSpacesWithPlusSigns(): void {
    $url = $this->urlFor([
      'brand_sans' => self::googleFont('Source Sans 3'),
    ]);

    $this->assertSame(
      self::PREFIX . 'family=Source+Sans+3' . self::SUFFIX,
      $url,
      'A Google font contributes its family, spaces and all, as one query parameter.'
    );

    // Every space becomes a plus sign, not just the first: a raw space in a
    // stylesheet href is the failure this replacement exists to prevent.
    $this->assertStringNotContainsString(' ', (string) $url, 'No space survives into the URL.');

    // A single-word family is passed through unchanged rather than mangled,
    // which is what says the replacement is a replacement and not an encoding
    // pass over the whole value.
    $this->assertSame(
      self::PREFIX . 'family=Inter' . self::SUFFIX,
      $this->urlFor(['inter' => self::googleFont('Inter')])
    );
  }

  /**
   * Tests that it appends a declared spec to the family after a colon.
   */
  public function testAppendsDeclaredSpecToFamilyAfterColon(): void {
    $this->assertSame(
      self::PREFIX . 'family=Inter:wght@400;700' . self::SUFFIX,
      $this->urlFor(['inter' => self::googleFont('Inter', 'wght@400;700')]),
      'The spec follows the family after a colon, exactly as declared.'
    );

    // The spec is appended after the space replacement, so a multi-word family
    // carrying one keeps its plus signs and gains the colon behind them.
    $this->assertSame(
      self::PREFIX . 'family=Source+Sans+3:ital,wght@0,400;1,700' . self::SUFFIX,
      $this->urlFor(['brand_sans' => self::googleFont('Source Sans 3', 'ital,wght@0,400;1,700')])
    );

    // The append is conditional on the spec being non-empty, so a font
    // declaring none contributes a bare family and no trailing colon — and a
    // font declaring an empty one is the same as declaring none, because the
    // guard is an emptiness check rather than an isset().
    foreach ([NULL, ''] as $spec) {
      $this->assertSame(
        self::PREFIX . 'family=Inter' . self::SUFFIX,
        $this->urlFor(['inter' => self::googleFont('Inter', $spec)]),
        'A font declaring no spec contributes its family alone.'
      );
    }
  }

  /**
   * Tests that it joins several Google families into one stylesheet URL.
   */
  public function testJoinsSeveralGoogleFamiliesIntoOneStylesheetUrl(): void {
    $url = $this->urlFor([
      'brand_sans' => self::googleFont('Source Sans 3', 'wght@400;700'),
      'brand_serif' => self::googleFont('Playfair Display'),
      'brand_mono' => self::googleFont('IBM Plex Mono', 'wght@400'),
    ]);

    // One URL, one `display=swap`, and each family its own `family=` parameter
    // joined by an ampersand — a site with three Google fonts fetches one
    // stylesheet, not three.
    $this->assertSame(
      self::PREFIX
      . 'family=Source+Sans+3:wght@400;700'
      . '&family=Playfair+Display'
      . '&family=IBM+Plex+Mono:wght@400'
      . self::SUFFIX,
      $url,
      'Every Google family joins one URL, in the order the definitions came back.'
    );
    $this->assertSame(3, substr_count((string) $url, 'family='), 'Each font contributes exactly one parameter.');
    $this->assertSame(1, substr_count((string) $url, 'display=swap'), 'The suffix is written once, after the last family.');

    // Only Google fonts contribute. A local font and a generic sitting in the
    // same definition set are passed over, which is the normal case: every
    // site has both, and neither is fetched from Google.
    $this->assertSame(
      $url,
      $this->urlFor([
        'sans' => self::genericFont('Sans-Serif'),
        'brand_sans' => self::googleFont('Source Sans 3', 'wght@400;700'),
        'inter' => self::localFont('Inter'),
        'brand_serif' => self::googleFont('Playfair Display'),
        'brand_mono' => self::googleFont('IBM Plex Mono', 'wght@400'),
      ]),
      'A local font and a generic in the same set change nothing about the URL.'
    );
  }

  /**
   * Tests that it composes no URL at all when nothing declares a Google font.
   */
  public function testComposesNoUrlAtAllWhenNothingDeclaresGoogleFont(): void {
    // NULL, not an empty string and not a bare prefix carrying no family. The
    // page attachment reads this return value as a boolean and attaches three
    // head links when it is truthy, so a site declaring no Google font depends
    // on this being falsy to get no links at all.
    $this->assertNull(
      $this->urlFor([
        'sans' => self::genericFont('Sans-Serif'),
        'inter' => self::localFont('Inter'),
      ]),
      'A definition set holding no Google font composes no URL.'
    );

    // The same for a set holding nothing whatsoever.
    $this->assertNull($this->urlFor([]), 'An empty definition set composes no URL.');

    // The control: adding one Google font to the first set is what makes a URL
    // appear, so the NULL above is the absence of Google fonts rather than a
    // manager that never composed anything.
    $this->assertSame(
      self::PREFIX . 'family=Inter' . self::SUFFIX,
      $this->urlFor([
        'sans' => self::genericFont('Sans-Serif'),
        'inter' => self::localFont('Inter'),
        'brand' => self::googleFont('Inter'),
      ])
    );
  }

  /**
   * Composes the stylesheet URL for a definition set.
   *
   * @param array<string, array<string, mixed>> $definitions
   *   Processed font definitions, keyed by plugin id, as a cache entry holds
   *   them.
   *
   * @return string|null
   *   The composed URL, or NULL when no Google font contributed.
   */
  private function urlFor(array $definitions): ?string {
    return $this->manager($definitions)->getGoogleUrl();
  }

  /**
   * Builds a font plugin manager serving one definition set from its cache.
   *
   * @param array<string, array<string, mixed>> $definitions
   *   Processed font definitions, keyed by plugin id.
   *
   * @return \Drupal\neo_font\FontPluginManager
   *   The manager under test.
   */
  private function manager(array $definitions): FontPluginManager {
    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturnCallback(
      static fn (string $cid): object|false => $cid === self::CACHE_KEY ? (object) ['data' => $definitions] : FALSE
    );

    $manager = new FontPluginManager(
      '/var/www/html/web',
      $this->createMock(ModuleHandlerInterface::class),
      $this->createMock(ThemeHandlerInterface::class),
      $this->createMock(FileSystemInterface::class),
      $this->createMock(FileUrlGeneratorInterface::class),
      $cache,
      new NullLogger(),
    );
    $manager->setStringTranslation($this->getStringTranslationStub());
    return $manager;
  }

  /**
   * Builds a processed Google font definition.
   *
   * @param string $family
   *   The font family.
   * @param string|null $spec
   *   The Google spec to declare, or NULL to declare none at all.
   *
   * @return array<string, mixed>
   *   A processed font definition.
   */
  private static function googleFont(string $family, ?string $spec = NULL): array {
    $definition = self::definition($family, 'google');
    if ($spec !== NULL) {
      $definition['spec'] = $spec;
    }
    return $definition;
  }

  /**
   * Builds a processed local font definition.
   *
   * @param string $family
   *   The font family.
   *
   * @return array<string, mixed>
   *   A processed font definition.
   */
  private static function localFont(string $family): array {
    // A spec is meaningless on a local font and no fixture would declare one.
    // It is here so that a set holding a local font cannot pass the Google
    // tests by accident: the type is the only thing keeping it out of the URL.
    return ['spec' => 'wght@400'] + self::definition($family, 'local');
  }

  /**
   * Builds a processed generic font definition.
   *
   * @param string $family
   *   The font family.
   *
   * @return array<string, mixed>
   *   A processed font definition.
   */
  private static function genericFont(string $family): array {
    return self::definition($family, 'generic');
  }

  /**
   * Builds a processed font definition of a given type.
   *
   * The shape is what `processDefinition()` leaves behind — id and selector
   * derived, label defaulted — because that is what the cache entry
   * `getGoogleUrl()` reads holds.
   *
   * @param string $family
   *   The font family.
   * @param string $type
   *   The font type.
   *
   * @return array<string, mixed>
   *   A processed font definition.
   */
  private static function definition(string $family, string $type): array {
    $id = str_replace(' ', '-', strtolower($family));
    return [
      'id' => $id,
      'label' => $family,
      'type' => $type,
      'generic' => 'sans',
      'selector' => $id,
      'family' => $family,
      'class' => FontDefault::class,
      'provider' => 'neo_font_test',
    ];
  }

}
