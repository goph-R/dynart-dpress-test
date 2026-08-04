<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Content\Slugger;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Dynart\Dpress\Content\Slugger
 */
class SluggerTest extends TestCase {

    private Slugger $slugger;

    protected function setUp(): void {
        $this->slugger = new Slugger();
    }

    public function testLowercasesAndHyphenates(): void {
        $this->assertSame('hello-dpress', $this->slugger->slugify('Hello, dpress'));
    }

    public function testCollapsesRunsOfSeparators(): void {
        $this->assertSame('a-b', $this->slugger->slugify('a   ---  b'));
    }

    public function testTrimsLeadingAndTrailingSeparators(): void {
        $this->assertSame('hello', $this->slugger->slugify('  ...hello!!!  '));
    }

    /**
     * Folded to their ASCII base rather than dropped, or a Hungarian title would come out as a
     * row of hyphens.
     */
    public function testFoldsAccentedCharacters(): void {
        $this->assertSame('arvizturo-tukorfurogep', $this->slugger->slugify('Árvíztűrő tükörfúrógép'));
    }

    public function testFoldsOtherEuropeanAccents(): void {
        $this->assertSame('creme-brulee', $this->slugger->slugify('Crème brûlée'));
        $this->assertSame('strasse', $this->slugger->slugify('Straße'));
    }

    public function testKeepsDigits(): void {
        $this->assertSame('top-10-things', $this->slugger->slugify('Top 10 things'));
    }

    public function testATitleWithNothingUsableGivesAnEmptySlug(): void {
        $this->assertSame('', $this->slugger->slugify('!!!'));
    }

    public function testTruncatesToTheColumnLength(): void {
        $slug = $this->slugger->slugify(str_repeat('a', 300));
        $this->assertLessThanOrEqual(Slugger::MAX_LENGTH, mb_strlen($slug));
    }

    // --- unique() ---

    public function testUniqueReturnsTheBaseWhenItIsFree(): void {
        $this->assertSame('hello', $this->slugger->unique('Hello', fn($c) => false));
    }

    public function testUniqueAppendsACounterWhenTaken(): void {
        $taken = ['hello' => true];
        $this->assertSame('hello-2', $this->slugger->unique('Hello', fn($c) => isset($taken[$c])));
    }

    public function testUniqueKeepsCountingPastTheSecond(): void {
        $taken = ['hello' => true, 'hello-2' => true, 'hello-3' => true];
        $this->assertSame('hello-4', $this->slugger->unique('Hello', fn($c) => isset($taken[$c])));
    }

    /**
     * A title of nothing but punctuation still has to produce a usable, unique slug rather than
     * an empty one, which the unique column would reject on the second attempt.
     */
    public function testUniqueFallsBackWhenTheTitleSlugifiesToNothing(): void {
        $this->assertSame('item', $this->slugger->unique('!!!', fn($c) => false));
        $taken = ['item' => true];
        $this->assertSame('item-2', $this->slugger->unique('!!!', fn($c) => isset($taken[$c])));
    }
}
