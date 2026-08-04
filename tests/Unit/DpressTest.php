<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Dpress;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Dynart\Dpress\Dpress
 */
class DpressTest extends TestCase {

    /**
     * The version lives in two places: `Dpress::VERSION`, which `dpress version` prints, and
     * composer.json, which is what a site actually installs. Nothing keeps them in sync but this.
     */
    public function testTheVersionMatchesComposerJson(): void {
        $path = __DIR__.'/../../vendor/dynart/dpress/composer.json';
        $this->assertFileExists($path);
        $composer = json_decode(file_get_contents($path), true);
        $this->assertSame($composer['version'], Dpress::VERSION);
    }

    public function testTheVersionLooksLikeAVersion(): void {
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', Dpress::VERSION);
    }

    public function testTheConfigFileNameIsSet(): void {
        $this->assertSame('dpress.ini', Dpress::CONFIG_FILE_NAME);
    }
}
