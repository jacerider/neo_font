<?php

declare(strict_types=1);

namespace Drupal\neo_font;

use Drupal\Component\Utility\NestedArray;
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
   * The plugin ids definition processing refused during the current pass.
   *
   * Definition processing is handed one definition by reference and cannot
   * remove it from a set it never sees, so the refusals it finds are recorded
   * here and acted on by the pass that does own the set.
   *
   * @var array<string, string>
   *
   * @see \Drupal\neo_font\FontPluginManager::alterDefinitions()
   */
  protected array $droppedDefinitions = [];

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
    $this->droppedDefinitions = [];
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
   * Where a dropped font definition actually leaves the set, and the one place
   * in the pass where that choice is available to make.
   *
   * Definition processing collects a refusal per definition but cannot remove
   * one — it is handed a single definition by reference — so the removal has to
   * happen in the pass that owns the whole set. Core's definition finding runs
   * the processing loop, then this alter, then its own provider filter, and
   * this module's generic resolution and label sort come after all three. Every
   * one of those is downstream of here, which is what makes this the earliest
   * point the drop can happen.
   *
   * It is deliberately *before* `hook_neo_font_info`. Core runs that alter from
   * inside its definition finding, so an implementation would otherwise be
   * handed the slot of a font that does not exist, and would have to know which
   * entries the pass was about to throw away in order to read the set right.
   * A dropped font does not exist for the rest of the request, and that has to
   * be true of the alter hook too. No implementation exists on this site or in
   * any `jacerider/*` package, which is exactly why the choice is made on
   * purpose here rather than discovered by whoever writes the first one.
   *
   * @param array<mixed> $definitions
   *   The discovered definitions, keyed by plugin id, by reference.
   */
  protected function alterDefinitions(&$definitions): void {
    foreach ($this->droppedDefinitions as $plugin_id) {
      unset($definitions[$plugin_id]);
    }
    $this->droppedDefinitions = [];
    parent::alterDefinitions($definitions);
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

    // Definition processing throws nothing. It runs the checks, logs what they
    // found, and records a refusal for the drop — see ADR 0004 for why, and for
    // why that reads as swallowing errors until you know that the Google font
    // link resolves the whole definition set inside hook_page_attachments, so
    // a throw here is a stack trace on every page render of every site.
    foreach ($this->findDeclarationProblems($definition, (string) $plugin_id) as $problem) {
      $this->logger->log($problem->severity->logLevel(), $problem->message, $problem->context);
      if ($problem->isRefusal()) {
        $this->droppedDefinitions[(string) $plugin_id] = (string) $plugin_id;
      }
    }
  }

  /**
   * {@inheritdoc}
   *
   * The font declaration check. It runs the same single pass definition
   * processing runs, over the declaration files themselves, and hands back
   * everything it found without logging any of it and without dropping
   * anything: the caller decides what a problem costs, and prepare's caller
   * fails the build over a refusal.
   *
   * Reading the files rather than the cached definition set is not an
   * optimisation detail — it is the whole reason the check can answer at all. A
   * refusal removes the definition from that set, so by the time anything has
   * asked for a definition the evidence is gone, and a cache-served set would
   * report a clean site while the author's font is missing from every page. The
   * cost is one extra YAML pass and one `file_exists()` per declared face, on a
   * step a human triggered that already resets extension information and
   * rebuilds every library definition.
   *
   * Nothing here goes through `hook_neo_font_info`. The alter is part of what a
   * consumer of the definition set is handed; a declaration file is what an
   * extension actually wrote, and that is the thing an author is being asked to
   * fix.
   *
   * @return list<\Drupal\neo_font\FontDeclarationProblem>
   *   Every problem found across every declared font, in discovery order.
   */
  public function checkDeclarations(): array {
    $problems = [];
    foreach ($this->getDiscovery()->getDefinitions() as $plugin_id => $definition) {
      if (!is_array($definition)) {
        continue;
      }
      // The same merge core's definition processing performs before this
      // module's own runs, because every check reads a key the defaults may be
      // the only source of.
      $definition = NestedArray::mergeDeep($this->defaults, $definition);
      foreach ($this->findDeclarationProblems($definition, (string) $plugin_id) as $problem) {
        $problems[] = $problem;
      }
    }
    return $problems;
  }

  /**
   * Finds everything wrong with one font declaration.
   *
   * The single pass every check lives in. It returns what it found rather than
   * acting on it, because it has two callers that act differently: definition
   * processing logs each problem and drops the definition, and the prepare-time
   * font declaration check collects them and fails the build. Neither owns the
   * rule — this method does — so the two can never disagree about what is wrong
   * with a file.
   *
   * It derives as much as it checks, and the order of the two is load-bearing:
   * the id, the label and the font selector are derived between the checks
   * because the checks after them read what they derived.
   *
   * @param array<string, mixed> $definition
   *   The font definition to check, by reference. The derivations are written
   *   back to it.
   * @param string $plugin_id
   *   The definition's plugin id, which is the font key as the YAML spells it.
   *
   * @return list<\Drupal\neo_font\FontDeclarationProblem>
   *   Every problem found with the declaration, in the order they were found.
   */
  protected function findDeclarationProblems(array &$definition, string $plugin_id): array {
    $problems = [];
    $extension = isset($definition['provider']) && is_string($definition['provider']) ? $definition['provider'] : '';

    if (empty($definition['family'])) {
      $problems[] = FontDeclarationProblem::refusal($extension, $plugin_id, 'declares no font family, so there is no font stack to build from it.');
    }

    $types = array_keys($this->getSupportedTypes());
    if (empty($definition['type'])) {
      $problems[] = FontDeclarationProblem::refusal($extension, $plugin_id, 'declares no font type, so there is no way to process it. Declare one of: @types.', [
        '@types' => implode(', ', $types),
      ]);
    }
    elseif (!in_array($definition['type'], $types)) {
      // Only reachable with a type that was declared, so the two type checks
      // never both fire: an undeclared type is empty rather than absent, and
      // would satisfy this one just as well.
      $problems[] = FontDeclarationProblem::refusal($extension, $plugin_id, 'declares the font type %type, which is not supported. Declare one of: @types.', [
        '%type' => is_scalar($definition['type']) ? (string) $definition['type'] : gettype($definition['type']),
        '@types' => implode(', ', $types),
      ]);
    }

    $definition['id'] = str_replace('_', '-', $plugin_id);
    $definition['label'] = ($definition['label'] ?? '') ?: (string) ($definition['family'] ?? '');
    $definition['selector'] = ($definition['selector'] ?? '') ?: $definition['id'];

    $roles = $this->getSettingTypes();
    if (isset($roles[$definition['id']])) {
      $problems[] = FontDeclarationProblem::refusal($extension, $plugin_id, 'derives the id %id, which is the name of a font role. Rename the font key: a definition id claiming a role name destroys that role\'s token.', [
        '%id' => $definition['id'],
      ]);
    }
    // The selector is the half of a definition that reaches CSS, and it shares
    // its keyspace with the font roles: a font selecting on a role's name and
    // that role write the same key, and one of the two is silently lost. Only
    // an explicitly declared selector can get here — a selector left undeclared
    // takes the definition's id, and an id equal to a role name is refused by
    // the branch above. That guarantee is why this is an elseif and not a
    // second if: a definition already refused for its id would otherwise be
    // reported for the selector it derived from that same id.
    //
    // This reports rather than refuses on purpose. The refusal belongs to a
    // later release, so that a site already carrying a colliding declaration
    // gets one released version that warns it before one that drops the font.
    // Nothing here says which of the two entries wins: that is the role
    // resolver's subject, and this message has to stay true either side of it.
    elseif (isset($roles[(string) $definition['selector']])) {
      $problems[] = FontDeclarationProblem::report($extension, $plugin_id, 'declares the selector %selector, which is also the name of the %role font role. Rename the selector: a font selector matching a font role name is reported now and will be refused in a future release.', [
        '%selector' => $definition['selector'],
        '%role' => $definition['selector'],
      ]);
    }

    // Only the types with a case here are processed further; a generic font
    // is terminal and falls straight through. The local branch is the other
    // half of this pass rather than a step after it — it returns problems the
    // same way, so a local font's four checks reach both callers through the
    // same list as the four above.
    switch ($definition['type']) {
      case 'local':
        $problems = array_merge($problems, $this->processDefinitionLocal($definition, $plugin_id));
        break;
    }

    return $problems;
  }

  /**
   * Finds everything wrong with a local font, and rewrites it when nothing is.
   *
   * The other half of the pass above, in the same shape: it returns what it
   * found rather than throwing, so an unresolvable provider, a font with no
   * faces, a face with no source and a source that is not on disk all reach
   * definition processing and the prepare-time font declaration check through
   * one list. The last of those is the one a theme author actually trips, and
   * its message names the path the check looked at rather than the fragment
   * they wrote — the fragment is already in front of them, and the directory it
   * resolved against is the whole reason the file was not found.
   *
   * Every face is checked before any face is rewritten, which is deliberate on
   * two counts. A theme that got three sources wrong learns all three from one
   * pass rather than one per build; and a refused declaration never reaches the
   * rewrite at all, so a font that is about to be dropped is never left with
   * half its faces resolved — and `base_path()`, the one piece of bootstrap
   * global state this class touches, is only ever called for a font that is
   * actually being built.
   *
   * @param array<string, mixed> $definition
   *   The font definition to process, by reference. Face sources are rewritten
   *   in place, and only when nothing is wrong with the declaration.
   * @param string $plugin_id
   *   The definition's plugin id, which is the font key as the YAML spells it.
   *
   * @return list<\Drupal\neo_font\FontDeclarationProblem>
   *   Every problem found with the local half of the declaration.
   */
  protected function processDefinitionLocal(array &$definition, string $plugin_id): array {
    $extension = isset($definition['provider']) && is_string($definition['provider']) ? $definition['provider'] : '';
    if ($extension !== '' && $this->moduleHandler->moduleExists($extension)) {
      $base_path = $this->moduleHandler->getModule($extension)->getPath();
    }
    elseif ($extension !== '' && $this->themeHandler->themeExists($extension)) {
      $base_path = $this->themeHandler->getTheme($extension)->getPath();
    }
    else {
      return [
        FontDeclarationProblem::refusal($extension, $plugin_id, 'names a provider that is neither an installed module nor an installed theme, so there is no directory to resolve its font files against.'),
      ];
    }

    $faces = $definition['faces'] ?? NULL;
    if (empty($faces) || !is_array($faces)) {
      return [
        FontDeclarationProblem::refusal($extension, $plugin_id, 'declares no font faces, so there is no font-face rule to emit and nothing for a browser to load.'),
      ];
    }

    $problems = [];
    $resolved = [];
    foreach ($faces as $delta => $face) {
      $position = (string) $delta;
      if (!is_array($face) || empty($face['src']) || !is_scalar($face['src'])) {
        $problems[] = FontDeclarationProblem::refusal($extension, $plugin_id, 'has a font face at position %face with no source, so there is no file to build a font-face rule from. Every face needs a src.', [
          '%face' => $position,
        ]);
        continue;
      }
      $src = $base_path . '/' . (string) $face['src'];
      $absolute = $this->appRoot . '/' . $src;
      if (!file_exists($absolute)) {
        $problems[] = FontDeclarationProblem::refusal($extension, $plugin_id, 'has a font face at position %face whose source %src is not on disk: nothing exists at %path.', [
          '%face' => $position,
          '%src' => (string) $face['src'],
          '%path' => $absolute,
        ]);
        continue;
      }
      $resolved[$delta] = $src;
    }

    if ($problems) {
      return $problems;
    }

    foreach ($faces as $delta => $face) {
      if (isset($resolved[$delta]) && is_array($face)) {
        $face['src'] = base_path() . $resolved[$delta];
        $faces[$delta] = $face;
      }
    }
    $definition['faces'] = $faces;

    return [];
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
