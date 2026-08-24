<?php

declare(strict_types=1);

namespace Drupal\neo_font;

use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\Core\Plugin\Discovery\YamlDiscovery;
use Drupal\Core\Plugin\Factory\ContainerFactory;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Psr\Log\LoggerInterface;

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
   *
   * @var array<string, mixed>
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
   *
   * @param string $appRoot
   *   The app root.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\Extension\ThemeHandlerInterface $theme_handler
   *   The theme handler.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $file_url_generator
   *   The file url generator.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   The discovery cache backend.
   * @param \Psr\Log\LoggerInterface $logger
   *   The neo_font logger channel. Injected rather than reached for through
   *   \Drupal::logger(), which the site's standards treat as a finding.
   */
  public function __construct(
    private readonly string $appRoot,
    ModuleHandlerInterface $module_handler,
    ThemeHandlerInterface $theme_handler,
    FileSystemInterface $file_system,
    FileUrlGeneratorInterface $file_url_generator,
    CacheBackendInterface $cache_backend,
    private readonly LoggerInterface $logger,
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
   *
   * @param string $provider
   *   The provider to check for.
   */
  protected function providerExists($provider): bool {
    return $this->moduleHandler->moduleExists($provider) || $this->themeHandler->themeExists($provider);
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, array<string, mixed>>
   *   The font definitions, keyed by plugin id.
   */
  protected function findDefinitions(): array {
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
   *
   * @param array<string, mixed> $definition
   *   The font definition to process, by reference.
   * @param string $plugin_id
   *   The definition's plugin id.
   */
  public function processDefinition(&$definition, $plugin_id): void {
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

    // The selector is the half of a definition that reaches CSS, and it shares
    // its keyspace with the font roles: a font selecting on a role's name and
    // that role write the same key, and one of the two is silently lost. Only
    // an explicitly declared selector can get here — a selector left undeclared
    // takes the definition's id, and an id equal to a role name was refused
    // immediately above.
    //
    // This reports rather than refuses on purpose. The refusal belongs to a
    // later release, so that a site already carrying a colliding declaration
    // gets one released version that warns it before one that fails its cache
    // rebuild. Nothing here says which of the two entries wins: that is the
    // role resolver's subject, and this message has to stay true either side
    // of it.
    if (isset($this->getSettingTypes()[(string) $definition['selector']])) {
      $this->logger->warning('The font %font declares the selector %selector, which is also the name of the %role font role. Rename the selector: a font selector matching a font role name is reported now and will be refused in a future release.', [
        '%font' => $plugin_id,
        '%selector' => $definition['selector'],
        '%role' => $definition['selector'],
      ]);
    }

    // Only the types with a case here are processed further; a generic font
    // is terminal and falls straight through.
    switch ($definition['type']) {
      case 'local':
        $this->processDefinitionLocal($definition, (string) $plugin_id);
        break;
    }
  }

  /**
   * Process a local font definition.
   *
   * @param array<string, mixed> $definition
   *   The font definition to process, by reference.
   * @param string $plugin_id
   *   The definition's plugin id.
   */
  protected function processDefinitionLocal(array &$definition, string $plugin_id): void {
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
    foreach ($definition['faces'] as &$face) {
      if (empty($face['src'])) {
        throw new PluginException(sprintf('Style font plugin property (%s) definition "faces.*.src" is required.', $plugin_id));
      }
      $src = $base_path . '/' . $face['src'];
      // Name the path the check actually used. The declared fragment is
      // already in front of whoever wrote it; what they cannot see is which
      // directory it resolved against, which is the whole reason the file was
      // not found.
      $absolute = $this->appRoot . '/' . $src;
      if (!file_exists($absolute)) {
        throw new PluginException(sprintf('Style font plugin property (%s) references a font file that does not exist. (%s)', $plugin_id, $absolute));
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
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   The supported font types, keyed by machine name.
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
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   The font roles, keyed by role name.
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
