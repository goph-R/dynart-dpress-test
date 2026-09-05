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
        $this->assertStringContainsString('data-enlighter-language="php"', $html);
        $this->assertStringContainsString('class="language-php"', $html);
        $this->assertStringNotContainsString('<span', $html, 'a token span was baked into the document');
    }

    /**
     * The bug this shipped with: **a highlighted block has no `<code>` inside it**
     *
     * EnlighterJS reads the `innerHTML` of the element it matched and unescapes it, so a `<code>`
     * wrapper is not ignored - it is *displayed as the first line of the code*, tag and all. Its
     * documented markup is a `<pre>` with the code directly inside.
     */
    public function testAHighlightedBlockHasNoCodeElementToLeakIntoTheDisplay(): void {
        $html = $this->render("```php\necho 1;\n```");
        $this->assertStringNotContainsString('<code', $html, 'this tag would be shown as code, not as markup');
        $this->assertStringContainsString('>echo 1;', $html);
    }

    /**
     * Every blank line an author wrote is still there. The report that found the `<code>` leak
     * started as "my blank lines are gone", and this is the half of it that is ours to keep.
     */
    public function testBlankLinesInsideABlockSurvive(): void {
        $html = $this->render("```php\n<?php\n\nclass A {\n\n}\n```");
        $this->assertStringContainsString("\n\nclass A {\n\n}", $html);
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

    /**
     * Every theme paints the block's background on `.enlighter-default` and sets `padding: 0`, so
     * the code sits against the top and bottom edges of the colour. The correction is one class
     * against the theme's one class, which means **source order decides it** - and the theme's
     * stylesheet is added to the page after the layout's own `<style>`. So it has to come from
     * here, after the link, rather than from a layout that happens to be earlier.
     */
    public function testThePaddingCorrectionComesAfterTheThemeItCorrects(): void {
        $tags = $this->assets('dracula')->tags();
        $link = strpos($tags, 'dracula.min.css');
        $style = strpos($tags, CodeAssets::STYLE);
        $this->assertNotFalse($link);
        $this->assertNotFalse($style);
        $this->assertGreaterThan($link, $style, 'the correction is before the stylesheet, so it loses');
    }

    /**
     * A wrapped line of code says something different from the line that was written
     *
     * EnlighterJS defaults `textOverflow` to `break`, so a long line wraps - and code is the one
     * kind of text where that changes the meaning: the indent stops marking a nesting level and
     * a shell command comes apart mid-flag. `scroll` is the library's own supported mode; it adds
     * `enlighter-overflow-scroll`, which its stylesheets already answer with `overflow-x: auto`
     * and `white-space: pre`. Worth a test because it is one word in a string that nothing else
     * would notice going missing.
     */
    public function testCodeScrollsRatherThanWraps(): void {
        $this->assertStringContainsString('textOverflow:"scroll"', $this->assets('dracula')->tags());
    }

    /**
     * A sideways-scrolling block is one a thumb swipes in, and the swipe chains to the page once
     * the code runs out - which is the back gesture on iOS and under Chrome's
     */
    public function testTheScrollDoesNotChainToThePage(): void {
        $this->assertStringContainsString('overscroll-behavior-x:contain', CodeAssets::STYLE);
    }

    /**
     * `init(blocks, inline, options)` — the second selector is for inline snippets, and `code`
     * there rebuilds every backtick span in somebody's prose into a code sample
     */
    public function testTheInlineSelectorMatchesNothing(): void {
        $tags = $this->assets('dracula')->tags();
        $this->assertStringContainsString('"code.enlighter-inline"', $tags);
        $this->assertStringNotContainsString('],"code"', $tags, 'every inline code span would be rebuilt');
    }

    public function testNothingIsEmittedWhenHighlightingIsOff(): void {
        $this->assertSame('', $this->assets(CodeAssets::NONE)->tags());
    }

    private function assets(string $theme): CodeAssets {
        $settings = new PlacesSettings();   // the stub from ThemePlacesTest: answers from an array
        $settings->values[\Dynart\Dpress\Entity\Setting::CODE_THEME] = $theme;
        $router = $this->createMock(\Dynart\Micro\RouterInterface::class);
        $router->method('url')->willReturnCallback(fn(string $path, array $q = []) => '/base'.$path);
        return new CodeAssets($router, $settings);
    }
}
