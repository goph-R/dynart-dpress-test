<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Content\CodeAssets;
use Dynart\Dpress\Content\CodeBlockRenderer;
use Dynart\Dpress\Dpress;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;
use PHPUnit\Framework\TestCase;

/**
 * ` ```php ` and what gets stored for it
 *
 * **The colours are not in the document.** A fenced block is rendered to a `<pre>` carrying the
 * language as an attribute, and EnlighterJS paints it in the browser. A server-side highlighter
 * would write a `<span>` per token into `body_html` - markup about how a thing looks, living
 * inside the content - and changing the theme or upgrading the highlighter would mean
 * re-rendering every post. This way the stored HTML is semantic and permanent.
 *
 * @covers \Dynart\Dpress\Content\CodeBlockRenderer
 * @covers \Dynart\Dpress\Content\CodeAssets
 */
class CodeHighlightingTest extends TestCase {

    private function render(string $markdown): string {
        $environment = new Environment(['html_input' => 'strip', 'allow_unsafe_links' => false]);
        $environment->addExtension(new CommonMarkCoreExtension());
        (new CodeBlockRenderer())->onEnvironment($environment);
        return trim((string)(new MarkdownConverter($environment))->convert($markdown));
    }

    // --- what is stored ---

    public function testTheLanguageIsAnAttributeAndTheColoursAreNotThere(): void {
        $html = $this->render("```php\necho 1;\n```");
        $this->assertStringContainsString('<pre data-enlighter-language="php">', $html);
        $this->assertStringContainsString('<code class="language-php">', $html);
        $this->assertStringNotContainsString('<span', $html, 'a token span was baked into the document');
    }

    /**
     * A fence with nothing after it is a code block, not a failed one, and renders exactly as it
     * did before any of this existed
     */
    public function testAFenceWithNoLanguageIsUntouched(): void {
        $this->assertSame("<pre><code>plain\n</code></pre>", $this->render("```\nplain\n```"));
    }

    /**
     * The author writes what they write; the highlighter has its own names for some of them. The
     * class keeps theirs, because that is what every other tool reads.
     */
    public function testAliasesAreResolvedForTheHighlighterAndNotForTheClass(): void {
        $html = $this->render("```py\nx = 1\n```");
        $this->assertStringContainsString('data-enlighter-language="python"', $html);
        $this->assertStringContainsString('class="language-py"', $html);
    }

    public function testTheAliasesPeopleActuallyType(): void {
        foreach (['c++' => 'cpp', 'c#' => 'csharp', 'js' => 'javascript', 'yml' => 'yaml',
                  'bash' => 'shell', 'html' => 'xml'] as $written => $resolved) {
            $this->assertStringContainsString(
                'data-enlighter-language="'.$resolved.'"',
                $this->render("```$written\nx\n```"),
                "'$written' did not resolve to '$resolved'"
            );
        }
    }

    /**
     * The languages asked for by name, which must all pass through untouched
     */
    public function testTheOnesThatNeedNoAlias(): void {
        foreach (['c', 'cpp', 'csharp', 'java', 'python', 'php', 'go', 'rust', 'sql'] as $language) {
            $this->assertStringContainsString(
                'data-enlighter-language="'.$language.'"', $this->render("```$language\nx\n```")
            );
        }
    }

    /**
     * A language nobody has heard of is somebody's own convention, not an error
     */
    public function testAnUnknownLanguageIsPassedThroughRatherThanRefused(): void {
        $html = $this->render("```pseudocode\nx\n```");
        $this->assertStringContainsString('data-enlighter-language="pseudocode"', $html);
    }

    /**
     * The info string is whatever came after the backticks, and it reaches an HTML attribute
     */
    public function testAnInfoStringCannotBecomeMarkup(): void {
        $html = $this->render("```php\"><script>alert(1)</script>\nx\n```");
        $this->assertStringNotContainsString('<script', $html);
    }

    /**
     * The test to keep above all the others here: a code block is *text*, whatever is in it
     */
    public function testTheCodeItselfIsStillEscaped(): void {
        $html = $this->render("```html\n<script>alert(1)</script>\n```");
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    // --- what the page loads ---

    /**
     * The front end of this CMS ships no JavaScript, and a page without code keeps it that way
     */
    public function testAPageWithNoCodeNeedsNothing(): void {
        $assets = $this->assets('enlighter');
        $this->assertFalse($assets->needed('<p>An ordinary page.</p>'));
        $this->assertTrue($assets->needed('<pre data-enlighter-language="php">…</pre>'));
    }

    /**
     * `SettingService::get()` reads `''` as *absent* and answers with the default, so "off" has
     * to be a word rather than an empty value - otherwise a site cannot switch this off at all
     */
    public function testOffIsAWordAndItWorks(): void {
        $this->assertSame('', $this->assets(CodeAssets::NONE)->theme());
        $this->assertFalse($this->assets(CodeAssets::NONE)->needed('<pre data-enlighter-language="php">…</pre>'));
        $this->assertNotSame('', $this->assets('dracula')->theme());
    }

    /**
     * The setting is writable by hand and by a plugin, and a stylesheet path is not something to
     * build out of whatever is in a row
     */
    public function testAThemeThatIsNotOneOfOursIsOffRatherThanGuessedAt(): void {
        $this->assertSame('', $this->assets('../../../etc/passwd')->theme());
        $this->assertSame('', $this->assets('bogus')->theme());
    }

    /**
     * Every theme the setting offers has a stylesheet behind it - a name with no file is a page
     * that asks for a 404 on every load and shows uncoloured code with no clue why
     */
    public function testEveryThemeOfferedHasAStylesheet(): void {
        foreach (array_keys(CodeAssets::THEMES) as $name) {
            $this->assertFileExists(
                dirname(Dpress::viewsPath()).'/assets/enlighter/enlighterjs.'.$name.'.min.css',
                "the '$name' theme has no stylesheet"
            );
        }
        $this->assertFileExists(dirname(Dpress::viewsPath()).'/assets/enlighter/enlighterjs.min.js');
    }

    private function assets(string $theme): CodeAssets {
        $settings = new PlacesSettings();   // the stub from ThemePlacesTest: answers from an array
        $settings->values[\Dynart\Dpress\Entity\Setting::CODE_THEME] = $theme;
        return new CodeAssets($this->createMock(\Dynart\Micro\RouterInterface::class), $settings);
    }
}
