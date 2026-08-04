<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\DpressServices;
use Dynart\Micro\Entities\MigrationInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Dynart\Dpress\DpressServices
 */
class DpressServicesTest extends TestCase {

    public function testEveryCoreMigrationClassExists(): void {
        foreach (DpressServices::MIGRATIONS as $className) {
            $this->assertTrue(class_exists($className), "$className does not exist");
        }
    }

    public function testEveryCoreMigrationImplementsTheInterface(): void {
        foreach (DpressServices::MIGRATIONS as $className) {
            $this->assertTrue(
                is_subclass_of($className, MigrationInterface::class),
                "$className does not implement MigrationInterface"
            );
        }
    }

    /**
     * The runner sorts by version, so duplicates would silently make the order undefined.
     */
    public function testCoreMigrationVersionsAreUnique(): void {
        $versions = [];
        foreach (DpressServices::MIGRATIONS as $className) {
            $version = (new \ReflectionClass($className))->newInstanceWithoutConstructor()->version();
            $this->assertNotContains($version, $versions, "duplicate migration version $version");
            $versions[] = $version;
        }
    }

    public function testCoreMigrationVersionsAreNotEmpty(): void {
        foreach (DpressServices::MIGRATIONS as $className) {
            $version = (new \ReflectionClass($className))->newInstanceWithoutConstructor()->version();
            $this->assertNotSame('', $version, "$className has an empty version");
        }
    }
}
