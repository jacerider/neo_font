<?php

declare(strict_types=1);

namespace Drupal\neo_font\EventSubscriber;

use Drupal\neo_build\Event\NeoBuildEvent;
use Drupal\neo_font\FontPluginManager;
use Drupal\neo_settings\SettingsRepositoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Act on build events.
 *
 * @package Drupal\custom_events\EventSubscriber
 */
class NeoBuildEventSubscriber implements EventSubscriberInterface {

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
   * @param \Drupal\neo_build\Event\NeoBuildEvent $event
   *   The neo build event.
   */
  public function onBuild(NeoBuildEvent $event) {
    $collection = $event->getCollection();
    $theme = [];
    $base = [];
    $settingTypes = $this->pluginManagerNeoFont->getSettingTypes();
    foreach ($this->pluginManagerNeoFont->getDefinitions() as $definition) {
      $id = $definition['id'];
      /** @var \Drupal\neo_font\FontInterface $instance */
      $instance = $this->pluginManagerNeoFont->createInstance($id);
      $theme['fontFamily'][$definition['selector']] = explode(', ', $instance->getPropertyValue());
      foreach ($settingTypes as $type => $label) {
        if ($id === $this->settings->getValue($type)) {
          $theme['fontFamily'][$type] = 'var(--font-' . $type . '-family)';
        }
      }
    }
    $collection->addTailwindTheme($theme);
    $collection->addTailwindBase($base);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      NeoBuildEvent::EVENT_NAME => 'onBuild',
    ];
  }

}
