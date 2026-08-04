<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\DpressException;
use Dynart\Dpress\Media\SvgSanitizer;
use Dynart\Dpress\Media\SvgSanitizerInterface;
use PHPUnit\Framework\TestCase;

/**
 * What an uploaded SVG is allowed to keep
 *
 * Half of these are payloads and half are ordinary files. Both halves matter: a sanitiser that
 * strips everything is safe and useless, and the failure mode nobody notices is the one where
 * real icons come out blank.
 *
 * @covers \Dynart\Dpress\Media\SvgSanitizer
 */
class SvgSanitizerTest extends TestCase {

    private SvgSanitizer $sanitizer;

    protected function setUp(): void {
        $this->sanitizer = new SvgSanitizer();
    }

    private function sanitize(string $svg): string {
        return $this->sanitizer->sanitize($svg);
    }

    private function assertGone(string $needle, string $output, string $what): void {
        $this->assertStringNotContainsStringIgnoringCase($needle, $output, $what);
    }

    // --- what has to go ---

    public function testAScriptElementIsRemoved(): void {
        $output = $this->sanitize(
            '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10">'
            .'<script>alert(1)</script><rect width="10" height="10"/></svg>'
        );
        $this->assertGone('<script', $output, 'a script element survived');
        $this->assertStringContainsString('<rect', $output, 'the drawing was thrown away with it');
    }

