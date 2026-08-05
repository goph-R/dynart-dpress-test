<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Micro\Entities\EntityManager;
use Dynart\Dpress\DpressException;
use Dynart\Dpress\Entity\Role;
use Dynart\Dpress\Entity\User;
use Dynart\Dpress\Entity\UserRole;
use Dynart\Dpress\Service\UserService;
use Dynart\Dpress\Test\RecordingEvents;
use Dynart\Dpress\Test\StubConfig;
use Dynart\Dpress\Test\StubDatabase;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The site keeps a way in
 *
 * Blocking the last administrator locks everybody out of the admin: `AuthService::login()`
 * refuses anybody who is not active, so an account that cannot sign in is not an administrator
 * in any sense that matters. Recovering needs shell access, which is exactly what the person
 * locked out of their own site does not necessarily have.
 *
 * The check used to live in `UserCommands`, where it guarded `dpress user:role -revoke` and
 * nothing else - the admin UI revoked, blocked and deleted straight past it. It is in
 * `UserService` now, which every path has to go through.
 *
 * @covers \Dynart\Dpress\Service\UserService
 */
class LastAdminTest extends TestCase {

    private function admin(string $status = User::STATUS_ACTIVE): User {
        $user = new User();
        $user->id = 1;
        $user->email = 'admin@example.com';
        $user->status = $status;
        return $user;
    }

    /**
     * A service with the two lookups answered, so the rule can be tested without a database
     */
    private function service(array $roleNames, int $activeAdmins): UserService {
        return new class($roleNames, $activeAdmins) extends UserService {
            public function __construct(private array $names, private int $admins) {}
            public function roleNames(int $userId): array { return $this->names; }
            public function countActiveAdmins(): int { return $this->admins; }
            public function guard(User $user, string $consequence): void {
                $this->guardLastActiveAdmin($user, $consequence);
            }
        };
    }

    // --- who counts as the last way in ---

    public function testTheOnlyActiveAdministratorIsTheLastOne(): void {
        $service = $this->service([Role::NAME_ADMIN], 1);
        $this->assertTrue($service->isLastActiveAdmin($this->admin()));
    }

    public function testOneOfTwoIsNotTheLastOne(): void {
        $service = $this->service([Role::NAME_ADMIN], 2);
        $this->assertFalse($service->isLastActiveAdmin($this->admin()));
    }

    /**
     * Somebody already blocked is not the way in, whatever roles they hold - taking the role
     * from an account that cannot sign in anyway costs the site nothing
     */
    public function testAnAdministratorWhoIsAlreadyBlockedIsNotTheLastOne(): void {
        $service = $this->service([Role::NAME_ADMIN], 1);
        $this->assertFalse($service->isLastActiveAdmin($this->admin(User::STATUS_BLOCKED)));
        $this->assertFalse($service->isLastActiveAdmin($this->admin(User::STATUS_PENDING)));
    }

    public function testSomebodyWhoIsNotAnAdministratorIsNeverTheLastOne(): void {
        $service = $this->service(['editor'], 1);
        $this->assertFalse($service->isLastActiveAdmin($this->admin()));
    }

    // --- what the guard does ---

    public function testTheGuardRefusesAndSaysWhy(): void {
        $service = $this->service([Role::NAME_ADMIN], 1);
        $this->expectException(DpressException::class);
        $this->expectExceptionMessage('the account cannot be deleted');
        $service->guard($this->admin(), 'the account cannot be deleted');
    }

    public function testTheGuardIsSilentWhenSomebodyElseCanStillSignIn(): void {
        $service = $this->service([Role::NAME_ADMIN], 2);
        $service->guard($this->admin(), 'the account cannot be deleted');
        $this->expectNotToPerformAssertions();
    }

    // --- the count itself ---

    /**
     * The arithmetic that makes the refinement worth having
     *
     * Counting every administrator regardless of status gets it wrong in the one case that
     * matters: with two of them and one already blocked, blocking the other leaves nobody who
     * can sign in while a naive count still says two.
     */
    public function testTheCountAsksForActiveAdministratorsOnly(): void {
        $db = new StubDatabase();
        $config = new StubConfig();
        $em = new EntityManager($config, $db, new RecordingEvents());
        foreach ([User::class, Role::class, UserRole::class] as $className) {
            $em->registerEntity($className);
        }
        $service = (new ReflectionClass(UserService::class))->newInstanceWithoutConstructor();
        foreach (['db' => $db, 'em' => $em] as $property => $value) {
            $found = (new ReflectionClass(UserService::class))->getProperty($property);
            $found->setAccessible(true);
            $found->setValue($service, $value);
        }
        $db->answers = [2];

        $this->assertSame(2, $service->countActiveAdmins());
        $query = end($db->queries);
        $this->assertSame(Role::NAME_ADMIN, $query['params'][':role']);
        $this->assertSame(User::STATUS_ACTIVE, $query['params'][':status']);
        $this->assertStringContainsString('count(1)', $query['sql']);
    }
}
