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
    return (string) $this->getDefinitionValue('label');
  }

  /**
   * {@inheritdoc}
   */
  public function getPropertyValue(): string {
    $values = [];
    $family = (string) ($this->getDefinitionValue('family') ?? '');
    if (!empty($family)) {
      [$name] = explode(':', $family);
      $values[] = "'" . $name . "'";
    }
    $generic = (string) ($this->getDefinitionValue('generic') ?? '');
    if (!empty($generic)) {
      $values[] = $generic;
    }
    return implode(', ', $values);
  }

  /**
   * {@inheritdoc}
   */
  public function getSelector(): string {
    return (string) $this->getDefinitionValue('selector');
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
        'class' => ['block text-3xl font-' . $this->getSelector()],
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
    $definitionFaces = $this->getDefinitionValue('faces');
    $definitionFaces = is_array($definitionFaces) ? $definitionFaces : [];
    $family = (string) ($this->getDefinitionValue('family') ?? '');
    foreach ($definitionFaces as $face) {
      if (!is_array($face)) {
        continue;
      }
      $weight = (string) ($face['weight'] ?? '');
      $style = (string) ($face['style'] ?? '');
      $display = (string) ($face['display'] ?? $face['swap'] ?? 'swap');
      $range = (string) ($face['unicode'] ?? '');
      $ascentOverride = (string) ($face['ascent-override'] ?? '');
      $descentOverride = (string) ($face['descent-override'] ?? '');
      $lineGapOverride = (string) ($face['line-gap-override'] ?? '');
      // Every property that identifies the rule belongs in the key. The three
      // overrides are rule properties like the rest, so two faces declaring
      // different metrics are two rules: merging them would serve the second
      // face's file under the first face's metrics and drop the difference.
      $key = implode('-', [
        $family,
        $weight,
        $style,
        $display,
        $range,
        $ascentOverride,
        $descentOverride,
        $lineGapOverride,
      ]);
      if (isset($faces[$key])) {
        $faces[$key]['src'] .= ",\nurl('" . $face['src'] . "')" . ($face['format'] ?? '' ? " format('" . $face['format'] . "')" : '');
        continue;

      }
      $faces[$key] = array_filter([
        'font-family' => "'" . $family . "'",
        'src' => "url('" . $face['src'] . "')" . ($face['format'] ?? '' ? " format('" . $face['format'] . "')" : ''),
        'font-weight' => $weight,
        'font-style' => $style,
        'font-display' => $display,
        'ascent-override' => $ascentOverride,
        'descent-override' => $descentOverride,
        'line-gap-override' => $lineGapOverride,
        'unicode-range' => $range,
      ]);
    }
    return $faces;
  }

  /**
   * Reads one property from the plugin definition.
   *
   * PluginBase types the definition as an array or a definition object; YAML
   * discovery only ever produces the array, so this narrows it in one place
   * rather than at every read.
   *
   * @param string $key
   *   The definition property to read.
   *
   * @return mixed
   *   The property value, or NULL when the definition does not carry it.
   */
  private function getDefinitionValue(string $key): mixed {
    $definition = $this->pluginDefinition;
    return is_array($definition) ? ($definition[$key] ?? NULL) : NULL;
  }

}
