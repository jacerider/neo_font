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
   * Finds every problem in every extension's font declarations.
   *
   * The **font declaration check**: one pass over the declaration files, run at
   * prepare so that a declaration that cannot produce a font fails the build
   * the author is already running rather than the site an hour later. It
   * reports, and does nothing else — no logging, no dropping, no throwing. What
   * a problem costs is the caller's decision.
   *
   * It reads the files rather than the cached definition set on purpose. A
   * refusal removes the definition from that set, so a cache-served set cannot
   * report what is missing from it.
   *
   * @return list<\Drupal\neo_font\FontDeclarationProblem>
   *   Every problem found across every declared font, in discovery order.
   *   Empty when every declaration is sound, which is the state of every site
   *   whose fonts all build.
   */
  public function checkDeclarations(): array;

  /**
   * Returns the supported types.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   The supported font types, keyed by machine name.
   */
  public function getSupportedTypes(): array;

  /**
   * Returns the setting types.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   The font roles, keyed by role name.
   */
  public function getSettingTypes(): array;

}
