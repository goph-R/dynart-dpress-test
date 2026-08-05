<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Micro\Entities\EntityManager;
use Dynart\Dpress\Entity\AuthAttempt;
use Dynart\Dpress\Security\RateLimiter;
use Dynart\Dpress\Test\RecordingEvents;
use Dynart\Dpress\Test\StubConfig;
use Dynart\Dpress\Test\StubDatabase;
use PHPUnit\Framework\TestCase;

/**
 * How many times something may be tried
 *
 * Guessing a password is cheap and a password is short, so the only thing between an eight
 * character password and somebody who wants it is how many guesses a minute they get.
 *
 * The logic here *is* the query: which window it counts over, which key it counts, what it
 * deletes when it clears. Those are assertions about the SQL and its parameters - a real server
 * would only confirm that MariaDB can count.
 *
 * @covers \Dynart\Dpress\Security\RateLimiter
 */
class RateLimiterTest extends TestCase {

    private StubDatabase $db;
    private StubConfig $config;
    private RateLimiter $limiter;

    protected function setUp(): void {
        $this->db = new StubDatabase();
        $this->config = new StubConfig();
        $em = new EntityManager($this->config, $this->db, new RecordingEvents());
        $em->registerEntity(AuthAttempt::class);
        $this->limiter = new RateLimiter($this->config, $em, $this->db);
    }

    private function hashOf(string $key): string {
        return hash('sha256', $key);
    }

    private function lastParams(string $fragment): array {
        $matching = $this->db->matching($fragment);
        $this->assertNotEmpty($matching, "no query containing \"$fragment\" was sent");
        return end($matching)['params'];
    }

    // --- what it counts ---

    /**
     * The key never reaches the database
     *
     * Attempts are counted per address and per account, and the account is whatever somebody
     * typed into the form - including addresses that have no account here, which is exactly the
     * set this site has no business writing down.
     */
    public function testTheKeyIsStoredAsADigest(): void {
        $this->limiter->reached(RateLimiter::SCOPE_LOGIN, 'account', 'someone@example.com');
        $params = $this->lastParams('count(1)');
        $this->assertSame($this->hashOf('someone@example.com'), $params[':key']);
        $this->assertStringNotContainsString('example.com', json_encode($params));
    }

    public function testItCountsOnlyInsideTheWindow(): void {
        $this->limiter->reached(RateLimiter::SCOPE_LOGIN, 'account', 'someone@example.com');
        $since = $this->lastParams('count(1)')[':since'];
        $this->assertEqualsWithDelta(time() - 900, strtotime($since.' UTC'), 2);
    }

    public function testTheWindowAndTheLimitAreConfigurable(): void {
        $this->config->set('dpress.rate_limit.login.window', 60);
        $this->config->set('dpress.rate_limit.login.account', 2);
        $this->assertSame(60, $this->limiter->window(RateLimiter::SCOPE_LOGIN));
        $this->assertSame(2, $this->limiter->limit(RateLimiter::SCOPE_LOGIN, 'account'));
        $this->limiter->reached(RateLimiter::SCOPE_LOGIN, 'account', 'someone@example.com');
        $since = $this->lastParams('count(1)')[':since'];
        $this->assertEqualsWithDelta(time() - 60, strtotime($since.' UTC'), 2);
    }

    // --- when it says no ---

    public function testUnderTheLimitIsNotReached(): void {
        $this->db->answers = [4]; // the default is five
        $this->assertFalse($this->limiter->reached(RateLimiter::SCOPE_LOGIN, 'account', 'someone@example.com'));
    }

    public function testTheLimitIsTheLastAttemptAllowed(): void {
        $this->db->answers = [5];
        $this->assertTrue($this->limiter->reached(RateLimiter::SCOPE_LOGIN, 'account', 'someone@example.com'));
    }

    /**
     * A per account limit on its own hands anybody a way to lock a person out by failing on
     * their behalf; a per address limit on its own does nothing about one password tried against
     * every account. Both, or neither is worth having.
     */
    public function testEitherLimitIsEnoughToRefuse(): void {
        $this->db->answers = [0, 20]; // the account is clear, the address has had its twenty
        $this->assertTrue($this->limiter->reachedEither(RateLimiter::SCOPE_LOGIN, 'someone@example.com', '10.0.0.1'));
    }