    /**
     * No `on*` attribute is in the allowlist, so this falls out rather than being blocked by name
     */
    public function testEventHandlersAreRemoved(): void {
        foreach ([
            '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><circle r="5"/></svg>',
            '<svg xmlns="http://www.w3.org/2000/svg"><rect width="9" height="9" onclick="alert(1)"/></svg>',
            '<svg xmlns="http://www.w3.org/2000/svg"><rect width="9" height="9" onmouseover="alert(1)"/></svg>',
        ] as $payload) {
            $output = $this->sanitize($payload);
            $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $output, 'a handler survived');
        }
    }

    public function testAJavascriptUrlIsRemoved(): void {
        $output = $this->sanitize(
            '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">'
            .'<a xlink:href="javascript:alert(1)"><text y="10">click</text></a></svg>'
        );
        $this->assertGone('javascript:', $output, 'a javascript: url survived');
    }

    /**
     * A foreignObject is a hole straight through to HTML, which is where a script would sit
     */
    public function testAForeignObjectIsRemoved(): void {
        $output = $this->sanitize(
            '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject width="100" height="50">'
            .'<body xmlns="http://www.w3.org/1999/xhtml"><script>alert(1)</script></body></foreignObject></svg>'
        );
        $this->assertGone('foreignobject', $output, 'a foreignObject survived');
        $this->assertGone('<script', $output, 'the script inside it survived');
    }

    /**
     * `animate` and `set` can write an attribute after load, which is a handler by another route
     */
    public function testAnimationElementsAreRemoved(): void {
        $animate = $this->sanitize(
            '<svg xmlns="http://www.w3.org/2000/svg"><a>'
            .'<animate attributeName="href" values="javascript:alert(1)"/><text y="10">x</text></a></svg>'
        );
        $this->assertGone('<animate', $animate, 'an animate element survived');
        $this->assertGone('javascript:', $animate, 'its payload survived');

        $set = $this->sanitize(
            '<svg xmlns="http://www.w3.org/2000/svg">'
            .'<set attributeName="onmouseover" to="alert(1)"/><rect width="9" height="9"/></svg>'
        );
        $this->assertGone('<set', $set, 'a set element survived');
    }

    public function testAUseElementIsRemoved(): void {
        $output = $this->sanitize(
            '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">'
            .'<use xlink:href="http://evil.example/x.svg#a"/></svg>'
        );
        $this->assertGone('<use', $output, 'a use element survived');
        $this->assertGone('evil.example', $output, 'its target survived');
    }

    /**
     * The one the library alone did not catch: it only rejects an external URL inside a CSS
     * `url()`. Through `<img src>` a browser would not fetch this, but the file is reachable at
     * its own address, and there it is a tracking pixel firing from this origin.
     */
    public function testAnExternalImageReferenceIsRemoved(): void {
        $output = $this->sanitize(
            '<svg xmlns="http://www.w3.org/2000/svg">'
            .'<image href="http://evil.example/track.png" width="1" height="1"/></svg>'
        );
        $this->assertGone('evil.example', $output, 'an external reference survived');
    }

    public function testEveryShapeOfExternalReferenceIsRemoved(): void {
        foreach ([
            '<image href="http://evil.example/x.png" width="1" height="1"/>',
            '<image href="https://evil.example/x.png" width="1" height="1"/>',
            '<image href="//evil.example/x.png" width="1" height="1"/>',
            '<rect width="9" height="9" fill="url(http://evil.example/x)"/>',
            '<rect width="9" height="9" style="fill:url(//evil.example/x)"/>',
        ] as $fragment) {
            $output = $this->sanitize('<svg xmlns="http://www.w3.org/2000/svg">'.$fragment.'</svg>');
            $this->assertGone('evil.example', $output, "an external reference survived in: $fragment");
        }
    }

    /**
     * `data:image/png` is an inert raster and worth keeping; `data:text/html` is a document
     */
    public function testADataUrlIsKeptOnlyForRasterImages(): void {
        $raster = $this->sanitize(
            '<svg xmlns="http://www.w3.org/2000/svg">'
            .'<image width="1" height="1" href="data:image/png;base64,iVBORw0KGgo="/></svg>'
        );
        $this->assertStringContainsString('data:image/png', $raster, 'an embedded raster was thrown away');

        $document = $this->sanitize(
            '<svg xmlns="http://www.w3.org/2000/svg">'
            .'<image width="1" height="1" href="data:text/html;base64,PHNjcmlwdD4="/></svg>'
        );
        $this->assertGone('data:text/html', $document, 'a data: document survived');
    }

    public function testAPhpBlockIsRemoved(): void {
        $output = $this->sanitize(
            '<svg xmlns="http://www.w3.org/2000/svg"><?php echo shell_exec("id"); ?>'
            .'<rect width="9" height="9"/></svg>'
        );
        $this->assertGone('shell_exec', $output, 'a PHP block survived');
        $this->assertGone('<?php', $output, 'a PHP open tag survived');
    }

    /**
     * The parser runs with no doctype and `LIBXML_NONET`, so the entity has nothing to resolve
     * and what is left will not parse - which is the right answer for a file like this
     */
    public function testAnExternalEntityIsRefused(): void {
        $this->expectException(DpressException::class);
        $this->sanitize(
            '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
            .'<svg xmlns="http://www.w3.org/2000/svg"><text y="10">&xxe;</text></svg>'
        );
    }

    public function testAnEntityExpansionBombIsRefused(): void {
        $this->expectException(DpressException::class);
        $this->sanitize(
            '<?xml version="1.0"?><!DOCTYPE lolz [<!ENTITY lol "lol">'
            .'<!ENTITY lol2 "&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;">'
            .'<!ENTITY lol3 "&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;">]>'
            .'<svg xmlns="http://www.w3.org/2000/svg"><text y="10">&lol3;</text></svg>'
        );
    }

    public function testAnEmptyFileIsRefused(): void {
        $this->expectException(DpressException::class);
        $this->sanitize('   ');
    }

    public function testSomethingThatIsNotAnSvgIsRefused(): void {
        $this->expectException(DpressException::class);
        $this->sanitize('<html><body><script>alert(1)</script></body></html>');
    }

    // --- what has to stay ---

    /**
     * Without the namespace the file is safe and blank, which is not a useful outcome
     */
    public function testTheSvgNamespaceSurvives(): void {
        $output = $this->sanitize(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><rect width="24" height="24"/></svg>'
        );
        $this->assertStringContainsString('xmlns="http://www.w3.org/2000/svg"', $output);
    }

    public function testAnOrdinaryIconSurvivesIntact(): void {
        $output = $this->sanitize(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"'
            .' stroke="currentColor" stroke-width="2"><title>A box</title>'
            .'<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M8 12h8"/></svg>'
        );
        foreach (['viewBox', '<title', '<rect', '<path', 'stroke="currentColor"', 'rx="2"'] as $needed) {
            $this->assertStringContainsString($needed, $output, "a real drawing lost $needed");
        }
    }

    /**
     * Almost every exported SVG starts with an XML declaration; refusing those would refuse most
     * real files
     */
    public function testAnXmlDeclarationIsFine(): void {
        $output = $this->sanitize(
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><rect width="24" height="24"/></svg>'
        );
        $this->assertStringContainsString('<rect', $output);
    }

    public function testAnEditorExportWithExtraNamespacesSurvives(): void {
        $output = $this->sanitize(
            '<?xml version="1.0" encoding="UTF-8" standalone="no"?>'
            .'<svg xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns="http://www.w3.org/2000/svg"'
            .' width="24" height="24" viewBox="0 0 24 24" version="1.1">'
            .'<g transform="translate(0,-1028)"><path d="m 4,1032 16,0" style="fill:none;stroke:#000000"/></g></svg>'
        );
        $this->assertStringContainsString('<path', $output, 'the drawing was lost');
        $this->assertStringContainsString('transform="translate(0,-1028)"', $output);
        $this->assertStringContainsString('xmlns:dc=', $output, 'a metadata namespace was treated as a reference');
    }

    /**
     * A gradient is referenced by fragment, which is internal and must not be mistaken for an
     * external URL
     */
    public function testAFragmentReferenceSurvives(): void {
        $output = $this->sanitize(
            '<svg xmlns="http://www.w3.org/2000/svg"><defs><linearGradient id="g">'
            .'<stop offset="0" stop-color="#f00"/><stop offset="1" stop-color="#00f"/></linearGradient></defs>'
            .'<rect width="9" height="9" fill="url(#g)"/></svg>'
        );
        $this->assertStringContainsString('url(#g)', $output, 'an internal reference was stripped');
        $this->assertStringContainsString('linearGradient', $output);
    }

    // --- the contract ---

    public function testItImplementsTheInterface(): void {
        $this->assertInstanceOf(SvgSanitizerInterface::class, $this->sanitizer);
    }

    // --- isClean, which is what the report is built on ---

    /**
     * The one that made the report useless: sanitising reserialises, so an untouched file comes
     * back with different whitespace. Answering "did the bytes change" flagged every SVG in the
     * library, and a report that flags everything is one nobody reads.
     */
    public function testAnUntouchedFileIsCleanEvenThoughItReserialisesDifferently(): void {
        $svg = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>
"
            ."<svg   xmlns=\"http://www.w3.org/2000/svg\"    viewBox=\"0 0 24 24\" >
"
            ."    <rect width=\"24\" height=\"24\"/>
"
            ."</svg>
";
        $this->assertNotSame($svg, $this->sanitize($svg), 'the test is pointless if it round-trips byte for byte');
        $this->assertTrue($this->sanitizer->isClean($svg), 'reformatting was reported as a removal');
    }

    public function testAFileWithSomethingDangerousIsNotClean(): void {
        foreach ([
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
            '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect width="9" height="9"/></svg>',
            '<svg xmlns="http://www.w3.org/2000/svg"><image href="http://evil.example/x.png"/></svg>',
        ] as $payload) {
            $this->assertFalse($this->sanitizer->isClean($payload), "reported clean: $payload");
        }
    }

    /**
     * A doctype is how an entity attack is delivered and is something no drawing needs, so it
     * counts as dirty without having to work out what the entities would do
     */
    public function testADoctypeIsNeverClean(): void {
        $this->assertFalse($this->sanitizer->isClean(
            '<?xml version="1.0"?><!DOCTYPE svg><svg xmlns="http://www.w3.org/2000/svg"><rect width="9" height="9"/></svg>'
        ));
    }

    public function testUnparseableContentIsNotClean(): void {
        $this->assertFalse($this->sanitizer->isClean('<svg><unclosed></svg>'));
        $this->assertFalse($this->sanitizer->isClean(''));
    }

    /**
     * Whatever `sanitize()` produced has to come back clean, or `media:sanitize` would rewrite
     * the same files on every run
     */
    public function testSanitisedOutputIsClean(): void {
        $output = $this->sanitize(
            '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)">'
            .'<script>alert(2)</script><rect width="9" height="9"/></svg>'
        );
        $this->assertTrue($this->sanitizer->isClean($output));
    }

    /**
     * Sanitising twice has to give the same thing, or `media:sanitize` would report every file as
     * changed forever
     */
    public function testItIsIdempotent(): void {
        $once = $this->sanitize(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><rect width="24" height="24"/></svg>'
        );
        $this->assertSame($once, $this->sanitize($once));
    }
}
