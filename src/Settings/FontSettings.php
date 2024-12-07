<?php

namespace Drupal\neo_font\Settings;

use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\neo_build\Build;
use Drupal\neo_font\FontPluginManagerInterface;
use Drupal\neo_settings\Plugin\SettingsBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Module settings.
 *
 * @Settings(
 *   id = "neo_font",
 *   label = @Translation("Font"),
 *   config_name = "neo_font.settings",
 *   menu_title = @Translation("Fonts"),
 *   route = "/admin/config/neo/font",
 *   admin_permission = "administer neo_font",
 * )
 */
class FontSettings extends SettingsBase {

  /**
   * The font plugin manager.
   *
   * @var \Drupal\neo_font\FontPluginManagerInterface
   */
  private FontPluginManagerInterface $fontManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    MessengerInterface $messenger,
    FormBuilderInterface $form_builder,
    FontPluginManagerInterface $font_plugin_manager
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $messenger, $form_builder);
    $this->fontManager = $font_plugin_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('messenger'),
      $container->get('form_builder'),
      $container->get('plugin.manager.neo_font')
    );
  }

  /**
   * {@inheritdoc}
   *
   * Instance settings are settings that are set both in the base form and the
   * variation form. They are editable in both forms and the values are merged
   * together.
   */
  protected function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);

    $supportedTypes = $this->fontManager->getSupportedTypes();
    $definitions = $this->fontManager->getDefinitions();
    $options = [];
    foreach ($definitions as $definition) {
      $options[$definition['id']] = $definition['label'] . ' (' . $definition['type'] . ')';
    }

    $form['preview'] = [
      '#type' => 'details',
      '#title' => $this->t('Available Fonts'),
      '#open' => FALSE,
    ];
    foreach ($supportedTypes as $type => $label) {
      $defs = array_filter($definitions, fn($def) => $def['type'] === $type);
      if ($defs) {
        $form['preview'][$type] = [
          '#type' => 'table',
          '#caption' => $label,
          '#header' => [
            $this->t('Label'),
            $this->t('Class'),
            $this->t('Family'),
            $this->t('Preview'),
          ],
        ];
        foreach ($defs as $definition) {
          /** @var \Drupal\neo_font\FontInterface $instance */
          $instance = $this->fontManager->createInstance($definition['id']);
          $row = [];
          $row[]['#markup'] = '<span class="whitespace-nowrap">' . $instance->label() . '</span><br><small>(' . $definition['id'] . ')</small>';
          $row[]['#markup'] = '<span class="whitespace-nowrap"><pre>.font-' . $definition['selector'] . '</pre></span>';
          $row[]['#markup'] = '<small>' . $instance->getPropertyValue() . '</small>';
          $row[] = $instance->preview();
          $form['preview'][$type][] = $row;
        }
      }
    }

    foreach ($this->fontManager->getSettingTypes() as $type => $label) {
      $form[$type] = [
        '#type' => 'select',
        '#title' => $this->t('@label font', [
          '@label' => $label,
        ]),
        '#description' => $this->t('The font that will be associated when using %font.', [
          '%font' => '.font-' . $type,
        ]),
        // '#disabled' => !$dev,
        '#options' => $options,
        '#default_value' => $this->getValue($type),
        '#required' => TRUE,
      ];
    }

    return $form;
  }

}
