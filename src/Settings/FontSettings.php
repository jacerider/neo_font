<?php

namespace Drupal\neo_font\Settings;

use Drupal\Core\Form\FormStateInterface;
use Drupal\neo_font\FontPluginManagerInterface;
use Drupal\neo_settings\Plugin\SettingsBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Module settings.
 *
 * Injects the font plugin manager and deliberately never the font role
 * resolver: the settings repository builds this plugin in its own constructor
 * and the resolver injects that repository, so consuming the resolver here
 * would close a container cycle.
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
   * Protected rather than private: DependencySerializationTrait's __sleep() is
   * compiled into SettingsBase, so it cannot see — and therefore cannot record
   * or restore — a private property declared on this subclass.
   *
   * @var \Drupal\neo_font\FontPluginManagerInterface
   *
   * @see https://www.drupal.org/node/3110266
   */
  protected FontPluginManagerInterface $fontManager;

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   * @param array<string, mixed> $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   *
   * @return static
   *   The settings plugin, carrying the font plugin manager.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->fontManager = $container->get('plugin.manager.neo_font');
    return $instance;
  }

  /**
   * {@inheritdoc}
   *
   * Instance settings are settings that are set both in the base form and the
   * variation form. They are editable in both forms and the values are merged
   * together.
   *
   * @param array<mixed> $form
   *   A nested array of form elements comprising the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return array<mixed>
   *   The form elements for this settings plugin.
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
        foreach ($defs as $plugin_id => $definition) {
          /** @var \Drupal\neo_font\FontInterface $instance */
          $instance = $this->fontManager->createInstance($plugin_id);
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
