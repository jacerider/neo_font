<?php

declare(strict_types=1);

namespace Drupal\neo_font;

/**
 * Resolves which declared font serves each font role.
 *
 * @see \Drupal\neo_font\FontRoleResolver
 */
interface FontRoleResolverInterface {

  /**
   * Returns every declared font, instantiated.
   *
   * Keyed by plugin id rather than by font selector on purpose: two fonts may
   * declare the same selector, and both must still be returned.
   *
   * @return array<string, \Drupal\neo_font\FontInterface>
   *   One font instance per discovered definition, keyed by plugin id, in the
   *   order discovery returns them.
   */
  public function getFonts(): array;

  /**
   * Returns the font serving each font role.
   *
   * A role whose configured font id matches no discovered font is absent from
   * the map rather than present and empty.
   *
   * @return array<string, \Drupal\neo_font\FontInterface>
   *   The resolved font, keyed by font role.
   */
  public function getRoleMap(): array;

}
