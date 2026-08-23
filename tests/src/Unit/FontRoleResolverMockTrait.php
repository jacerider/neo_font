<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_font\Unit;

use Drupal\neo_font\FontInterface;
use Drupal\neo_font\FontRoleResolverInterface;

/**
 * Builds the mocked role resolver both build subscriber tests consume.
 *
 * The subscribers' only dependency is the resolver, so a mock of it is the
 * whole fixture: no container, no plugin manager, no settings repository and
 * no database is involved in reaching either handler.
 */
trait FontRoleResolverMockTrait {

  /**
   * Builds a resolver returning the given fonts and role map.
   *
   * @param array<string, array{selector: string, property: string, faces?: array<string, array<string, string>>}> $fonts
   *   The fonts to declare, keyed by plugin id — as the resolver keys them.
   * @param array<string, string> $roleMap
   *   The plugin id serving each role, keyed by role.
   *
   * @return \Drupal\neo_font\FontRoleResolverInterface
   *   The mocked resolver.
   */
  private function mockRoleResolver(array $fonts, array $roleMap): FontRoleResolverInterface {
    $instances = [];
    foreach ($fonts as $plugin_id => $font) {
      $instance = $this->createMock(FontInterface::class);
      $instance->method('getSelector')->willReturn($font['selector']);
      $instance->method('getPropertyValue')->willReturn($font['property']);
      $instance->method('getFontFaces')->willReturn($font['faces'] ?? []);
      $instances[$plugin_id] = $instance;
    }

    $resolved = [];
    foreach ($roleMap as $role => $plugin_id) {
      $resolved[$role] = $instances[$plugin_id];
    }

    $resolver = $this->createMock(FontRoleResolverInterface::class);
    $resolver->method('getFonts')->willReturn($instances);
    $resolver->method('getRoleMap')->willReturn($resolved);
    return $resolver;
  }

  /**
   * Builds a resolver that records every question asked of it.
   *
   * @return array{0: \Drupal\neo_font\FontRoleResolverInterface, 1: \ArrayObject<int, string>}
   *   The resolver, and the recorder its calls append to.
   */
  private function recordingRoleResolver(): array {
    /** @var \ArrayObject<int, string> $calls */
    $calls = new \ArrayObject();
    $resolver = $this->createMock(FontRoleResolverInterface::class);
    $resolver->method('getFonts')->willReturnCallback(
      static function () use ($calls): array {
        $calls[] = 'getFonts';
        return [];
      }
    );
    $resolver->method('getRoleMap')->willReturnCallback(
      static function () use ($calls): array {
        $calls[] = 'getRoleMap';
        return [];
      }
    );
    return [$resolver, $calls];
  }

  /**
   * Asserts a subscriber declares the resolver as its only dependency.
   *
   * @param class-string $class
   *   The subscriber class to inspect.
   */
  private function assertConstructorTakesTheResolverAlone(string $class): void {
    $reflection = new \ReflectionClass($class);
    $constructor = $reflection->getConstructor();
    $this->assertNotNull($constructor);

    $types = [];
    foreach ($constructor->getParameters() as $parameter) {
      $type = $parameter->getType();
      $types[$parameter->getName()] = $type instanceof \ReflectionNamedType ? $type->getName() : NULL;
    }
    $this->assertSame(
      [FontRoleResolverInterface::class],
      array_values($types),
      $class . ' injects the role resolver and nothing else.'
    );

    $this->assertFalse(
      $reflection->hasProperty('settings'),
      $class . ' holds no settings snapshot of its own.'
    );
  }

}
