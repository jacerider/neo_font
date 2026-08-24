<?php

declare(strict_types=1);

namespace Drupal\neo_font;

/**
 * One thing wrong with a font declaration.
 *
 * Definition processing's checks produce one of these instead of throwing, so
 * the same finding can be acted on differently by each of the two callers: the
 * plugin manager logs it and drops the definition, while the prepare-time font
 * declaration check collects it and fails the build. Neither decision lives
 * here — a problem knows what is wrong and how much it costs, and nothing about
 * who asked.
 *
 * A problem always names both the declaring extension and the font key as the
 * YAML spells it, because the two questions an author has are "whose file?" and
 * "which entry?", and a message that answers neither costs them a grep. The
 * message is carried as a template beside its placeholder values rather than
 * pre-rendered, so a logger records the two separately.
 *
 * @see \Drupal\neo_font\FontDeclarationSeverity
 * @see \Drupal\neo_font\FontPluginManager::findDeclarationProblems()
 */
final class FontDeclarationProblem {

  /**
   * Constructs a font declaration problem.
   *
   * Private: a problem is built through the severity-named constructors, so
   * that choosing a severity is a deliberate act at every call site rather than
   * an argument that can be defaulted or copied from the line above.
   *
   * @param string $extension
   *   The extension whose file declared the font.
   * @param string $font
   *   The font key as the YAML spells it, underscores and all.
   * @param string $message
   *   The message template, carrying placeholders for $context.
   * @param array<string, mixed> $context
   *   The placeholder values for the message.
   * @param \Drupal\neo_font\FontDeclarationSeverity $severity
   *   What the problem costs the font.
   */
  private function __construct(
    public readonly string $extension,
    public readonly string $font,
    public readonly string $message,
    public readonly array $context,
    public readonly FontDeclarationSeverity $severity,
  ) {}

  /**
   * A problem the font cannot survive.
   *
   * @param string $extension
   *   The extension whose file declared the font.
   * @param string $font
   *   The font key as the YAML spells it.
   * @param string $reason
   *   What is wrong, as a sentence completing "The font X declared by Y …",
   *   optionally carrying placeholders of its own.
   * @param array<string, mixed> $context
   *   Placeholder values for the reason's own placeholders.
   *
   * @return self
   *   The problem.
   */
  public static function refusal(string $extension, string $font, string $reason, array $context = []): self {
    return self::create($extension, $font, $reason, $context, FontDeclarationSeverity::Refusal);
  }

  /**
   * A problem the font survives.
   *
   * @param string $extension
   *   The extension whose file declared the font.
   * @param string $font
   *   The font key as the YAML spells it.
   * @param string $reason
   *   What is wrong, as a sentence completing "The font X declared by Y …",
   *   optionally carrying placeholders of its own.
   * @param array<string, mixed> $context
   *   Placeholder values for the reason's own placeholders.
   *
   * @return self
   *   The problem.
   */
  public static function report(string $extension, string $font, string $reason, array $context = []): self {
    return self::create($extension, $font, $reason, $context, FontDeclarationSeverity::Report);
  }

  /**
   * Builds a problem, naming the extension and the font in its message.
   *
   * @param string $extension
   *   The extension whose file declared the font.
   * @param string $font
   *   The font key as the YAML spells it.
   * @param string $reason
   *   What is wrong, as a sentence completing "The font X declared by Y …".
   * @param array<string, mixed> $context
   *   Placeholder values for the reason's own placeholders.
   * @param \Drupal\neo_font\FontDeclarationSeverity $severity
   *   What the problem costs the font.
   *
   * @return self
   *   The problem.
   */
  private static function create(string $extension, string $font, string $reason, array $context, FontDeclarationSeverity $severity): self {
    return new self(
      $extension,
      $font,
      'The font %font declared by %extension ' . $reason,
      $context + [
        '%font' => $font,
        '%extension' => $extension,
      ],
      $severity,
    );
  }

  /**
   * Whether this problem means no font can be built from the declaration.
   *
   * @return bool
   *   TRUE if the problem is a refusal.
   */
  public function isRefusal(): bool {
    return $this->severity === FontDeclarationSeverity::Refusal;
  }

  /**
   * Whether any problem in a list is a refusal.
   *
   * @param list<self> $problems
   *   The problems to look through.
   *
   * @return bool
   *   TRUE if at least one of them is a refusal.
   */
  public static function hasRefusal(array $problems): bool {
    foreach ($problems as $problem) {
      if ($problem->isRefusal()) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * The message with its placeholders substituted in.
   *
   * For a caller that has to put the problem into a string rather than into a
   * logger — the prepare-time refusal, which lists every problem it found in
   * one exception message.
   *
   * @return string
   *   The rendered message.
   */
  public function render(): string {
    $replacements = [];
    foreach ($this->context as $placeholder => $value) {
      if (is_scalar($value) || $value instanceof \Stringable) {
        $replacements[$placeholder] = (string) $value;
      }
    }
    return strtr($this->message, $replacements);
  }

}
