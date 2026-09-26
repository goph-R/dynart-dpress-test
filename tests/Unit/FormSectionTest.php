<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Controller\AbstractController;
use Dynart\Dpress\Entity\Content;
use Dynart\Dpress\Form\AdminForms;
use Dynart\Dpress\Form\DpressForm;
use Dynart\Dpress\Form\FormFactory;
use Dynart\Dpress\Test\RecordingEvents;
use Dynart\Dpress\Test\StubTranslation;
use Dynart\Dpress\Test\StubView;
use Dynart\Micro\Request;
use Dynart\Micro\Session;
use Dynart\Micro\ViewInterface;
use PHPUnit\Framework\TestCase;

/**
 * A form whose fields and sections render as markers, so the order is what is read back
 */
class MarkedForm extends DpressForm {
    public function fetchErrors(): string { return ''; }
    public function fetchField(string $name, array $field): string { return "[$name]"; }
    protected function view(): ViewInterface {
        return new StubView([DpressForm::VIEW_SECTION => '<{label}|{open}>{fields}</{label}>']);
    }
}

/**
 * Expandable sections of a form, and the additional CSS that lives in one
 *
 * A field joins a section by naming it, which is the whole API - so what is tested is where a
 * section lands among the loose fields, when it opens by itself, and that the core's forms put
 * the right things in them.
 *
 * @covers \Dynart\Dpress\Form\DpressForm
 * @covers \Dynart\Dpress\Form\AdminForms
 * @covers \Dynart\Dpress\Controller\AbstractController
 */
class FormSectionTest extends TestCase {

    private function form(array $fields): MarkedForm {
        $form = new MarkedForm(new Request(), new Session(), 'test', false);
        $form->addFields($fields, false);
        return $form;
    }

    // --- rendering ---

    /**
     * The loose fields first and then each section, in the order its first field was added -
     * not wherever that field happened to fall, or a plugin's field added last would decide it
     */
    public function testLooseFieldsComeFirstThenEachSection(): void {
        $form = $this->form([
            'title'  => ['type' => 'text'],
            'weight' => ['type' => 'text', 'section' => 'Advanced'],
            'body'   => ['type' => 'text'],
            'shortname' => ['type' => 'text', 'section' => 'Comments'],
            'css'    => ['type' => 'text', 'section' => 'Advanced'],
        ]);
        $this->assertSame('[title][body]<Advanced|>[weight][css]</Advanced><Comments|>[shortname]</Comments>', $form->fetch());
    }

    public function testAFormWithNoSectionsIsTheFieldsAsBefore(): void {
        $this->assertSame('[a][b]', $this->form(['a' => [], 'b' => []])->fetch());
    }

    /**
     * Closed, unless a field inside has an error - a message folded away is a form that refuses
     * to save and will not say why
     */
    public function testASectionWithAnErrorOpensByItself(): void {
        $form = $this->form(['weight' => ['type' => 'text', 'section' => 'Advanced']]);
        $this->assertSame('<Advanced|>[weight]</Advanced>', $form->fetch());
        $form->addFieldError('weight', 'A whole number, please.');
        $this->assertSame('<Advanced|1>[weight]</Advanced>', $form->fetch());
    }

    // --- rows ---

    /**
     * Fields next to each other that name the same row are one row
     */
    public function testNeighboursInTheSameRowShareOne(): void {
        $form = $this->form([
            'title'        => [],
            'status'       => ['row' => 'publication'],
            'published_at' => ['row' => 'publication'],
            'author_id'    => [],
        ]);
        $this->assertSame('[title]<div class="form-row">[status][published_at]</div>[author_id]', $form->fetch());
    }

    /**
     * A row is something seen, so two fields with a third between them are not beside each
     * other whatever they are called - and a row of one is just the field
     */
    public function testARowIsOnlyTheFieldsNextToEachOther(): void {
        $form = $this->form([
            'a' => ['row' => 'r'],
            'b' => [],
            'c' => ['row' => 'r'],
        ]);
        $this->assertSame('[a][b][c]', $form->fetch());
    }

    public function testTwoRowsInARowAreTwoRows(): void {
        $form = $this->form([
            'a' => ['row' => 'one'], 'b' => ['row' => 'one'],
            'c' => ['row' => 'two'], 'd' => ['row' => 'two'],
        ]);
        $this->assertSame('<div class="form-row">[a][b]</div><div class="form-row">[c][d]</div>', $form->fetch());
    }

    public function testRowsWorkInsideASectionToo(): void {
        $form = $this->form([
            'x' => ['section' => 'Advanced', 'row' => 'r'],
            'y' => ['section' => 'Advanced', 'row' => 'r'],
        ]);
        $this->assertSame('<Advanced|><div class="form-row">[x][y]</div></Advanced>', $form->fetch());
    }

    // --- where the core puts things ---

    public function testStatusAndPublishedShareARow(): void {
        $fields = $this->factory()->create(AdminForms::CONTENT, ['is_page' => false, 'can_publish' => true])->fields();
        $this->assertSame('publication', $fields['status']['row'] ?? null);
        $this->assertSame('publication', $fields['published_at']['row'] ?? null);
        $this->assertSame(['status', 'published_at'], array_values(array_filter(
            array_keys($fields), fn($name) => ($fields[$name]['row'] ?? '') === 'publication'
        )));
    }

    private function factory(): FormFactory {
        $factory = new FormFactory(new Request(), new Session(), new RecordingEvents(), new StubTranslation());
        AdminForms::register($factory);
        return $factory;
    }

    public function testTheEditorKeepsTheWeightAndTheCssInAdvanced(): void {
        $fields = $this->factory()->create(AdminForms::CONTENT, ['is_page' => false])->fields();
        $this->assertSame(AdminForms::SECTION_ADVANCED, $fields['weight']['section'] ?? null);
        $this->assertSame(AdminForms::SECTION_ADVANCED, $fields['css']['section'] ?? null);
        $this->assertSame('textarea', $fields['css']['type']);
        $this->assertFalse($this->factory()->create(AdminForms::CONTENT, ['is_page' => true])->required('css'));
    }

    public function testThePostSettingsAreAPostSection(): void {
        $fields = $this->factory()->create(AdminForms::SETTINGS, [])->fields();
        foreach (['autolink', 'posts_per_page', 'feed_items', 'post_path', 'featured_tag'] as $name) {
            $this->assertSame(AdminForms::SECTION_POST, $fields[$name]['section'] ?? null, $name);
        }
        $this->assertArrayNotHasKey('section', $fields['site_name']);
    }

    // --- the additional CSS on the page ---

    public function testNoCssAddsNothing(): void {
        $this->assertSame('', AbstractController::contentStyle(null));
        $this->assertSame('', AbstractController::contentStyle("  \n "));
    }

    public function testCssIsPutInAStyleElement(): void {
        $this->assertSame(
            '<style data-content-css>table { width: 100%; }</style>',
            AbstractController::contentStyle('table { width: 100%; }')
        );
    }

    /**
     * A `<style>` ends at the first `</style` wherever it stands, so without this a string in the
     * CSS could close the element and put a script on the page
     */
    public function testTheStyleCannotBeClosedFromInside(): void {
        $style = AbstractController::contentStyle('a::after { content: "</style><script>alert(1)</script>"; }');
        // the element's own end tag and no other
        $this->assertSame(1, substr_count(strtolower($style), '</style'));
        $this->assertStringEndsWith('</style>', $style);
        $this->assertStringContainsString('<\/style><script>alert(1)<\/script>', $style);
    }

    public function testANewPostHasNoCss(): void {
        $this->assertNull((new Content())->css);
    }
}
