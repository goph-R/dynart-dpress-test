<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Controller\AbstractController;
use PHPUnit\Framework\TestCase;

/**
 * WordPress's template hierarchy, for categories, tags, pages and posts (0.89.0)
 *
 * A theme gives one of them a layout of its own with a file named after its slug or its id, and
 * a whole kind with a file named after the kind - nothing to register.
 */
final class TemplateHierarchyTest extends TestCase {

    public function testTheSlugThenTheIdThenTheKindThenTheFallback(): void {
        $this->assertSame(
            ['dpress:content/category-retro', 'dpress:content/category-5', 'dpress:content/category', 'dpress:content/list'],
            AbstractController::templateCandidates('dpress:content/category', 'retro', 5, 'dpress:content/list')
        );
    }

    public function testAPostOrAPageHasNoFallbackBeyondItsKind(): void {
        $this->assertSame(
            ['dpress:content/page-about', 'dpress:content/page-12', 'dpress:content/page'],
            AbstractController::templateCandidates('dpress:content/page', 'about', 12)
        );
    }

    /** a slug goes into a file path, so anything but a plain one is left out rather than trusted */
    public function testAnOddSlugIsLeftOut(): void {
        foreach (['../../etc/passwd', 'Retro', 'a/b', '', '-x', 'árvíztűrő'] as $slug) {
            $this->assertSame(
                ['dpress:content/tag-3', 'dpress:content/tag'],
                AbstractController::templateCandidates('dpress:content/tag', $slug, 3),
                $slug
            );
        }
    }

    public function testNoIdIsNoIdTemplate(): void {
        $this->assertSame(['dpress:content/single-x', 'dpress:content/single'], AbstractController::templateCandidates('dpress:content/single', 'x', 0));
    }
}
