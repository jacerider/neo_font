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
 * The selector is the one definition property that reaches CSS, and consumers
 * used to read it out of the raw definition array. These pins cover reading it
 * through the plugin interface instead.
 */
#[Group('neo_font')]
final class FontDefaultTest extends UnitTestCase {

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

}
