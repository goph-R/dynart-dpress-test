<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\DpressCliApp;
use Dynart\Dpress\DpressException;
use Dynart\Dpress\Entity\User;
use Dynart\Dpress\Service\UserService;
use PHPUnit\Framework\TestCase;

/**
 * Deleting an account, and what stops it
 *
 * `content.author_id` and `media.uploaded_by` are **not null** foreign keys, so a user who has
 * written anything cannot be deleted while it is theirs. Until 0.63.0 that came back as a raw
 * constraint violation from the database - a 500 on the admin screen, a stack trace on the CLI -
 * with nothing anywhere saying *reassign their posts first*.
 *
 * The refusal is in the service beside `guardLastActiveAdmin()`, and for the same reason: the rule
 * is about the state the site may end up in, and there are two ways in.
 *
 * @covers \Dynart\Dpress\Service\UserService
 */
class UserDeleteTest extends TestCase {

    private function user(): User {
        $user = new User();
        $user->id = 7;
        $user->email = 'someone@example.com';
        return $user;
    }

    /**
     * A service with both lookups answered, so the rule can be tested without a database
     */
    private function service(array $owned): UserService {
        return new class($owned) extends UserService {
            public function __construct(private array $owned) {}
            public function ownedRows(int $userId): array { return $this->owned; }
            public function guard(User $user): void { $this->guardOwnedRows($user); }
        };
    }

    public function testAnAccountThatOwnsNothingCanGo(): void {
        $this->service([])->guard($this->user());
        $this->assertTrue(true, 'no refusal is the whole assertion');
    }

    /**
     * **Not a cascade**: deleting somebody must not delete what they wrote, and a cascade would
     * take it out inside the database where no event fires and nothing is audited
     */
    public function testAnAccountThatStillOwnsSomethingIsRefused(): void {
        $this->expectException(DpressException::class);
        $this->expectExceptionMessageMatches('/still has 9 posts and pages/');
        $this->service(['content' => 9])->guard($this->user());
    }

    /**
     * The refusal says what to do about it, because "cannot be deleted" on its own sends somebody
     * to the database
     */
    public function testTheRefusalSaysHowToGetPastIt(): void {
        try {
            $this->service(['media' => 1])->guard($this->user());
            $this->fail('a user who owns a media item should not be deletable');
        } catch (DpressException $e) {
            $this->assertStringContainsString('Author box', $e->getMessage());
        }
    }

    // --- how it reads ---

    public function testOneOfSomethingIsSingular(): void {
        $this->assertSame('1 post or page', UserService::describeOwned(['content' => 1]));
        $this->assertSame('1 media item', UserService::describeOwned(['media' => 1]));
    }

    public function testMoreThanOneIsNot(): void {
        $this->assertSame('9 posts and pages', UserService::describeOwned(['content' => 9]));
    }

    public function testBothAreJoined(): void {
        $this->assertSame(
            '9 posts and pages and 50 media items',
            UserService::describeOwned(['content' => 9, 'media' => 50])
        );
    }

    public function testNothingReadsAsNothing(): void {
        $this->assertSame('', UserService::describeOwned([]));
    }

    // --- the command ---

    /**
     * `-confirm` like `media:purge`, because it is the other operation here that takes something
     * away for good
     */
    public function testTheCommandIsRegisteredAndAsksToBeMeant(): void {
        $command = DpressCliApp::COMMANDS['user:delete'] ?? null;
        $this->assertIsArray($command, 'there is no user:delete command');
        $this->assertSame(['email'], $command['params'] ?? null);
        $this->assertSame(['confirm'], $command['flags'] ?? null);
        $this->assertTrue($command['needsConfig'] ?? false);
    }
}