    public function testTheTwoLimitsAreDifferentNumbers(): void {
        $this->assertSame(5, $this->limiter->limit(RateLimiter::SCOPE_LOGIN, 'account'));
        $this->assertSame(20, $this->limiter->limit(RateLimiter::SCOPE_LOGIN, 'address'));
    }

    /**
     * An empty key is no key: a request with no address must not share one allowance with every
     * other request that had none
     */
    public function testAnEmptyKeyIsNeverLimited(): void {
        $this->db->answers = [999];
        $this->assertFalse($this->limiter->reached(RateLimiter::SCOPE_LOGIN, 'address', ''));
        $this->assertSame([], $this->db->queries);
    }

    // --- what it writes ---

    public function testAnAttemptIsRecordedAgainstBothKeys(): void {
        $this->limiter->record(RateLimiter::SCOPE_LOGIN, 'someone@example.com', '10.0.0.1');
        $inserts = $this->db->matching('insert into');
        $this->assertCount(2, $inserts);
        $hashes = array_map(fn($q) => $q['params'][':key_hash'], $inserts);
        $this->assertSame([$this->hashOf('someone@example.com'), $this->hashOf('10.0.0.1')], $hashes);
    }

    public function testARecordWithNoAddressStillCountsTheAccount(): void {
        $this->limiter->record(RateLimiter::SCOPE_LOGIN, 'someone@example.com', '');
        $this->assertCount(1, $this->db->matching('insert into'));
    }

    /**
     * Clearing the address as well would mean anybody holding one valid account could wipe their
     * own address count between guesses at everybody else's
     */
    public function testClearingTakesOneKeyAndOneScope(): void {
        $this->limiter->clear(RateLimiter::SCOPE_LOGIN, 'someone@example.com');
        $deletes = $this->db->matching('delete from');
        $this->assertCount(1, $deletes);
        $this->assertSame(RateLimiter::SCOPE_LOGIN, $deletes[0]['params'][':scope']);
        $this->assertSame($this->hashOf('someone@example.com'), $deletes[0]['params'][':key']);
    }

    public function testPruningGoesBackAsFarAsTheLongestWindow(): void {
        $this->limiter->prune();
        $cutoff = $this->lastParams('delete from')[':cutoff'];
        $longest = max(array_column(RateLimiter::LIMITS, 'window'));
        $this->assertEqualsWithDelta(time() - $longest, strtotime($cutoff.' UTC'), 2);
    }

    // --- switched off ---

    /**
     * Off means off: no counting, no writing, and no queries either
     */
    public function testDisabledLimitsNothingAndWritesNothing(): void {
        $this->config->set(RateLimiter::CONFIG_ENABLED, false);
        $this->db->answers = [999];
        $this->assertFalse($this->limiter->reached(RateLimiter::SCOPE_LOGIN, 'account', 'someone@example.com'));
        $this->limiter->record(RateLimiter::SCOPE_LOGIN, 'someone@example.com', '10.0.0.1');
        $this->assertSame([], $this->db->queries);
    }

    // --- what it tells somebody ---

    public function testRetryAfterIsWhenTheOldestAttemptLeavesTheWindow(): void {
        $this->db->answers = [gmdate('Y-m-d H:i:s', time() - 300)];
        $this->assertEqualsWithDelta(600, $this->limiter->retryAfter(RateLimiter::SCOPE_LOGIN, 'someone@example.com'), 2);
    }

    public function testRetryAfterIsZeroWhenNothingIsBeingHeldBack(): void {
        $this->db->answers = [null];
        $this->assertSame(0, $this->limiter->retryAfter(RateLimiter::SCOPE_LOGIN, 'someone@example.com'));
    }

    public function testTheWaitReadsAsSomethingAPersonWouldSay(): void {
        $this->assertSame('1 minute', $this->limiter->humanRetryAfter(1));
        $this->assertSame('1 minute', $this->limiter->humanRetryAfter(60));
        $this->assertSame('15 minutes', $this->limiter->humanRetryAfter(900));
        $this->assertSame('2 minutes', $this->limiter->humanRetryAfter(61));
        $this->assertSame('1 hour', $this->limiter->humanRetryAfter(3600));
        $this->assertSame('2 hours', $this->limiter->humanRetryAfter(3601));
    }
}
