<?php

declare(strict_types=1);

namespace Drupal\neo_font\EventSubscriber;

use Drupal\neo_build\Event\NeoBuildEvent;
use Drupal\neo_font\FontPluginManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Refuses a build carrying a font declaration that cannot produce a font.
 *
 * The author-facing half of what this module does with a bad declaration.
 * Discovery no longer refuses one — it logs it and drops the font, so a
 * mistyped face path costs a font rather than every page on the site — and this
 * is where the same finding is made loud again, at the console of the author
 * who caused it. Adding or editing a font is a build-time act: the
 * `.font-{selector}` utility only exists once prepare has written it into the
 * generated stylesheet, so prepare is where the author is already standing.
 *
 * Deliberately a separate class from the two subscribers that emit. The
 * `neo-font-role-resolver` plan decided those inject the role resolver and
 * nothing else, and folding a check that needs the plugin manager into one of
 * them would undo that. It runs ahead of them so a refusal happens before
 * anything reaches the collection.
 *
 * It throws `\InvalidArgumentException` rather than a plugin exception: this is
 * no longer a plugin-system error, it is the shape prepare already refuses
 * declarations with, and it is what the build CLI reports as a failed fatal
 * step.
 *
 * @see \Drupal\neo_font\FontPluginManagerInterface::checkDeclarations()
 */
final class NeoBuildDeclarationEventSubscriber implements EventSubscriberInterface {

  /**
   * The priority that puts this subscriber ahead of the ones that emit.
   *
   * A refusal has to happen before anything a refused declaration could have
   * contributed reaches the collection, and the emitting subscribers take the
   * default priority.
   */
  private const PRIORITY = 1000;

  /**
   * Constructs a new NeoBuildDeclarationEventSubscriber object.
   *
   * @param \Drupal\neo_font\FontPluginManagerInterface $fontPluginManager
   *   The font plugin manager, which owns the declaration check. The only
   *   thing this subscriber holds: it decides what a refusal does to a build,
   *   and nothing about what is wrong with a file.
   */
  public function __construct(
    private readonly FontPluginManagerInterface $fontPluginManager,
  ) {}

  /**
   * Refuses the build if any font declaration cannot produce a font.
   *
   * Every refusal is listed, not the first, because a theme that got three face
   * paths wrong should learn all three in one build rather than one per run.
   * A report-severity problem is not raised here at all: the font is built
   * correctly, the problem was already logged at discovery, and the prepare
   * result is `neo_build`'s and carries no severity by design.
   *
   * @param \Drupal\neo_build\Event\NeoBuildEvent $event
   *   The neo build event.
   *
   * @throws \InvalidArgumentException
   *   If any declared font carries a font declaration refusal.
   */
  public function onBuild(NeoBuildEvent $event): void {
    $refusals = [];
    foreach ($this->fontPluginManager->checkDeclarations() as $problem) {
      if ($problem->isRefusal()) {
        $refusals[] = $problem->render();
      }
    }

    if (!$refusals) {
      return;
    }

    throw new \InvalidArgumentException(sprintf(
      "%d font %s cannot be built, so the build is refused. Fix or remove %s:\n- %s",
      count($refusals),
      count($refusals) === 1 ? 'declaration' : 'declarations',
      count($refusals) === 1 ? 'it' : 'each one',
      implode("\n- ", $refusals),
    ));
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, array{0: string, 1: int}>
   *   The event names this subscriber listens to, their handlers and the
   *   priority that puts the refusal ahead of every emission.
   */
  public static function getSubscribedEvents(): array {
    return [
      NeoBuildEvent::EVENT_NAME => ['onBuild', self::PRIORITY],
    ];
  }

}
