<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Controller\Admin\ContentAdminController;
use Dynart\Dpress\Plugin\AbstractPlugin;
use Dynart\Dpress\Service\ContentService;
use Dynart\Dpress\Theme\PageTemplates;
use PHPUnit\Framework\TestCase;

/**
 * A page template that came with a plugin (0.96.0)
 *
 * Until this only a theme could offer one, by having a file - so a plugin whose whole point is a
 * kind of page ended with "now copy this into your theme".
 *
 * @covers \Dynart\Dpress\Theme\PageTemplates
 * @covers \Dynart\Dpress\Controller\Admin\ContentAdminController
 */
final class PluginPageTemplateTest extends TestCase {

    public function testATemplateIsKeptUnderItsName(): void {
        $templates = new PageTemplates();
        $this->assertTrue($templates->add('music-player', 'Music player', 'music_list:page-player', 'music-list'));
        $this->assertTrue($templates->has('music-player'));
        $this->assertSame('music_list:page-player', $templates->templateOf('music-player'));
        $this->assertNull($templates->templateOf('gallery'));
    }

    /** A page stores the name and `ContentService::templateName()` decides what a name is */
    public function testANameAPageCouldNotStoreIsRefused(): void {
        $templates = new PageTemplates();
        foreach (['Bad_Name', '../layout', '', '-leading', str_repeat('a', 65)] as $name) {
            $this->assertFalse($templates->add($name, 'x', 'ns:view'), "'$name'");
            $this->assertNull(ContentService::templateName($name), "'$name' - the two rules are one rule");
        }
        $this->assertTrue($templates->add(str_repeat('a', 64), 'x', 'ns:view'));
        $this->assertSame([str_repeat('a', 64) => 'x'], $templates->labels());
    }

    public function testATemplateWithNoViewIsRefused(): void {
        $this->assertFalse((new PageTemplates())->add('player', 'Player', ''));
    }

    public function testAMissingLabelIsMadeFromTheNameTheWayAThemesIs(): void {
        $templates = new PageTemplates();
        $templates->add('wide-page', '', 'ns:wide');
        $this->assertSame(['wide-page' => 'Wide page'], $templates->labels());
    }

    public function testLabelsAreInOrder(): void {
        $templates = new PageTemplates();
        $templates->add('b', 'Zebra', 'ns:b');
        $templates->add('a', 'apple', 'ns:a');
        $this->assertSame(['a' => 'apple', 'b' => 'Zebra'], $templates->labels());
    }

    public function testTheEditorOffersTheThemesAndThePluginsTogether(): void {
        $this->assertSame(
            ['' => 'Default', 'music-player' => 'Music player', 'gallery' => 'Photo gallery'],
            ContentAdminController::templateOptions(['gallery' => 'Photo gallery'], ['music-player' => 'Music player'], '')
        );
    }

    /** The theme's file is the one rendered, so it is the theme's label that is true */
    public function testTheThemesLabelWinsANameBothHave(): void {
        $options = ContentAdminController::templateOptions(
            ['music-player' => 'Our player'], ['music-player' => 'Music player'], 'music-player'
        );
        $this->assertSame(['' => 'Default', 'music-player' => 'Our player'], $options);
    }

    /** A plugin's template alone is enough for the select to appear - it shows from two options up */
    public function testAPluginsTemplateIsOfferedWithNoThemeAtAll(): void {
        $this->assertCount(2, ContentAdminController::templateOptions([], ['music-player' => 'Music player'], ''));
    }

    public function testATemplateNobodyHasAnyMoreStaysOnTheListMarked(): void {
        $options = ContentAdminController::templateOptions([], [], 'music-player');
        $this->assertStringContainsString('the default is used', $options['music-player']);
    }

    public function testAPluginOffersNoneUnlessItSaysSo(): void {
        $plugin = new class extends AbstractPlugin {};
        $this->assertSame([], $plugin->pageTemplates());
    }
}
