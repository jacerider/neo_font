<?php

declare(strict_types=1);

namespace Drupal\neo_font;

/**
 * Interface for neo_font plugins.
 */
interface FontInterface {

  /**
   * Returns the translated plugin label.
   */
  public function label(): string;

  /**
   * Returns the font property value.
   */
  public function getPropertyValue(): string;

  /**
   * Generates a preview of the font with various weights.
   *
   * @return array
   *   A render array containing the font preview.
   */
  public function preview(): array;

  /**
   * Retrieves the font faces defined in the plugin definition.
   *
   * This method constructs an array of font face definitions based on the
   * plugin's configuration. Each font face includes properties such as
   * 'font-family', 'src', 'font-weight', and 'font-style'.
   *
   * @return array
   *   An array of font face definitions, where each definition is an
   *   associative array containing the following keys:
   *   - 'font-family': The font family name.
   *   - 'src': The source URL of the font.
   *   - 'font-weight': (optional) The weight of the font.
   *   - 'font-style': (optional) The style of the font.
   */
  public function getFontFaces(): array;

}
