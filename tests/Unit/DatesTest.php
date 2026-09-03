<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Content\Dates;
use Dynart\Dpress\Entity\Setting;
use PHPUnit\Framework\TestCase;

/**
 * A stored moment, written the way a site writes dates
 *
 * Every timestamp in the database is UTC, which is right - a stored moment should not change
 * meaning when a site moves or a country changes its clocks - and it is exactly why a **format
 * cannot be added without a timezone beside it**. The templates used to print the first ten
 * characters of the column, and the day that produced is the UTC day: a post published at half
 * past midnight in Budapest was stored on the previous date and shown on it to everybody.
 *
 * @covers \Dynart\Dpress\Content\Dates
 */
class DatesTest extends TestCase {

    private function dates(string $format = '', string $timezone = ''): Dates {
        $settings = new PlacesSettings();
        if ($format !== '') {
            $settings->values[Setting::DATE_FORMAT] = $format;
        }
        if ($timezone !== '') {
            $settings->values[Setting::TIMEZONE] = $timezone;
        }
        return new Dates($settings);
    }

    // --- the timezone, which is the reason this class exists ---

    /**
     * The case that made a format alone worse than no format: 23:30 UTC is already tomorrow in
     * Budapest, so a post written just before midnight there was dated the day before
     */
    public function testTheDayIsTheSitesDayAndNotUtcs(): void {
        $stored = '2026-01-05 23:30:00';
        $this->assertSame('2026-01-05', $this->dates()->format($stored), 'UTC, as stored');
        $this->assertSame('2026-01-06', $this->dates('', 'Europe/Budapest')->format($stored));
    }

    /**
     * And the other way, which is the same bug from the other side
     */
    public function testAMomentCanBeYesterdayFurtherWest(): void {
        $this->assertSame(
            '2026-01-05',
            $this->dates('', 'America/New_York')->format('2026-01-06 02:00:00')
        );
    }

    /**
     * A settings screen can be typed into, and a clock setting that is a typo must not take every
     * URL on the site down - the same bargain a missing theme makes
     */
    public function testATimezoneThatDoesNotExistFallsBackToUtc(): void {
        $dates = $this->dates('', 'Middle/Earth');
        $this->assertSame('UTC', $dates->timezone()->getName());
        $this->assertSame('2026-01-05', $dates->format('2026-01-05 23:30:00'));
    }

    // --- the format ---

    public function testTheDefaultIsWhatTheTemplatesPrintedBefore(): void {
        $this->assertSame('2026-01-06', $this->dates()->format('2026-01-06 08:00:00'));
        $this->assertSame(Dates::DEFAULT_FORMAT, $this->dates()->siteFormat());
    }

    public function testTheSiteFormat(): void {
        $this->assertSame('January 6, 2026', $this->dates('F j, Y')->format('2026-01-06 08:00:00'));
    }

    public function testATemplateMayAskForItsOwnFormat(): void {
        $dates = $this->dates('F j, Y');
        $this->assertSame('2026', $dates->format('2026-01-06 08:00:00', 'Y'));
    }

    /**
     * An empty setting is a site that has not chosen, not a site that wants no date
     */
    public function testAnEmptyFormatIsTheDefault(): void {
        $this->assertSame('2026-01-06', $this->dates('   ')->format('2026-01-06 08:00:00'));
    }

    // --- nothing to print ---

    /**
     * A draft has no `published_at`, and a template asking for one should get nothing rather than
     * a date in 1970 that looks like a real one
     */
    public function testNothingStoredPrintsNothing(): void {
        foreach ([null, '', '   ', '0000-00-00 00:00:00', 'not a date'] as $stored) {
            $this->assertSame('', $this->dates()->format($stored), var_export($stored, true));
            $this->assertSame('', $this->dates()->iso($stored));
            $this->assertSame('', $this->dates()->tag($stored));
        }
    }

    // --- what a machine reads ---

    /**
     * The printed date can say anything the site likes; the attribute has to say what it means
     */
    public function testTheAttributeCarriesTheOffset(): void {
        $iso = $this->dates('F j, Y', 'Europe/Budapest')->iso('2026-01-06 08:00:00');
        $this->assertSame('2026-01-06T09:00:00+01:00', $iso);
    }

    public function testTheTagIsBothHalvesAtOnce(): void {
        $tag = $this->dates('F j, Y', 'Europe/Budapest')->tag('2026-01-06 08:00:00');
        $this->assertStringContainsString('datetime="2026-01-06T09:00:00+01:00"', $tag);
        $this->assertStringContainsString('>January 6, 2026</time>', $tag);
    }

    /**
     * The format is a **setting**, so somebody who may change settings decides what goes through
     * `format()` and straight into a page
     */
    public function testTheTagEscapesWhatTheFormatProduced(): void {
        $tag = $this->dates('\<\s\c\r\i\p\t\>')->tag('2026-01-06 08:00:00');
        $this->assertStringNotContainsString('<script>', $tag);
        $this->assertStringContainsString('&lt;script&gt;', $tag);
    }
}
