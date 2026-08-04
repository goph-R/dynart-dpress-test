<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\DpressServices;
use Dynart\Dpress\Entity\RefreshToken;
use Dynart\Dpress\Entity\Role;
use Dynart\Dpress\Entity\RolePermission;
use Dynart\Dpress\Entity\User;
use Dynart\Dpress\Entity\UserRole;
use Dynart\Dpress\Entity\UserToken;
use Dynart\Micro\Entities\Attribute\Auditable;
use Dynart\Micro\Entities\Entity;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pins the auditing decisions from the plan, which are easy to undo by accident
 */
class IdentityEntitiesTest extends TestCase {

    private function isAuditable(string $className): bool {
        return (new ReflectionClass($className))->getAttributes(Auditable::class) !== [];
    }

    /**
     * Granting a role changes no row in `user`, so without its own mirror the change would leave
     * no trace at all. Same for a permission on a role.
     */
    public function testTheIdentityRecordsAndRelationsAreAudited(): void {
        foreach ([User::class, Role::class, UserRole::class, RolePermission::class] as $className) {
            $this->assertTrue($this->isAuditable($className), "$className should be auditable");
        }
    }

    /**
     * These hold credentials and are short lived by design. Auditing them would copy secrets
     * into a table that is never deleted from.
     */
    public function testTheTokenTablesAreNotAudited(): void {
        foreach ([RefreshToken::class, UserToken::class] as $className) {
            $this->assertFalse($this->isAuditable($className), "$className must not be auditable");
        }
    }

    public function testEveryIdentityEntityHasAShortEventAlias(): void {
        foreach (DpressServices::ENTITIES as $className) {
            $namespace = $className::eventNamespace();
            $this->assertStringStartsWith(Entity::EVENT_NAMESPACE_PREFIX.'.', $namespace);
            $this->assertStringNotContainsString(
                'dpress',
                $namespace,
                "$className falls back to the class name, declare a \$eventName alias"
            );
        }
    }

    public function testEventNamespacesAreUnique(): void {
        $namespaces = [];
        foreach (DpressServices::ENTITIES as $className) {
            $namespaces[] = $className::eventNamespace();
        }
        $this->assertSame(count($namespaces), count(array_unique($namespaces)));
    }

    public function testEveryRegisteredEntityIsAnEntity(): void {
        foreach (DpressServices::ENTITIES as $className) {
            $this->assertTrue(is_subclass_of($className, Entity::class), "$className is not an Entity");
        }
    }

    /**
     * The relation tables carry no `ON DELETE CASCADE` on purpose: a cascade happens inside the
     * database, so no event fires and no audit row is written. The services delete these rows
     * explicitly instead.
     */
    public function testTheAuditedRelationsDoNotCascade(): void {
        foreach ([UserRole::class, RolePermission::class] as $className) {
            foreach ((new ReflectionClass($className))->getProperties() as $property) {
                foreach ($property->getAttributes(\Dynart\Micro\Entities\Attribute\Column::class) as $attribute) {
                    $column = $attribute->newInstance();
                    $this->assertNull(
                        $column->onDelete,
                        "$className::\${$property->getName()} must not cascade, it would skip the audit"
                    );
                }
            }
        }
    }

    public function testUserStatusesAreDistinct(): void {
        $this->assertSame(count(User::STATUSES), count(array_unique(User::STATUSES)));
        $this->assertContains(User::STATUS_ACTIVE, User::STATUSES);
    }

    public function testTheAdminRoleIsUnremovableByDefaultSeed(): void {
        $role = new Role();
        $role->name = Role::NAME_ADMIN;
        $this->assertTrue($role->isAdmin());
    }
}
