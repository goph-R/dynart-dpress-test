<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Security\Permissions;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Dynart\Dpress\Security\Permissions
 */
class PermissionsTest extends TestCase {

    private Permissions $permissions;

    protected function setUp(): void {
        $this->permissions = new Permissions();
    }

    public function testCorePermissionsAreKnown(): void {
        $this->assertTrue($this->permissions->has(Permissions::USER_CREATE));
        $this->assertTrue($this->permissions->has(Permissions::ROLE_ASSIGN));
    }

    public function testAnUnknownPermission(): void {
        $this->assertFalse($this->permissions->has('nosuch.permission'));
    }

    /**
     * A plugin declaring a permission has to show up in the role editor without a migration.
     */
    public function testAPluginCanRegisterItsOwn(): void {
        $this->permissions->add('myplugin.do_thing', 'myplugin');
        $this->assertTrue($this->permissions->has('myplugin.do_thing'));
        $this->assertContains('myplugin.do_thing', $this->permissions->names());
    }

    public function testRegisteredPermissionsAreGrouped(): void {
        $this->permissions->add('myplugin.do_thing', 'myplugin');
        $grouped = $this->permissions->grouped();
        $this->assertArrayHasKey('myplugin', $grouped);
        $this->assertSame(['myplugin.do_thing'], $grouped['myplugin']);
    }

    public function testCorePermissionsAreGroupedBySubject(): void {
        $grouped = $this->permissions->grouped();
        $this->assertContains(Permissions::USER_CREATE, $grouped['user']);
        $this->assertContains(Permissions::ROLE_CREATE, $grouped['role']);
    }

    public function testEveryCorePermissionFollowsTheNamingConvention(): void {
        foreach (array_keys(Permissions::CORE) as $permission) {
            $this->assertMatchesRegularExpression('/^[a-z_]+\.[a-z_]+$/', $permission);
        }
    }

    public function testCorePermissionsAreUnique(): void {
        $names = array_keys(Permissions::CORE);
        $this->assertSame(count($names), count(array_unique($names)));
    }
}
