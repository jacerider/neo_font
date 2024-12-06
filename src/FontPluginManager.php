<?php

declare(strict_types=1);

namespace Drupal\neo_font;

use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\Core\Plugin\Discovery\YamlDiscovery;
use Drupal\Core\Plugin\Factory\ContainerFactory;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\neo\Helpers\Utilities;

/**
 * Defines a plugin manager to deal with neo_fonts.
 *
 * Modules can define neo_fonts in a MODULE_NAME.neo_fonts.yml file contained
 * in the module's base directory. Each neo_font has the following structure:
 *
 * @code
 *   MACHINE_NAME:
 *     label: STRING
 *     description: STRING
 * @endcode
 *
 * @see \Drupal\neo_font\FontDefault
 * @see \Drupal\neo_font\FontInterface
 */
final class FontPluginManager extends DefaultPluginManager implements FontPluginManagerInterface {

  use StringTranslationTrait;

  /**
   * The object that discovers plugins managed by this manager.
   *
   * @var \Drupal\Core\Plugin\Discovery\YamlDiscovery
   */
  protected $discovery;

  /**
   * The theme handler.
   *
   * @var \Drupal\Core\Extension\ThemeHandlerInterface
   */
  protected $themeHandler;

  /**
   * The file system.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The file url generator.
   *
   * @var \Drupal\Core\File\FileUrlGeneratorInterface
   */
  protected $fileUrlGenerator;

  /**
   * The directory.
   *
   * @var string
   */
  protected $directory = 'public://neo-fonts';

  /**
   * {@inheritdoc}
   */
  protected $defaults = [
    'id' => '',
    'label' => '',
    'type' => '',
    'generic' => 'sans',
    'selector' => '',
    'family' => '',
    // Default plugin class.
    'class' => FontDefault::class,
  ];

  /**
   * Constructs FontPluginManager object.
   */
  public function __construct(
    private readonly string $appRoot,
    ModuleHandlerInterface $module_handler,
    ThemeHandlerInterface $theme_handler,
    FileSystemInterface $file_system,
    FileUrlGeneratorInterface $file_url_generator,
    CacheBackendInterface $cache_backend
  ) {
    $this->factory = new ContainerFactory($this);
    $this->moduleHandler = $module_handler;
    $this->themeHandler = $theme_handler;
    $this->fileSystem = $file_system;
    $this->fileUrlGenerator = $file_url_generator;
    $this->alterInfo('neo_font_info');
    $this->setCacheBackend($cache_backend, 'neo_font_plugins');
  }

  /**
   * {@inheritdoc}
   */
  protected function getDiscovery(): YamlDiscovery {
    if (!isset($this->discovery)) {
      $this->discovery = new YamlDiscovery('neo.font', $this->moduleHandler->getModuleDirectories() + $this->themeHandler->getThemeDirectories());
      $this->discovery->addTranslatableProperty('label', 'label_context');
      $this->discovery->addTranslatableProperty('description', 'description_context');
    }
    return $this->discovery;
  }

  /**
   * {@inheritdoc}
   */
  protected function providerExists($provider) {
    return $this->moduleHandler->moduleExists($provider) || $this->themeHandler->themeExists($provider);
  }

  /**
   * {@inheritdoc}
   */
  protected function findDefinitions() {
    /** @var \Drupal\Core\File\FileSystemInterface $file_system */
    // $file_system = \Drupal::service('file_system');
    // $file_system->deleteRecursive($this->directory);
    $definitions = parent::findDefinitions();
    foreach ($definitions as &$definition) {
      if ($definition['type'] !== 'generic' && !empty($definition['generic']) && isset($definitions[$definition['generic']])) {
        $definition['generic'] = $definitions[$definition['generic']]['generic'];
      }
    }
    uasort($definitions, function ($a, $b) {
      return strnatcasecmp((string) $a['label'], (string) $b['label']);
    });
    return $definitions;
  }

  /**
   * {@inheritdoc}
   */
  public function processDefinition(&$definition, $plugin_id) {
    parent::processDefinition($definition, $plugin_id);

    if (empty($definition['family'])) {
      throw new PluginException(sprintf('Style font plugin property (%s) definition "family" is required.', $plugin_id));
    }

    if (empty($definition['type'])) {
      throw new PluginException(sprintf('Style font plugin property (%s) definition "type" is required.', $plugin_id));
    }

    if (!in_array($definition['type'], array_keys($this->getSupportedTypes()))) {
      throw new PluginException(sprintf('Style font plugin property (%s) definition "type" is not supported.', $plugin_id));
    }

    $definition['id'] = str_replace('_', '-', $plugin_id);
    $definition['label'] = $definition['label'] ?: (string) $definition['family'];
    $definition['selector'] = $definition['selector'] ?: $definition['id'];

    if (isset($this->getSettingTypes()[$definition['id']])) {
      throw new PluginException(sprintf('Style font plugin property (%s) definition "id" conflicts with a setting type.', $plugin_id));
    }

    // Skip generic font types.
    if ($definition['type'] === 'generic') {
      return;
    }

    switch ($definition['type']) {
      case 'local':
        $this->processDefinitionLocal($definition, $plugin_id);
        break;
    }
  }

  /**
   * Process a local font definition.
   */
  protected function processDefinitionLocal(&$definition, $plugin_id) {
    $provider = $definition['provider'];
    if ($this->moduleHandler->moduleExists($provider)) {
      $base_path = $this->moduleHandler->getModule($provider)->getPath();
    }
    elseif ($this->themeHandler->themeExists($provider)) {
      $base_path = $this->themeHandler->getTheme($provider)->getPath();
    }
    else {
      throw new PluginException(sprintf('Style font plugin property (%s) could not determine provider location.', $plugin_id));
    }
    if (empty($definition['faces'])) {
      throw new PluginException(sprintf('Style font plugin property (%s) definition "local.faces" is required.', $plugin_id));
    }
    foreach ($definition['faces'] as $delta => &$face) {
      if (empty($face['src'])) {
        throw new PluginException(sprintf('Style font plugin property (%s) definition "faces.*.src" is required.', $plugin_id));
      }
      $src = $base_path . '/' . $face['src'];
      if (!file_exists($this->appRoot . '/' . $src)) {
        throw new PluginException(sprintf('Style font plugin property (%s) references a font file that does not exist.', $plugin_id));
      }
      $face['src'] = base_path() . $src;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getGoogleUrl(): ?string {
    $google = [];
    foreach ($this->getDefinitions() as $definition) {
      if ($definition['type'] === 'google') {
        $value = str_replace(' ', '+', $definition['family']);
        if (!empty($definition['spec'])) {
          $value .= ':' . $definition['spec'];
        }
        $google[] = $value;
      }
    }
    if (empty($google)) {
      return NULL;
    }
    return 'https://fonts.googleapis.com/css2?' . implode('&', array_map(function ($value) {
      return 'family=' . $value;
    }, $google)) . '&display=swap';
  }

  /**
   * {@inheritDoc}
   */
  public function getSupportedTypes(): array {
    return [
      'local' => $this->t('Local'),
      'google' => $this->t('Google'),
      'generic' => $this->t('Generic'),
    ];
  }

  /**
   * {@inheritDoc}
   */
  public function getSettingTypes(): array {
    return [
      'primary' => $this->t('Primary'),
      'secondary' => $this->t('Secondary'),
      'accent' => $this->t('Accent'),
      'heading' => $this->t('Heading'),
      'ui' => $this->t('UI'),
    ];
  }

}
