<?php

declare(strict_types=1);

namespace Drupal\neo_font\EventSubscriber;

use Drupal\neo_build\Event\NeoBuildEvent;
use Drupal\neo_font\FontRoleResolverInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Adds the declared fonts to a build's Tailwind theme.
 *
 * A consumer of the role resolver and nothing else: which font serves which
 * role is the resolver's rule, so this file only decides how the answer is
 * shaped for Tailwind.
 */
class NeoBuildEventSubscriber implements EventSubscriberInterface {

  /**
   * Constructs a new NeoBuildEventSubscriber object.
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
   * Every per-font entry is written before any per-role entry. Order is only
   * observable when a font's selector equals a role name, and roles last is
   * what makes the admin setting win.
   *
   * @param \Drupal\neo_build\Event\NeoBuildEvent $event
   *   The neo build event.
   */
  public function onBuild(NeoBuildEvent $event): void {
    /** @var array{fontFamily?: array<string, string|array<int, string>>} $theme */
    $theme = [];
    foreach ($this->roleResolver->getFonts() as $font) {
      $theme['fontFamily'][$font->getSelector()] = explode(', ', $font->getPropertyValue());
    }
    foreach (array_keys($this->roleResolver->getRoleMap()) as $role) {
      $theme['fontFamily'][$role] = 'var(--font-' . $role . '-family)';
    }
    $event->getCollection()->addTailwindTheme($theme);
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, string>
   *   The event names this subscriber listens to, and their handlers.
   */
  public static function getSubscribedEvents(): array {
    return [
      NeoBuildEvent::EVENT_NAME => 'onBuild',
    ];
  }

}
