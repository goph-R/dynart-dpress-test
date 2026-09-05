<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Content\PageContext;
use Dynart\Dpress\DpressServices;
use Dynart\Dpress\Entity\Content;
use Dynart\Micro\Session;
use Dynart\Micro\Request;
use Dynart\Dpress\Test\StubTranslation;
use Dynart\Dpress\Test\RecordingEvents;
use Dynart\Dpress\Form\FormFactory;
use Dynart\Dpress\Form\AdminForms;
use Dynart\Dpress\Entity\Setting;
use Dynart\Dpress\Service\SettingFields;
use Dynart\Dpress\Test\StubLogger;
use Dynart\Dpress\Theme\ThemeService;
use PHPUnit\Framework\TestCase;

/**
 * The three seams a comments plugin needs, and nothing else could reach
 *
 * Each closes a hardcoded list where the rest of the CMS has a registry, and each is worth having
 * on its own: somewhere under a post to render, a way to know which post that is, and a settings
 * field that is actually saved.
 *
 * @covers \Dynart\Dpress\Content\PageContext
 * @covers \Dynart\Dpress\Service\SettingFields
 */
class PageContextTest extends TestCase {

    // --- what the page is about ---

    /**
     * Empty is the normal case: the front page, an archive and every admin screen have no content
     * of their own, and a block that needs one should render nothing there rather than guess
     */
    public function testAPageIsAboutNothingUntilSomethingSaysOtherwise(): void {
        $context = new PageContext();
        $this->assertFalse($context->has());
        $this->assertNull($context->content());
        $this->assertSame(0, $context->id());
        $this->assertFalse($context->isPost());
    }

    public function testItAnswersWithWhatIsBeingViewed(): void {
        $post = new Content();
        $post->id = 42;
        $post->type = Content::TYPE_POST;

        $context = new PageContext();
        $context->set($post);
        $this->assertTrue($context->has());
        $this->assertSame(42, $context->id());
        $this->assertTrue($context->isPost());
        $this->assertSame($post, $context->content());
    }

    public function testAPageIsNotAPost(): void {
        $page = new Content();
        $page->id = 7;
        $page->type = Content::TYPE_PAGE;

        $context = new PageContext();
        $context->set($page);
        $this->assertTrue($context->has());
        $this->assertFalse($context->isPost());
    }

    // --- somewhere under a post to render ---

    /**
     * One place and not two, so "comments on pages as well" is a question about where the block is
     * put rather than a second name every theme has to declare
     */
    public function testThereIsAPlaceUnderTheContent(): void {
        $this->assertArrayHasKey('after_content', ThemeService::BUILT_IN_PLACES);
    }

    public function testBothContentTemplatesDrawIt(): void {
        foreach (['single', 'page'] as $template) {
            $path = dirname(__DIR__, 3).'/dynart-dpress/views/content/'.$template.'.phtml';
            $this->assertStringContainsString(
                "\$places->render('after_content')",
                (string)file_get_contents($path),
                "content/$template.phtml does not draw the after_content place"
            );
        }
    }

    // --- a setting a plugin can add ---

    private function fields(): SettingFields {
        $fields = new SettingFields(new StubLogger());
        DpressServices::registerSettingFields($fields);
        return $fields;
    }

    public function testTheCoreSettingsAreRegisteredRatherThanConstant(): void {
        $types = $this->fields()->types();
        $this->assertSame('bool', $types[Setting::REGISTRATION_OPEN] ?? null);
        $this->assertSame('media', $types[Setting::SITE_LOGO] ?? null);
        $this->assertSame('int', $types[Setting::POSTS_PER_PAGE] ?? null);
        $this->assertSame('string', $types[Setting::SITE_NAME] ?? null);
    }

    /**
     * The whole point: a setting added from outside is written like any other. Before this it
     * rendered, took a value, and was silently dropped on save.
     */
    public function testAPluginCanAddOneAndItIsWritten(): void {
        $fields = $this->fields();
        $fields->add('disqus_shortname', 'string', ['type' => 'text', 'label' => 'Disqus shortname']);
        $this->assertTrue($fields->has('disqus_shortname'));
        $this->assertSame('string', $fields->types()['disqus_shortname']);
        $this->assertArrayHasKey('disqus_shortname', $fields->formFields());
    }

    /**
     * Core's own bring no field definition - the settings form builds those by hand, because they
     * need lists only the controller can fetch - so only a plugin's reach the form builder
     */
    public function testOnlyTheOnesThatBroughtAFieldAppearInTheFormFields(): void {
        $fields = $this->fields();
        $this->assertSame([], $fields->formFields());
    }

    /**
     * And it reaches the Settings screen, which is the other half of "one call"
     *
     * The registry decides what is *written*; the form builder has to be handed the field or
     * there is nothing on the screen to write from. Both halves or neither.
     */
    public function testARegisteredFieldReachesTheSettingsForm(): void {
        $factory = new FormFactory(new Request(), new Session(), new RecordingEvents(), new StubTranslation());
        AdminForms::register($factory);
        $form = $factory->create(AdminForms::SETTINGS, [
            'themes' => ['' => 'Built in'],
            'registered_fields' => [
                'disqus_shortname' => ['type' => 'text', 'label' => 'Disqus shortname',
                                       'required' => false],
            ],
        ]);
        $this->assertArrayHasKey('disqus_shortname', $form->fields());
        // never required: one added by a plugin would stop anybody saving the screen
        $this->assertFalse($form->required('disqus_shortname'));
    }

    /**
     * A type nobody handles is a setting that reads back as whatever the `match` falls through to,
     * so it is refused and said out loud rather than stored
     */
    public function testATypeNobodyHandlesIsRefused(): void {
        $logger = new StubLogger();
        $fields = new SettingFields($logger);
        $fields->add('odd', 'colour');
        $this->assertFalse($fields->has('odd'));
    }
}
