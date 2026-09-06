<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Cli\InitCommands;
use Dynart\Dpress\Dpress;
use Dynart\Dpress\DpressCliApp;
use Dynart\Micro\CliOutputInterface;
use PHPUnit\Framework\TestCase;

/**
 * `dpress init`, the one command that runs before there is a site
 *
 * It writes a real file into a real directory, so this does too - into a temporary one, with the
 * working directory moved for the duration, because "where am I" is the input the command
 * actually has. Stubbing `getcwd()` would be testing something else.
 *
 * @covers \Dynart\Dpress\Cli\InitCommands
 */
class InitCommandTest extends TestCase {

    private string $temp = '';
    private string $wasIn = '';
    /** @var string[] */
    private array $written = [];

    protected function setUp(): void {
        $this->wasIn = (string)getcwd();
        $this->temp = sys_get_temp_dir().'/dpress-init-'.bin2hex(random_bytes(4));
        mkdir($this->temp, 0775, true);
        chdir($this->temp);
        $this->written = [];
    }

    protected function tearDown(): void {
        chdir($this->wasIn);
        $this->removeTree($this->temp);
    }

    private function removeTree(string $path): void {
        if (!is_dir($path)) {
            return;
        }
        foreach ((array)scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path.'/'.$entry;
            is_dir($full) ? $this->removeTree($full) : unlink($full);
        }
        rmdir($path);
    }

    private function command(): InitCommands {
        $lines = &$this->written;
        return new InitCommands(new class($lines) implements CliOutputInterface {
            public function __construct(private array &$lines) {}
            public function setColor(?int $color, ?int $bgColor = null): void {}
            public function setUseColor(bool $useColor): void {}
            public function write(string $text): void { $this->lines[] = $text; }
            public function writeLine(string $text): void { $this->lines[] = $text; }
        });
    }

    private function init(array $params = []): int {
        return $this->command()->init(array_merge([
            'base-url' => 'https://example.com',
            'db-name' => 'mysite',
            'db-user' => 'mysite',
        ], $params));
    }

    private function config(): array {
        $parsed = parse_ini_file($this->temp.'/dpress.ini', false, INI_SCANNER_TYPED);
        $this->assertIsArray($parsed, 'the written dpress.ini does not parse');
        return $parsed;
    }

    // --- what it writes ---

    public function testItWritesAConfigThatParses(): void {
        $this->assertSame(0, $this->init());
        $config = $this->config();
        $this->assertSame('https://example.com', $config['app.base_url']);
        $this->assertSame('mysite', $config['database.default.name']);
        $this->assertSame('mysite', $config['database.default.username']);
        $this->assertSame('dp_', $config['database.default.table_prefix']);
        $this->assertSame(str_replace('\\', '/', (string)realpath($this->temp)),
            str_replace('\\', '/', $config['app.root_path']));
    }

    /**
     * The fiddliest step of an install, and the one where getting it wrong is invisible: a site
     * copied from the example and never edited signs its sessions with a value that is in a
     * public repository
     */
    public function testTheSecretIsGeneratedAndDifferentEveryTime(): void {
        $this->init();
        $first = $this->config()['jwt.secret'];
        $this->assertSame(64, strlen($first), 'HS256 wants 32 bytes, written as 64 hex characters');
        $this->assertStringNotContainsStringIgnoringCase('change me', $first);

        unlink($this->temp.'/dpress.ini');
        $this->init();
        $this->assertNotSame($first, $this->config()['jwt.secret']);
    }

    /**
     * A development site needs three settings changed together, and each is a separate way to
     * lose an afternoon: a secure cookie on plain HTTP means the login never sticks, and `native`
     * mail on a laptop means a password reset that goes nowhere with no sign of it.
     */
    public function testTheDevFlagChangesTheThreeThingsThatGoTogether(): void {
        $this->init(['dev' => true]);
        $config = $this->config();
        $this->assertSame('dev', $config['app.environment']);
        $this->assertFalse($config['jwt.cookie_secure']);
        $this->assertSame('log', $config['mail.mailer']);
    }

