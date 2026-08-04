<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Entity\Role;
use Dynart\Dpress\Entity\User;
use Dynart\Dpress\Security\DpressUser;
use Dynart\Dpress\Security\Permissions;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Dynart\Dpress\Security\DpressUser
 */
class DpressUserTest extends TestCase {

    public function testSubAndId(): void {
        $user = new DpressUser('42');
        $this->assertSame('42', $user->sub());
        $this->assertSame(42, $user->id());
    }

    public function testHasPermissionFromTheGrantedList(): void {
        $user = new DpressUser('1', [Role::NAME_EDITOR], [Permissions::USER_VIEW]);
        $this->assertTrue($user->hasPermission(Permissions::USER_VIEW));
        $this->assertFalse($user->hasPermission(Permissions::USER_DELETE));
    }

    /**
     * The admin role holds everything implicitly, which is what stops a permission added later
     * by a plugin from having to be granted to it retroactively.
     */
    public function testAnAdminHoldsEveryPermissionImplicitly(): void {
        $user = new DpressUser('1', [Role::NAME_ADMIN], []);
        $this->assertTrue($user->hasPermission(Permissions::USER_DELETE));
        $this->assertTrue($user->hasPermission('some.plugin.permission.invented.later'));
        $this->assertTrue($user->isAdmin());
    }

    public function testANonAdminIsNotAnAdmin(): void {
        $user = new DpressUser('1', [Role::NAME_EDITOR], []);
        $this->assertFalse($user->isAdmin());
    }

    public function testHasRole(): void {
        $user = new DpressUser('1', [Role::NAME_EDITOR, Role::NAME_READER]);
        $this->assertTrue($user->hasRole(Role::NAME_EDITOR));
        $this->assertFalse($user->hasRole(Role::NAME_ADMIN));
    }

    public function testWithoutRolesOrPermissions(): void {
        $user = new DpressUser('1');
        $this->assertSame([], $user->roles());
        $this->assertSame([], $user->permissions());
        $this->assertFalse($user->hasPermission(Permissions::USER_VIEW));
    }

    /**
     * A user resolved straight from a token has no record loaded, and asking for its name must
     * not fatal.
     */
    public function testNameIsEmptyWithoutALoadedRecord(): void {
        $user = new DpressUser('1');
        $this->assertNull($user->user());
        $this->assertSame('', $user->name());
    }

    public function testNameComesFromTheLoadedRecord(): void {
        $record = new User();
        $record->name = 'Joe';
        $user = new DpressUser('1', [], [], $record);
        $this->assertSame('Joe', $user->name());
        $this->assertSame($record, $user->user());
    }
}
