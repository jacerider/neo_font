<?php

declare(strict_types=1);

namespace Drupal\neo_font;

use Psr\Log\LogLevel;

/**
 * How much a font declaration problem costs the font it was found on.
 *
 * The split is a property of the check, not of the stage that runs it: a
 * refusal is dropped at discovery and fatal at prepare, a report is logged at
 * discovery and prepare succeeds. One rule decides which a check is — can a
 * font be built from the declaration at all? If it cannot, the declaration is
 * refused; if it can, and only the author's expectation is wrong, it is
 * reported.
 *
 * @see \Drupal\neo_font\FontDeclarationProblem
 */
enum FontDeclarationSeverity: string {

  // A problem the font cannot survive: no font can be built from it. Logged at
  // error and dropped at discovery, fatal at prepare.
  case Refusal = 'refusal';

  // A problem the font survives: it is built correctly, and only the author's
  // expectation is wrong. Logged at warning, and prepare succeeds.
  case Report = 'report';

  /**
   * The log level a problem of this severity is written at.
   *
   * The severity split is only worth having if an operator can filter on it,
   * which means it has to reach the log as a level rather than as wording.
   *
   * @return string
   *   A PSR-3 log level.
   */
  public function logLevel(): string {
    return match ($this) {
      self::Refusal => LogLevel::ERROR,
      self::Report => LogLevel::WARNING,
    };
  }

}
