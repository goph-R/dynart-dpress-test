<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Micro\Entities\EntityManager;
use Dynart\Micro\ViewInterface;
use Dynart\Dpress\Block\TagCloudBlock;
use Dynart\Dpress\Entity\Block;
use Dynart\Dpress\Entity\Setting;
use Dynart\Dpress\Query\CoreQueries;
use Dynart\Dpress\Service\TaxonomyService;
use PHPUnit\Framework\TestCase;

/**
 * The featured tag is machinery, and a visitor should not meet it
 *
 * `featured` is how an author pins a post to the front page. It is not a subject, nobody browsing
 * wants an archive of *"posts the author pinned"*, and in a tag cloud it is worse than clutter:
 * the cloud scales between the smallest and the largest total, and the featured tag sits on
 * whatever was pinned - often the highest count on the site, which squashes every real tag into
 * the bottom bucket.
 *
 * **Left out of the rows, not filtered after them**, which is what puts it out of the weight
 * calculation as well.
 *
 * @covers \Dynart\Dpress\Query\CoreQueries::tagCloud
 * @covers \Dynart\Dpress\Block\TagCloudBlock
 */
class FeaturedTagTest extends TestCase {

    // --- the query ---

    private function tagCloudQuery(array $context): \Dynart\Micro\Entities\Query {
        $em = $this->createMock(EntityManager::class);
        $em->method('safeTableName')->willReturn('`dp_tag`');
        $em->method('tableName')->willReturn('dp_tag');
        return (new CoreQueries($em))->tagCloud($context);
    }

    /** @return string every condition of the query, joined */
    private function conditionText(array $context): string {
        $text = '';
        foreach ($this->tagCloudQuery($context)->conditions() as $condition) {
            $text .= is_array($condition) ? join(' ', array_filter($condition, 'is_string')) : (string)$condition;
        }
        return $text;
    }

    public function testAnExcludedSlugBecomesACondition(): void {
        $this->assertStringContainsString('slug', $this->conditionText(['exclude_slug' => 'featured']));
        $this->assertContains('featured', $this->tagCloudQuery(['exclude_slug' => 'featured'])->variables());
    }

    /**
     * The join brings in a `content`, which has a `slug` of its own - MariaDB rejects the
     * unqualified name as ambiguous rather than guessing, which is the same reason the selected
     * fields are qualified two lines above it
     */
    public function testTheExcludedSlugIsQualified(): void {
        $this->assertStringContainsString(
            '`dp_tag`.`slug`',
            $this->conditionText(['exclude_slug' => 'featured'])
        );
    }

    public function testNoExclusionAsksForNothingExtra(): void {
        $bare = $this->conditionText([]);
        $this->assertStringNotContainsString('`slug`', $bare);
        $this->assertSame($bare, $this->conditionText(['exclude_slug' => '   ']), 'blank is not a slug');
    }

    // --- who asks for it ---

    /**
     * A service that answers what the block asked, and remembers what that was
     */
    private function taxonomy(array $tags, string $featured = 'featured'): TaxonomyService {
        return new class($tags, $featured) extends TaxonomyService {
            public array $context = [];
            public function __construct(public array $tags, public string $featured) {}
            public function featuredTagSlug(): string { return $this->featured; }
            public function tagCloud(array $context = []): array {
                $this->context = $context;
                return $this->tags;
            }
        };
    }

    public function testTheCloudAsksForTheFeaturedTagToBeLeftOut(): void {
        $taxonomy = $this->taxonomy([['name' => 'retro', 'slug' => 'retro', 'total' => 3]]);
        $view = $this->createMock(ViewInterface::class);
        $view->method('fetch')->willReturn('');
        (new TagCloudBlock($taxonomy, $view))->render(new Block(), []);
        $this->assertSame('featured', $taxonomy->context['exclude_slug'] ?? null);
    }

    /**
     * A site that has turned the featured strip off has no tag to hide, and an empty slug must
     * not become a condition that excludes a tag literally named ''
     */
    public function testASiteWithNoFeaturedTagExcludesNothing(): void {
        $taxonomy = $this->taxonomy([['name' => 'retro', 'slug' => 'retro', 'total' => 3]], '');
        $view = $this->createMock(ViewInterface::class);
        $view->method('fetch')->willReturn('');
        (new TagCloudBlock($taxonomy, $view))->render(new Block(), []);
        $this->assertSame('', $taxonomy->context['exclude_slug'] ?? null);
        $this->assertStringNotContainsString('`slug`', $this->conditionText(['exclude_slug' => '']));
    }

    /**
     * The asymmetry, and the reason the exclusion is opt-in rather than a default on `tagCloud()`
     *
     * `taxonomy:list` calls the same query. Hiding a tag from the listing somebody would use to
     * find out it exists is how it gets lost - an operator's view should show what is there.
     */
    public function testTheCliListingIsNotFilteredBecauseItAsksForNothing(): void {
        $source = file_get_contents(
            dirname(__DIR__, 3).'/dynart-dpress/src/Cli/TaxonomyCommands.php'
        );
        $this->assertStringContainsString('tagCloud()', (string)$source,
            'taxonomy:list must keep calling it bare, or the featured tag disappears from the CLI too');
    }

    /**
     * One place reads the setting, so a trim done in one caller and forgotten in another cannot
     * leave a tag named ` featured` visible to half the site
     */
    public function testTheSlugIsReadInOnePlaceAndTrimmed(): void {
        $settings = new PlacesSettings();
        $settings->values[Setting::FEATURED_TAG] = '  kiemelt  ';
        $taxonomy = new class($settings) extends TaxonomyService {
            public function __construct($settings) { $this->settings = $settings; }
        };
        $this->assertSame('kiemelt', $taxonomy->featuredTagSlug());
    }
}
