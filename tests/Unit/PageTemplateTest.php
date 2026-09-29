<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Entity\Content;
use Dynart\Dpress\Form\AdminForms;
use Dynart\Dpress\Form\FormFactory;
use Dynart\Micro\Request;
use Dynart\Micro\Session;
use Dynart\Dpress\Test\StubTranslation;
use Dynart\Dpress\Entity\Setting;
use Dynart\Dpress\Service\ContentService;
use Dynart\Dpress\Theme\ThemeService;
use Dynart\Dpress\Test\RecordingEvents;
use Dynart\Dpress\Test\StubConfig;
use Dynart\Dpress\Test\StubView;
use PHPUnit\Framework\TestCase;

/**
 * A page's template chosen in the editor (0.91.0) - WordPress's *Template* dropdown
 *
 * `PlacesSettings` is `ThemePlacesTest`'s settings stub.
 *
 * @covers \Dynart\Dpress\Theme\ThemeService
 * @covers \Dynart\Dpress\Service\ContentService
 */
final class PageTemplateTest extends TestCase {

    private string $themes;

    protected function setUp(): void {
        $this->themes = sys_get_temp_dir().'/dpress-themes-'.bin2hex(random_bytes(4));
        $content = $this->themes.'/site/dpress/content';
        mkdir($content, 0777, true);
        file_put_contents($this->themes.'/site/theme.ini', "title = \"Site\"\n");
        file_put_contents($content.'/page-template-gallery.phtml', "<?php\n/**\n * Template Name: Photo gallery\n */\n?>\n");
        file_put_contents($content.'/page-template-wide-page.phtml', '');
        file_put_contents($content.'/page-template-Bad_Name.phtml', '');
        file_put_contents($content.'/page.phtml', '');
    }

    protected function tearDown(): void {
        array_map('unlink', glob($this->themes.'/site/dpress/content/*'));
        unlink($this->themes.'/site/theme.ini');
        rmdir($this->themes.'/site/dpress/content');
        rmdir($this->themes.'/site/dpress');
        rmdir($this->themes.'/site');
        rmdir($this->themes);
    }

    private function service(string $active): ThemeService {
        $settings = new PlacesSettings();
        $settings->values[Setting::THEME] = $active;
        return new ThemeService(new StubConfig([ThemeService::CONFIG_PATH => $this->themes]), new StubView(), $settings, new RecordingEvents());
    }

    public function testTheThemesPageTemplatesAreOfferedByLabel(): void {
        $this->assertSame(
            ['gallery' => 'Photo gallery', 'wide-page' => 'Wide page'],
            $this->service('site')->pageTemplates()
        );
    }

    public function testNoThemeHasNoPageTemplates(): void {
        $this->assertSame([], $this->service('')->pageTemplates());
    }

    /** stored, and put into a file name again - so a name is plain, or no template at all */
    public function testATemplateNameIsPlainOrNothing(): void {
        $this->assertSame('gallery', ContentService::templateName(' gallery '));
        foreach (['', null, '../page', 'Gallery', 'a/b', '-x', str_repeat('a', 65)] as $value) {
            $this->assertNull(ContentService::templateName($value), (string)$value);
        }
    }

    private function pageForm(array $templates): array {
        $factory = new FormFactory(new Request(), new Session(), new RecordingEvents(), new StubTranslation());
        AdminForms::register($factory);
        return $factory->create(AdminForms::CONTENT, ['is_page' => true, 'page_templates' => $templates])->fields();
    }

    public function testThePageEditorOffersTheTemplates(): void {
        $fields = $this->pageForm(['' => 'Default', 'gallery' => 'Photo gallery']);
        $this->assertSame('select', $fields['template']['type'] ?? null);
        $this->assertSame(['' => 'Default', 'gallery' => 'Photo gallery'], $fields['template']['options']);
    }

    /** a select whose one option is Default is a question with one answer */
    public function testATemplateFieldOnlyWhenThereIsAChoice(): void {
        $this->assertArrayNotHasKey('template', $this->pageForm(['' => 'Default']));
        $this->assertArrayNotHasKey('template', $this->pageForm([]));
    }

    public function testATemplateIsRestoredWithARevision(): void {
        $this->assertContains('template', ContentService::REVISION_FIELDS);
        $this->assertNull((new Content())->template);
    }
}
