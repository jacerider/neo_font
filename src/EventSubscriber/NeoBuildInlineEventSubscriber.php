<?php

declare(strict_types=1);

namespace Drupal\neo_font\EventSubscriber;

use Drupal\neo_build\Event\NeoBuildInlineEvent;
use Drupal\neo_font\FontPluginManager;
use Drupal\neo_settings\SettingsRepositoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Act on build events.
 *
 * @package Drupal\custom_events\EventSubscriber
 */
class NeoBuildInlineEventSubscriber implements EventSubscriberInterface {

  /**
   * The settings.
   *
   * @var \Drupal\neo_settings\Plugin\SettingsInterface
   */
  private $settings;

  /**
   * Constructs a new NeoBuildEventSubscriber object.
   */
  public function __construct(
    private readonly FontPluginManager $pluginManagerNeoFont,
    SettingsRepositoryInterface $settings_repository,
  ) {
    $this->settings = $settings_repository->getActive();
  }

  /**
   * Subscribe to the Neo build event dispatched.
   *
   * We inject the CSS variables directly into the DOM so that we do not need
   * to wait for the build to complete before the CSS is applied.
   *
   * @param \Drupal\neo_build\Event\NeoBuildInlineEvent $event
   *   The neo build dev event.
   */
  public function onInlineBuild(NeoBuildInlineEvent $event) {
    $settingTypes = $this->pluginManagerNeoFont->getSettingTypes();
    foreach ($this->pluginManagerNeoFont->getDefinitions() as $plugin_id => $definition) {
      $id = $definition['id'];
      /** @var \Drupal\neo_font\FontInterface $instance */
      $instance = $this->pluginManagerNeoFont->createInstance($plugin_id);
      $event->addCssValue('font-family', $instance->getPropertyValue(), '.font-' . $definition['selector']);
      foreach ($settingTypes as $type => $label) {
        if ($id === $this->settings->getValue($type)) {
          $event->addCssValue('--font-' . $type . '-family', $instance->getPropertyValue());
        }
      }
      // Key on the face id, not `src`: in this group the key identifies the
      // rule and is never printed, and one file can back several rules. A
      // variable font declared per weight — how Google Fonts lists one —
      // shared a `src`, so the rules overwrote each other and every weight
      // resolved to the survivor. getFontFaces() already keys by
      // family-weight-style-display-range and merges true duplicates.
      foreach ($instance->getFontFaces() as $faceId => $face) {
        $event->addCssValue($faceId, $face, '@font-face');
      }
    }
    $event->addCacheTags(['config:neo_font.settings']);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      NeoBuildInlineEvent::EVENT_NAME => 'onInlineBuild',
    ];
  }

}
