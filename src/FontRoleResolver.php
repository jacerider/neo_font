<?php

declare(strict_types=1);

namespace Drupal\neo_font;

use Drupal\neo_settings\SettingsRepositoryInterface;

/**
 * Resolves which declared font serves each font role.
 *
 * This is the module's single owner of the role map. Both build subscribers
 * consume it, so the rule — and the plugin-id-versus-definition-id split it
 * turns on — is written exactly once.
 *
 * It is deliberately a service of its own rather than a method on the font
 * plugin manager: the settings repository builds the font settings plugin in
 * its own constructor and that plugin injects the plugin manager, so a manager
 * holding the repository would close a container cycle that fails at container
 * build. That cycle is the whole reason for this shape.
 *
 * Settings are read inside the methods, never in the constructor, and nothing
 * is memoised here: font discovery is cached by the plugin manager and the
 * active settings by the settings repository.
 */
final class FontRoleResolver implements FontRoleResolverInterface {

  /**
   * Constructs a new FontRoleResolver object.
   *
   * @param \Drupal\neo_font\FontPluginManagerInterface $fontPluginManager
   *   The font plugin manager.
   * @param \Drupal\neo_settings\SettingsRepositoryInterface $settingsRepository
   *   The neo_font settings repository.
   */
  public function __construct(
    private readonly FontPluginManagerInterface $fontPluginManager,
    private readonly SettingsRepositoryInterface $settingsRepository,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFonts(): array {
    $fonts = [];
    foreach (array_keys($this->fontPluginManager->getDefinitions()) as $plugin_id) {
      $font = $this->fontPluginManager->createInstance((string) $plugin_id);
      assert($font instanceof FontInterface);
      $fonts[(string) $plugin_id] = $font;
    }
    return $fonts;
  }

  /**
   * {@inheritdoc}
   */
  public function getRoleMap(): array {
    $fonts = $this->getFonts();

    // A font is instantiated by its plugin id but named in settings by its
    // definition id, and the two differ whenever a machine name contains an
    // underscore. Indexing by definition id is where that translation happens,
    // and it happens only here.
    $byDefinitionId = [];
    foreach ($this->fontPluginManager->getDefinitions() as $plugin_id => $definition) {
      if (isset($fonts[$plugin_id]) && isset($definition['id'])) {
        $byDefinitionId[(string) $definition['id']] = $fonts[$plugin_id];
      }
    }

    $settings = $this->settingsRepository->getActive();
    $map = [];
    foreach (array_keys($this->fontPluginManager->getSettingTypes()) as $role) {
      $configured = $settings->getValue((string) $role);
      if (is_string($configured) && isset($byDefinitionId[$configured])) {
        $map[(string) $role] = $byDefinitionId[$configured];
      }
    }
    return $map;
  }

}
