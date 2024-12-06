<?php

declare(strict_types=1);

namespace Drupal\neo_font;

use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\Render\Markup;

/**
 * Default class used for neo_fonts plugins.
 */
final class FontDefault extends PluginBase implements FontInterface {

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    // The title from YAML file discovery may be a TranslatableMarkup object.
    return (string) $this->pluginDefinition['label'];
  }

  /**
   * {@inheritdoc}
   */
  public function getPropertyValue(): string {
    $values = [];
    if (!empty($this->pluginDefinition['family'])) {
      [$family] = explode(':', $this->pluginDefinition['family']);
      $values[] = "'" . $family . "'";
    }
    if (!empty($this->pluginDefinition['generic'])) {
      $values[] = $this->pluginDefinition['generic'];
    }
    return implode(', ', $values);
  }

  /**
   * {@inheritdoc}
   */
  public function preview(): array {
    $text = 'The <em>brown fox</em> jumped over the <strong>orange cow</strong>.';
    foreach ([100, 200, 300, 400, 500, 600, 700, 800, 900, 1000] as $weight) {
      $text .= ' <span style="font-weight:' . $weight . ';">' . $weight . '</span>';
    }
    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['block text-3xl font-' . $this->pluginDefinition['selector']],
      ],
      'markup' => [
        '#markup' => Markup::create('<div>' . $text . '</div>'),
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFontFaces(): array {
    $faces = [];
    foreach ($this->pluginDefinition['faces'] ?? [] as $face) {
      $faces[] = array_filter([
        'font-family' => "'" . $this->pluginDefinition['family'] . "'",
        'src' => "url('" . $face['src'] . "')" . ($face['format'] ?? '' ? 'format("' . $face['format'] . '")' : ''),
        'font-weight' => (string) ($face['weight'] ?? ''),
        'font-style' => (string) ($face['style'] ?? ''),
        'font-display' => (string) ($face['swap'] ?? 'swap'),
        'unicode-range' => (string) ($face['unicode'] ?? ''),
      ]);
    }
    return $faces;
  }

}