    public function testWithoutTheDevFlagItIsAProductionConfig(): void {
        $this->init();
        $config = $this->config();
        $this->assertSame('prod', $config['app.environment']);
        $this->assertTrue($config['jwt.cookie_secure']);
        $this->assertSame('native', $config['mail.mailer']);
    }

    /**
     * A placeholder added to the template and forgotten in the command would ship a literal
     * `%%SOMETHING%%` into somebody's configuration, and the first sign of it would be a site
     * that will not boot
     */
    public function testNoPlaceholderSurvivesIntoTheWrittenFile(): void {
        $this->init(['dev' => true, 'site-name' => 'A Site', 'email' => 'a@b.c',
                     'db-password' => 'secret', 'db-host' => 'db.internal', 'db-prefix' => 'x_']);
        $written = (string)file_get_contents($this->temp.'/dpress.ini');
        $this->assertDoesNotMatchRegularExpression('/%%[A-Z_]+%%/', $written);
    }

    public function testEveryPlaceholderInTheTemplateIsOneTheCommandKnows(): void {
        $template = (string)file_get_contents(Dpress::path(InitCommands::TEMPLATE));
        preg_match_all('/%%[A-Z_]+%%/', $template, $found);
        $source = (string)file_get_contents(Dpress::path('src/Cli/InitCommands.php'));
        foreach (array_unique($found[0]) as $placeholder) {
            $this->assertStringContainsString("'".$placeholder."'", $source,
                $placeholder.' is in the template and nothing substitutes it');
        }
    }

    public function testTheLogDirectoryIsMadeAbovePublic(): void {
        $this->init();
        $this->assertDirectoryExists($this->temp.'/logs');
        $this->assertSame('"~/logs"', $this->raw('log.dir'), 'the logs must not be under public/');
    }

    private function raw(string $key): string {
        foreach (explode("\n", (string)file_get_contents($this->temp.'/dpress.ini')) as $line) {
            if (str_starts_with(trim($line), $key)) {
                return trim(explode('=', $line, 2)[1] ?? '');
            }
        }
        return '';
    }

    // --- what it refuses ---

    /**
     * Overwriting logs everybody out and throws away the secret, which is not something to do
     * because a command was run twice
     */
    public function testItWillNotOverwriteAConfigThatIsAlreadyThere(): void {
        $this->init();
        $before = (string)file_get_contents($this->temp.'/dpress.ini');
        $this->assertSame(1, $this->init());
        $this->assertSame($before, (string)file_get_contents($this->temp.'/dpress.ini'));
    }

    public function testTheAddressHasNoSensibleDefault(): void {
        $this->assertSame(1, $this->command()->init(['db-name' => 'x', 'db-user' => 'x']));
        $this->assertFileDoesNotExist($this->temp.'/dpress.ini');
    }

    public function testTheDatabaseHasToBeNamed(): void {
        $this->assertSame(1, $this->command()->init(['base-url' => 'https://example.com']));
        $this->assertFileDoesNotExist($this->temp.'/dpress.ini');
    }

    public function testATrailingSlashOnTheAddressIsDropped(): void {
        $this->init(['base-url' => 'https://example.com/']);
        $this->assertSame('https://example.com', $this->config()['app.base_url'],
            'every generated URL appends its own slash');
    }

    // --- how it is registered ---

    /**
     * The one command that must run where there is no site, because it is what makes one
     */
    public function testItIsTheOnlySiteCommandThatNeedsNoConfig(): void {
        $this->assertArrayHasKey('init', DpressCliApp::COMMANDS);
        $this->assertFalse(DpressCliApp::COMMANDS['init']['needsConfig']);
        $this->assertFalse(DpressCliApp::commandNeedsConfig('init'));
    }

    /**
     * `user:create` has generated a password since it existed, and the help said only "Create a
     * user" - so the documented way to make the first admin put it in the shell history
     */
    public function testUserCreateAdvertisesThatItGeneratesAPassword(): void {
        $this->assertStringContainsString(
            'generating a password', DpressCliApp::COMMANDS['user:create']['description']
        );
    }
}
