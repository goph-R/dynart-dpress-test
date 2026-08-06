<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\DpressServices;
use Dynart\Dpress\Migration\CreateSchema;
use Dynart\Micro\Entities\Attribute\Auditable;
use Dynart\Micro\Entities\MigrationInterface;
use Dynart\Micro\Entities\Revision;
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

    // --- the schema and the entities have to agree ---

    /**
     * Every registered entity gets a table
     *
     * While the schema was eight migrations, each one listed the group it created and the pairing
     * was obvious from the file you were editing. Squashed into one, it is possible to add an
     * entity to `ENTITIES`, have it register happily, and only find out that nothing ever created
     * its table when a query fails on a site somebody has already installed.
     */
    public function testEveryEntityHasATableInTheSchema(): void {
        foreach (DpressServices::ENTITIES as $className) {
            $this->assertContains(
                $className, CreateSchema::TABLES,
                $className.' is registered but CreateSchema never builds its table'
            );
        }
    }

    /**
     * And nothing gets a table that is not an entity of ours - `Revision` excepted, which comes
     * from the library and is registered by the migration itself
     */
    public function testTheSchemaBuildsNothingUnknown(): void {
        $known = array_merge(DpressServices::ENTITIES, [Revision::class]);
        foreach (CreateSchema::TABLES as $className) {
            $this->assertContains($className, $known, $className.' gets a table but is not a registered entity');
        }
    }

    /**
     * `Revision` has to be first
     *
     * Every `_aud` mirror carries a foreign key into it, so building one before it exists fails
     * halfway through, on a database that is then neither empty nor usable.
     */
    public function testTheRevisionTableIsBuiltFirst(): void {
        $this->assertSame(Revision::class, CreateSchema::TABLES[0]);
    }

    /**
     * A child table cannot be built before the one it points at
     *
     * The order used to be spread over six migrations whose *names* carried it - identity, then
     * media, then content, then taxonomy. In one list it is just an array, and an array is easy
     * to tidy alphabetically one afternoon and break the install with.
     */
    public function testForeignKeysComeAfterWhatTheyPointAt(): void {
        $order = array_flip(CreateSchema::TABLES);
        foreach (self::PARENTS as $child => $parents) {
            foreach ($parents as $parent) {
                $this->assertGreaterThan(
                    $order[$parent], $order[$child],
                    $child.' is built before '.$parent.', which it has a foreign key into'
                );
            }
        }
    }

    const PARENTS = [
        \Dynart\Dpress\Entity\UserRole::class          => [\Dynart\Dpress\Entity\User::class, \Dynart\Dpress\Entity\Role::class],
        \Dynart\Dpress\Entity\RolePermission::class    => [\Dynart\Dpress\Entity\Role::class],
        \Dynart\Dpress\Entity\RefreshToken::class      => [\Dynart\Dpress\Entity\User::class],
        \Dynart\Dpress\Entity\UserToken::class         => [\Dynart\Dpress\Entity\User::class],
        \Dynart\Dpress\Entity\ContentCategory::class   => [\Dynart\Dpress\Entity\Content::class, \Dynart\Dpress\Entity\Category::class],
        \Dynart\Dpress\Entity\ContentTag::class        => [\Dynart\Dpress\Entity\Content::class, \Dynart\Dpress\Entity\Tag::class],
        \Dynart\Dpress\Entity\ContentAttachment::class => [\Dynart\Dpress\Entity\Content::class, \Dynart\Dpress\Entity\Media::class],
        \Dynart\Dpress\Entity\MenuItem::class          => [\Dynart\Dpress\Entity\Menu::class],
    ];
}
