<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_font\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\neo_font\FontPluginManager;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;

/**
 * Tests that the font plugin manager declares only what it actually uses.
 *
 * Almost nothing behavioural is asserted here, because almost nothing
 * behavioural is on the line: the manager never read the file system, the file
 * URL generator or the `public://neo-fonts` directory it used to declare, so
 * removing them changes no check, no derivation and no emission. What is on the
 * line is shape, and shape is what these assertions are about. The one call
 * that is made is there to prove the constructed object still answers at all.
 *
 * The service-definition assertion is the one that earns the test class. A
 * constructor edit that forgets the service file — or a service-file edit that
 * forgets the constructor — leaves two files each of which is correct read on
 * its own, so neither phpcs nor PHPStan can see the disagreement; the container
 * discovers it by shifting every remaining argument one position along the
 * parameter list and raising a type error on the next request. That failure is
 * invisible until a page is rendered, which is exactly the kind a test should
 * be holding. It also keeps guarding as the constructor grows: it counts what
 * the constructor declares rather than a number written down here, so a later
 * argument added to both places passes and one added to only one place fails.
 *
 * Reflection over the class and a parse of the module's service file are all
 * this needs — no container, no database, no fixture module.
 */
#[Group('neo_font')]
final class FontPluginManagerDependenciesTest extends UnitTestCase {

  /**
   * The manager's service id in the module's service file.
   */
  private const SERVICE_ID = 'plugin.manager.neo_font';

  /**
   * The service references the manager no longer takes.
   *
   * Spelled as the service file spells them, because the service file is where
   * the second assertion looks for them.
   */
  private const REMOVED_SERVICES = ['@file_system', '@file_url_generator'];

  /**
   * The parameter types the constructor no longer declares.
   *
   * Written as strings rather than imported, so that this test file names the
   * two interfaces exactly once each and never depends on them.
   */
  private const REMOVED_TYPES = [
    'Drupal\\Core\\File\\FileSystemInterface',
    'Drupal\\Core\\File\\FileUrlGeneratorInterface',
  ];

  /**
   * Source fragments that would mean a removed dependency came back.
   *
   * Every one names a file system, a file URL generator or the fonts directory
   * specifically. Bare words like "directory" are deliberately absent: the
   * class documents, in prose that has nothing to do with this ticket, that a
   * local font's provider resolves to an extension directory.
   */
  private const REMOVED_REFERENCES = [
    'FileSystemInterface',
    'FileUrlGeneratorInterface',
    'fileSystem',
    'fileUrlGenerator',
    'file_system',
    'file_url_generator',
    'public://neo-fonts',
  ];

  /**
   * The manager constructs from the collaborators it actually reads.
   */
  public function testConstructsWithoutTheFileSystemOrTheFileUrlGenerator(): void {
    $manager = new FontPluginManager(
      '/var/www/html/web',
      $this->createMock(ModuleHandlerInterface::class),
      $this->createMock(ThemeHandlerInterface::class),
      $this->createMock(CacheBackendInterface::class),
      new NullLogger(),
    );
    $manager->setStringTranslation($this->getStringTranslationStub());

    // A manager that constructed is a manager that answers, which is the whole
    // claim: neither removed service was ever consulted by anything it does.
    $this->assertSame(['local', 'google', 'generic'], array_keys($manager->getSupportedTypes()));

    // And the signature itself, so that re-injecting either service fails here
    // rather than only wherever it was eventually read.
    $types = $this->constructorParameterTypes();
    foreach (self::REMOVED_TYPES as $type) {
      $this->assertNotContains($type, $types, sprintf(
        'The font plugin manager constructor still declares a %s parameter.',
        $type
      ));
    }
  }

  /**
   * The types the manager's constructor parameters declare.
   *
   * @return list<string>
   *   One entry per parameter declaring a named type, in declaration order.
   */
  private function constructorParameterTypes(): array {
    $constructor = (new \ReflectionClass(FontPluginManager::class))->getConstructor();
    $this->assertNotNull($constructor);

    $types = [];
    foreach ($constructor->getParameters() as $parameter) {
      $type = $parameter->getType();
      if ($type instanceof \ReflectionNamedType) {
        $types[] = $type->getName();
      }
    }

    return $types;
  }

  /**
   * Nothing in the class names a file system, a URL generator or a directory.
   */
  public function testTheClassNamesNoFileSystemFileUrlGeneratorOrFontsDirectory(): void {
    $source = file_get_contents($this->classFile());
    $this->assertIsString($source);

    foreach (self::REMOVED_REFERENCES as $needle) {
      $this->assertStringNotContainsString($needle, $source, sprintf(
        'The font plugin manager still names "%s".',
        $needle
      ));
    }
  }

  /**
   * The service definition passes exactly what the constructor declares.
   */
  public function testTheServiceDefinitionMatchesTheConstructor(): void {
    $arguments = $this->serviceArguments();
    $constructor = (new \ReflectionClass(FontPluginManager::class))->getConstructor();
    $this->assertNotNull($constructor);

    $this->assertCount($constructor->getNumberOfParameters(), $arguments, sprintf(
      'The %s definition passes %d arguments to a constructor declaring %d parameters.',
      self::SERVICE_ID,
      count($arguments),
      $constructor->getNumberOfParameters()
    ));

    foreach (self::REMOVED_SERVICES as $service) {
      $this->assertNotContains($service, $arguments, sprintf(
        'The %s definition still passes %s.',
        self::SERVICE_ID,
        $service
      ));
    }
  }

  /**
   * The arguments the module's service file passes to the manager.
   *
   * @return list<mixed>
   *   The argument list, in the order the service file declares it.
   */
  private function serviceArguments(): array {
    $file = dirname($this->classFile(), 2) . '/neo_font.services.yml';
    $this->assertFileExists($file);

    $contents = file_get_contents($file);
    $this->assertIsString($contents);

    $services = Yaml::decode($contents)['services'] ?? [];
    $this->assertArrayHasKey(self::SERVICE_ID, $services);
    $this->assertIsArray($services[self::SERVICE_ID]);
    $this->assertArrayHasKey('arguments', $services[self::SERVICE_ID]);

    $arguments = $services[self::SERVICE_ID]['arguments'];
    $this->assertIsArray($arguments);

    return array_values($arguments);
  }

  /**
   * The file the manager class is defined in.
   *
   * @return string
   *   The absolute path to the class file.
   */
  private function classFile(): string {
    $file = (new \ReflectionClass(FontPluginManager::class))->getFileName();
    $this->assertIsString($file);

    return $file;
  }

}
