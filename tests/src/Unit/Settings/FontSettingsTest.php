<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_font\Unit\Settings;

use Drupal\Component\DependencyInjection\ReverseContainer;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\neo_font\FontPluginManagerInterface;
use Drupal\neo_font\Settings\FontSettings;
use Drupal\neo_settings\Plugin\SettingsBase;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests how the font settings plugin receives the font plugin manager.
 *
 * Nothing here exercises the settings form. The subject is the seam by which
 * the plugin takes the font plugin manager: the plugin must be constructible
 * from the base settings plugin's own five-argument signature, and the manager
 * must survive a serialize/unserialize round trip, which it can only do if
 * `DependencySerializationTrait` can see the property holding it.
 *
 * That trait's `__sleep()` is compiled into `SettingsBase`, so its
 * `get_object_vars()` runs in *that* class's scope. A private property on the
 * subclass is invisible from there: it is never recorded as a service id and
 * never restored. Two of these tests therefore read the plugin from
 * `SettingsBase`'s scope rather than from the test's, because that is the only
 * scope whose answer the trait acts on.
 *
 * @see https://www.drupal.org/node/3110266
 */
#[Group('neo_font')]
final class FontSettingsTest extends UnitTestCase {

  /**
   * The plugin id the settings plugin is built under.
   */
  private const PLUGIN_ID = 'neo_font';

  /**
   * The service id the font plugin manager is registered under.
   */
  private const MANAGER_SERVICE_ID = 'plugin.manager.neo_font';

  /**
   * The font plugin manager the plugin under test is given.
   */
  private FontPluginManagerInterface $manager;

  /**
   * The container every test builds the plugin against.
   */
  private ContainerBuilder $container;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->manager = $this->createMock(FontPluginManagerInterface::class);

    // Every injected service has to be registered, or the serialization trait
    // finds no service id for it and serializes the PHPUnit mock instead.
    $this->container = new ContainerBuilder();
    $this->container->set(self::MANAGER_SERVICE_ID, $this->manager);
    $this->container->set('messenger', $this->createMock(MessengerInterface::class));
    $this->container->set('form_builder', $this->createMock(FormBuilderInterface::class));
    // Built over the same container, because that is what turns a service
    // object back into a service id during __sleep().
    $this->container->set(ReverseContainer::class, new ReverseContainer($this->container));
    \Drupal::setContainer($this->container);
  }

  /**
   * The plugin constructs from the base settings plugin's own signature.
   *
   * `SettingsBase` is annotated `@phpstan-consistent-constructor` and its
   * `create()` really does build `new static()` with five arguments, so any
   * construction that does not go through this subclass's own `create()` has
   * only those five to offer.
   */
  public function testConstructsFromTheBaseFiveArgumentSignature(): void {
    $plugin = new FontSettings(
      $this->configuration(),
      self::PLUGIN_ID,
      $this->pluginDefinition(),
      $this->container->get('messenger'),
      $this->container->get('form_builder'),
    );

    $this->assertInstanceOf(SettingsBase::class, $plugin);
    $this->assertSame(self::PLUGIN_ID, $plugin->getPluginId());
  }

  /**
   * Building through `create()` leaves the manager visible to the base class.
   *
   * Read from `SettingsBase`'s scope on purpose: that is where the
   * serialization trait's `get_object_vars()` runs, so a manager the base class
   * cannot see is a manager nothing downstream of `__sleep()` can act on.
   */
  public function testCreateLeavesTheManagerVisibleToTheBaseClass(): void {
    $plugin = FontSettings::create(
      $this->container,
      $this->configuration(),
      self::PLUGIN_ID,
      $this->pluginDefinition(),
    );

    $properties = self::propertiesVisibleToTheBaseClass($plugin);

    $this->assertArrayHasKey('fontManager', $properties);
    $this->assertSame($this->manager, $properties['fontManager']);
  }

  /**
   * Serializing records the manager's service id instead of the manager.
   *
   * `__sleep()` writes the id onto the object it is serializing, so the
   * recording is read back off the original rather than off the copy — the copy
   * clears `_serviceIds` again in `__wakeup()`.
   */
  public function testSerializingRecordsTheManagerByServiceId(): void {
    $plugin = FontSettings::create(
      $this->container,
      $this->configuration(),
      self::PLUGIN_ID,
      $this->pluginDefinition(),
    );

    $serialized = serialize($plugin);
    $service_ids = self::propertiesVisibleToTheBaseClass($plugin)['_serviceIds'];

    $this->assertArrayHasKey('fontManager', $service_ids);
    $this->assertSame(self::MANAGER_SERVICE_ID, $service_ids['fontManager']);
    $this->assertStringNotContainsString(get_class($this->manager), $serialized);
  }

  /**
   * The manager comes back from the container after a round trip.
   *
   * This is the assertion that separates the repair from the bug: a typed
   * property that `__wakeup()` never restores is *uninitialized*, not NULL, so
   * the next read of it is a fatal `Error` rather than a soft failure.
   */
  public function testTheManagerIsRestoredAfterSerializeRoundTrip(): void {
    $plugin = FontSettings::create(
      $this->container,
      $this->configuration(),
      self::PLUGIN_ID,
      $this->pluginDefinition(),
    );

    $restored = unserialize(serialize($plugin), ['allowed_classes' => [FontSettings::class]]);
    $this->assertInstanceOf(FontSettings::class, $restored);

    $property = new \ReflectionProperty(FontSettings::class, 'fontManager');
    $this->assertTrue(
      $property->isInitialized($restored),
      'The font plugin manager is uninitialized after the round trip, so the next read of it would be a fatal Error.',
    );
    $this->assertSame($this->manager, $property->getValue($restored));
  }

  /**
   * Reads a plugin's properties from the base settings plugin's scope.
   *
   * `DependencySerializationTrait::__sleep()` is compiled into `SettingsBase`,
   * so this closure sees exactly what the trait sees — which is the whole point
   * of asking from here rather than with reflection.
   *
   * @param \Drupal\neo_settings\Plugin\SettingsBase $plugin
   *   The plugin to read.
   *
   * @return array<string, mixed>
   *   The properties visible from `SettingsBase`'s scope.
   */
  private static function propertiesVisibleToTheBaseClass(SettingsBase $plugin): array {
    $read = \Closure::bind(
      static fn (SettingsBase $subject): array => get_object_vars($subject),
      NULL,
      SettingsBase::class,
    );

    return $read($plugin);
  }

  /**
   * The configuration array the base settings plugin reads.
   *
   * Its constructor takes `config`, `variation` and `variation_id` out of this
   * array and nothing else.
   *
   * @return array<string, mixed>
   *   The plugin configuration.
   */
  private function configuration(): array {
    return [
      'config' => [],
      'variation' => [],
      'variation_id' => NULL,
    ];
  }

  /**
   * The plugin definition the base settings plugin reads.
   *
   * Its constructor takes `configuration` out of the definition and nothing
   * else.
   *
   * @return array<string, mixed>
   *   The plugin definition.
   */
  private function pluginDefinition(): array {
    return ['configuration' => []];
  }

}
