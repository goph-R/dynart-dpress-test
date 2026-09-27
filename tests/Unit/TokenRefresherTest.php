<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\DpressException;
use Dynart\Dpress\Middleware\TokenRefresher;
use Dynart\Dpress\Security\AuthCookies;
use Dynart\Dpress\Service\AuthService;
use Dynart\Micro\RequestInterface;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;

/**
 * What a request brings in its login cookies, and what is left of them for the validator
 *
 * The case this was written for: a cookie signed with a secret the site no longer has. The
 * validator answers 401 for the whole request when it cannot verify a token, so that one cookie
 * made every page - the login form included - a 401 until the visitor cleared their cookies.
 *
 * @covers \Dynart\Dpress\Middleware\TokenRefresher
 */
class TokenRefresherTest extends TestCase {

    const SECRET = 'the-secret-this-site-signs-with-000000';

    private array $headers = [];
    private bool $cleared = false;
    private ?array $set = null;

    private function refresher(string $authorization, ?string $refreshCookie, ?array $refreshed = null): TokenRefresher {
        $this->headers = ['Authorization' => $authorization];
        $request = $this->createMock(RequestInterface::class);
        $request->method('header')->willReturnCallback(
            fn(string $name, $default = null) => $this->headers[$name] ?? $default
        );
        $request->method('setHeader')->willReturnCallback(function (string $name, string $value): void {
            $this->headers[$name] = $value;
        });

        $cookies = $this->getMockBuilder(AuthCookies::class)->disableOriginalConstructor()->getMock();
        $cookies->method('refreshToken')->willReturn($refreshCookie);
        $cookies->method('clear')->willReturnCallback(function (): void { $this->cleared = true; });
        $cookies->method('set')->willReturnCallback(function (array $tokens): void { $this->set = $tokens; });

        $auth = $this->getMockBuilder(AuthService::class)->disableOriginalConstructor()->getMock();
        $auth->method('secret')->willReturn(self::SECRET);
        $auth->method('algorithm')->willReturn('HS256');
        if ($refreshed === null) {
            $auth->method('refresh')->willThrowException(new DpressException('spent'));
        } else {
            $auth->method('refresh')->willReturn($refreshed);
        }
        return new TokenRefresher($request, $cookies, $auth);
    }

    private static function token(string $secret, int $expiresIn = 600): string {
        return JWT::encode(['sub' => '1', 'exp' => time() + $expiresIn], $secret, 'HS256');
    }

    public function testATokenThisSiteSignedIsLeftAlone(): void {
        $token = self::token(self::SECRET);
        $this->refresher('Bearer '.$token, 'refresh')->run();
        $this->assertSame('Bearer '.$token, $this->headers['Authorization']);
        $this->assertFalse($this->cleared);
        $this->assertNull($this->set);
    }

    /** The reported case: a new secret, and nothing to renew with - anonymous, not a 401 site */
    public function testATokenFromAnotherSecretIsDroppedAndTheCookiesCleared(): void {
        $this->refresher('Bearer '.self::token('a-secret-this-site-no-longer-has-000000'), null)->run();
        $this->assertSame('', $this->headers['Authorization']);
        $this->assertTrue($this->cleared);
    }

    public function testATokenFromAnotherSecretIsRenewedWhenTheRefreshCookieStillWorks(): void {
        $fresh = ['access' => self::token(self::SECRET), 'refresh' => 'new'];
        $this->refresher('Bearer '.self::token('a-secret-this-site-no-longer-has-000000'), 'refresh', $fresh)->run();
        $this->assertSame('Bearer '.$fresh['access'], $this->headers['Authorization']);
        $this->assertSame($fresh, $this->set);
    }

    public function testAnExpiredTokenIsRenewedToo(): void {
        $fresh = ['access' => self::token(self::SECRET), 'refresh' => 'new'];
        $this->refresher('Bearer '.self::token(self::SECRET, -60), 'refresh', $fresh)->run();
        $this->assertSame('Bearer '.$fresh['access'], $this->headers['Authorization']);
    }

    public function testGarbageIsDroppedAndASpentRefreshCookieClearsBoth(): void {
        $this->refresher('Bearer garbage.not.ajwt', 'spent-refresh-token')->run();
        $this->assertSame('', $this->headers['Authorization']);
        $this->assertTrue($this->cleared);
    }

    public function testNoTokenAndNoRefreshCookieIsJustAVisitor(): void {
        $this->refresher('', null)->run();
        $this->assertSame('', $this->headers['Authorization']);
        $this->assertFalse($this->cleared);
    }
}
