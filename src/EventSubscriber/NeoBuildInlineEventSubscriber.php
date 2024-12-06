<?php

declare(strict_types = 1);

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
    SettingsRepositoryInterface $settings_repository
  ) {
    $this->settings = $settings_repository->getActive();
  }

  /**
   * Subscribe to the user login event dispatched.
   *
   * We inject the CSS variables directly into the DOM so that we do not need
   * to wait for the build to complete before the CSS is applied.
   *
   * @param \Drupal\neo_build\Event\NeoBuildInlineEvent $event
   *   The neo build dev event.
   */
  public function onInlineBuild(NeoBuildInlineEvent $event) {
    $settingTypes = $this->pluginManagerNeoFont->getSettingTypes();
    foreach ($this->pluginManagerNeoFont->getDefinitions() as $definition) {
      $id = $definition['id'];
      /** @var \Drupal\neo_font\FontInterface $instance */
      $instance = $this->pluginManagerNeoFont->createInstance($id);
      $event->addCssValue('font-family', $instance->getPropertyValue(), '.font-' . $definition['selector']);
      foreach ($settingTypes as $type => $label) {
        if ($id === $this->settings->getValue($type)) {
          $event->addCssValue('--font-' . $type . '-family', $instance->getPropertyValue());
        }
      }
      foreach ($instance->getFontFaces() as $face) {
        $event->addCssValue($face['src'], $face, '@font-face');
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
