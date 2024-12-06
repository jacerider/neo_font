<?php

namespace Drupal\neo_font;

use Drupal\Component\Plugin\Discovery\CachedDiscoveryInterface;
use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Cache\CacheableDependencyInterface;

/**
 * Defines an interface for style_font managers.
 */
interface FontPluginManagerInterface extends PluginManagerInterface, CachedDiscoveryInterface, CacheableDependencyInterface {

  /**
   * Generates the Google Fonts URL based on the font definitions.
   *
   * This method iterates through the font definitions and constructs a URL
   * for loading Google Fonts. It filters the definitions to include only those
   * of type 'google', and then builds the URL with the appropriate font
   * families and specifications.
   *
   * @return string|null
   *   The Google Fonts URL if there are any Google fonts defined, or NULL if
   *   there are no Google fonts.
   */
  public function getGoogleUrl(): ?string;

  /**
   * Returns the supported types.
   *
   * @return array
   *   An array of supported types.
   */
  public function getSupportedTypes(): array;

  /**
   * Returns the setting types.
   *
   * @return array
   *   An array of setting types.
   */
  public function getSettingTypes(): array;

}
