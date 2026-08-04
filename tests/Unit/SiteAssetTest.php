<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Controller\AbstractController;
use Dynart\Dpress\Controller\Admin\DashboardController;
use Dynart\Dpress\Test\StubConfig;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The logo and the icon are settings that name a file
 *
 * A path is stored, not a URL, so the value survives the site moving out of a subfolder onto a
 * domain of its own - the move that would otherwise silently break every stored absolute URL.
 *
 * @covers \Dynart\Dpress\Controller\AbstractController
 */
class SiteAssetTest extends TestCase {

    private function resolve(string $value, string $baseUrl = 'http://example.com/site'): string {
        // any concrete controller will do - the method is on the base and touches only the config
        $controller = (new ReflectionClass(DashboardController::class))->newInstanceWithoutConstructor();
        $property = (new ReflectionClass(AbstractController::class))->getProperty('config');
        $property->setAccessible(true);
        $property->setValue($controller, new StubConfig(['app.base_url' => $baseUrl]));

        $method = new ReflectionMethod(AbstractController::class, 'siteAsset');
        $method->setAccessible(true);
        return $method->invoke($controller, $value);
    }

    public function testAPathIsResolvedAgainstTheBaseUrl(): void {
        $this->assertSame('http://example.com/site/static/logo.svg', $this->resolve('/static/logo.svg'));
    }

    /**
     * The leading slash is what an editor will or will not type, and it should not matter
     */
    public function testTheLeadingSlashIsOptional(): void {
        $this->assertSame('http://example.com/site/favicon.png', $this->resolve('favicon.png'));
    }

    public function testATrailingSlashOnTheBaseUrlDoesNotDouble(): void {
        $this->assertSame(
            'http://example.com/favicon.png',
            $this->resolve('/favicon.png', 'http://example.com/')
        );
    }

    /**
     * A logo on a CDN, or one inlined into the setting, is already a URL
     */
    public function testSomethingAbsoluteIsLeftAlone(): void {
        foreach ([
            'https://cdn.example.com/logo.svg',
            'http://cdn.example.com/logo.svg',
            '//cdn.example.com/logo.svg',
            'data:image/svg+xml;base64,PHN2Zy8+',
        ] as $value) {
            $this->assertSame($value, $this->resolve($value));
        }
    }

    /**
     * No logo is the normal case, and it has to stay empty rather than become the base URL - the
     * template asks `!empty($site_logo)` and would otherwise render the whole site as an `<img>`
     */
    public function testNothingStaysNothing(): void {
        $this->assertSame('', $this->resolve(''));
        $this->assertSame('', $this->resolve('   '));
    }

    public function testTheValueIsTrimmed(): void {
        $this->assertSame('http://example.com/site/logo.svg', $this->resolve("  /logo.svg\n"));
    }
}
