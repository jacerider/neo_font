<?php

declare(strict_types=1);

namespace Drupal\neo_font\EventSubscriber;

use Drupal\neo_build\Event\NeoBuildInlineEvent;
use Drupal\neo_font\FontRoleResolverInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Adds the declared fonts to a build's inline CSS.
 *
 * A consumer of the role resolver and nothing else: which font serves which
 * role is the resolver's rule, so this file only decides how the answer is
 * shaped as CSS.
 */
class NeoBuildInlineEventSubscriber implements EventSubscriberInterface {

  /**
   * Constructs a new NeoBuildInlineEventSubscriber object.
   *
   * @param \Drupal\neo_font\FontRoleResolverInterface $roleResolver
   *   The font role resolver.
   */
  public function __construct(
    private readonly FontRoleResolverInterface $roleResolver,
  ) {}

  /**
   * Subscribe to the Neo build event dispatched.
   *
   * We inject the CSS variables directly into the DOM so that we do not need
   * to wait for the build to complete before the CSS is applied.
   *
   * Every per-font entry — the selector utility and the font's faces — is
   * written before any per-role variable. Every font emits its faces whether
   * or not it serves a role.
   *
   * @param \Drupal\neo_build\Event\NeoBuildInlineEvent $event
   *   The neo build dev event.
   */
  public function onInlineBuild(NeoBuildInlineEvent $event): void {
    foreach ($this->roleResolver->getFonts() as $font) {
      $event->addCssValue('font-family', $font->getPropertyValue(), '.font-' . $font->getSelector());
      foreach ($font->getFontFaces() as $face) {
        $event->addCssValue($face['src'], $face, '@font-face');
      }
    }
    foreach ($this->roleResolver->getRoleMap() as $role => $font) {
      $event->addCssValue('--font-' . $role . '-family', $font->getPropertyValue());
    }
    $event->addCacheTags(['config:neo_font.settings']);
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, string>
   *   The event names this subscriber listens to, and their handlers.
   */
  public static function getSubscribedEvents(): array {
    return [
      NeoBuildInlineEvent::EVENT_NAME => 'onInlineBuild',
    ];
  }

}
