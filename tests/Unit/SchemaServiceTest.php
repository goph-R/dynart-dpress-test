<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Service\SchemaService;
use Dynart\Dpress\Test\StubConfig;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * `isConfigured()` runs before anything connects, so it can be exercised without a database.
 *
 * @covers \Dynart\Dpress\Service\SchemaService
 */
class SchemaServiceTest extends TestCase {

    private function schemaWithConfig(array $values): SchemaService {
        $service = (new ReflectionClass(SchemaService::class))->newInstanceWithoutConstructor();
        $property = new \ReflectionProperty(SchemaService::class, 'config');
        $property->setAccessible(true);
        $property->setValue($service, new StubConfig($values));
        return $service;
    }

    public function testIsConfiguredWithDsnAndName(): void {
        $schema = $this->schemaWithConfig([
            'database.default.dsn'  => 'mysql:host=localhost',
            'database.default.name' => 'dpress',
        ]);
        $this->assertTrue($schema->isConfigured());
    }

    public function testIsNotConfiguredWithoutAnything(): void {
        $this->assertFalse($this->schemaWithConfig([])->isConfigured());
    }

    public function testIsNotConfiguredWithoutTheDsn(): void {
        $schema = $this->schemaWithConfig(['database.default.name' => 'dpress']);
        $this->assertFalse($schema->isConfigured());
    }

    /**
     * A missing database name is the easy half of the config to forget, and without this check
     * it surfaces as a PDO exception rather than as a sentence.
     */
    public function testIsNotConfiguredWithoutTheName(): void {
        $schema = $this->schemaWithConfig(['database.default.dsn' => 'mysql:host=localhost']);
        $this->assertFalse($schema->isConfigured());
    }

    public function testIsNotConfiguredWithEmptyValues(): void {
        $schema = $this->schemaWithConfig([
            'database.default.dsn'  => '',
            'database.default.name' => '',
        ]);
        $this->assertFalse($schema->isConfigured());
    }

    public function testDatabaseName(): void {
        $schema = $this->schemaWithConfig(['database.default.name' => 'mysite']);
        $this->assertSame('mysite', $schema->databaseName());
    }

    public function testDatabaseNameIsAStringWhenMissing(): void {
        $this->assertSame('', $this->schemaWithConfig([])->databaseName());
    }
}
