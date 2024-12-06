<?php

declare(strict_types=1);

namespace Drupal\neo_font\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\neo_font\FontPluginManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Returns responses for Neo | Font routes.
 */
final class FontListController extends ControllerBase {

  /**
   * The controller constructor.
   */
  public function __construct(
    private readonly FontPluginManager $pluginManagerNeoFont,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('plugin.manager.neo_font'),
    );
  }

  /**
   * Builds the response.
   */
  public function __invoke(): array {
    $build = [];
    $supportedTypes = $this->pluginManagerNeoFont->getSupportedTypes();
    $definitions = $this->pluginManagerNeoFont->getDefinitions();
    foreach ($supportedTypes as $type => $label) {
      $defs = array_filter($definitions, fn($def) => $def['type'] === $type);
      if ($defs) {
        $build[$type] = [
          '#type' => 'table',
          '#caption' => $label,
          '#header' => [
            $this->t('Label'),
            $this->t('Class'),
            $this->t('Generic'),
            $this->t('Preview'),
          ],
        ];
        foreach ($defs as $definition) {
          /** @var \Drupal\neo_font\FontInterface $instance */
          $instance = $this->pluginManagerNeoFont->createInstance($definition['id']);
          $row = [];
          $row[]['#markup'] = '<span class="whitespace-nowrap">' . $instance->label() . '</span><br><small>(' . $definition['id'] . ')</small>';
          $row[]['#markup'] = '<span class="whitespace-nowrap"><pre>.font-' . $definition['selector'] . '</pre></span>';
          $row[]['#markup'] = '<small>' . $instance->getPropertyValue() . '</small>';
          $row[] = $instance->preview();
          $build[$type][] = $row;
        }
      }
    }
    return $build;
  }

}
