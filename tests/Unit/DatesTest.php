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

    // --- and a date somebody typed, going the other way ---

    /**
     * The case the field exists for: a post written in 2014 and brought over today keeps the date
     * it went out on, because the archive, the ordering and the byline all read that column
     */
    public function testADateIsReadInTheSitesTimezoneAndStoredAsUtc(): void {
        $this->assertSame('2014-03-09 00:00:00', $this->dates()->parse('2014-03-09'));
        // midnight in Budapest is 23:00 the day before in UTC, which is what goes in the column
        $this->assertSame(
            '2014-03-08 23:00:00',
            $this->dates('', 'Europe/Budapest')->parse('2014-03-09')
        );
    }

    /**
     * A time when the time matters: two posts on the same day have to keep their order
     */
    public function testATimeMayFollowTheDate(): void {
        $dates = $this->dates();
        $this->assertSame('2014-03-09 14:30:00', $dates->parse('2014-03-09 14:30'));
        $this->assertSame('2014-03-09 14:30:37', $dates->parse('2014-03-09 14:30:37'));
    }

    /**
     * The date-only form is **midnight**, not midnight with the seconds of whenever the save
     * happened to run - which is what the format would mean without its leading bang
     */
    public function testADateWithNoTimeIsMidnightAndNotNow(): void {
        $this->assertStringEndsWith(' 00:00:00', (string)$this->dates()->parse('2014-03-09'));
    }

    /**
     * Everything `strtotime()` would have accepted and should not have
     *
     * `02/01/1999` is the second of January in half the world and the first of February in the
     * other half; `2014-03-09 potato` is a date with a modifier it read as nothing; and
     * `2014-13-45` is one `createFromFormat()` alone rolls forward into 2015 rather than refusing.
     * A typo has to come back as a typo.
     */
    public function testWhatCannotBeReadIsRefusedRatherThanGuessedAt(): void {
        foreach (['02/01/1999', '2014-03-09 potato', '2014-13-45', '9 March 2014', 'yesterday',
                  '20140309', '2014-03-09T14:30', '2014-03-09 14', 'x'] as $typed) {
            $this->assertNull($this->dates()->parse($typed), $typed);
        }
    }

    /**
     * A leading zero is not required, and asking for one would be pedantry: `2014-3-9` says
     * the ninth of March and nothing else. It is the *order* of the three numbers that has to
     * be beyond doubt, which is what refusing `02/01/1999` above is about.
     */
    public function testASingleDigitMonthOrDayIsFine(): void {
        $this->assertSame('2014-03-09 00:00:00', $this->dates()->parse('2014-3-9'));
    }

    /**
     * An empty box is not a date that failed to read - it means the field was left alone
     */
    public function testAnEmptyBoxIsNoDate(): void {
        foreach ([null, '', '   '] as $typed) {
            $this->assertNull($this->dates()->parse($typed), var_export($typed, true));
        }
    }

    // --- and back into the box it was typed into ---

    /**
     * What is shown is what would be saved again if nobody touched it, which is the only property
     * that matters here: opening a post and pressing Save must not move its date
     */
    public function testTheBoxRoundTripsExactly(): void {
        foreach (['UTC', 'Europe/Budapest', 'America/New_York'] as $zone) {
            $dates = $this->dates('F j, Y', $zone);
            foreach (['2014-03-08 23:00:00', '2026-01-06 08:00:00', '2026-07-01 12:34:56'] as $stored) {
                $this->assertSame($stored, $dates->parse($dates->input($stored)), $zone.' '.$stored);
            }
        }
    }

    /**
     * A date typed without a time comes back without one, rather than having grown an `00:00:00`
     * nobody wrote
     */
    public function testAMidnightMomentIsShownAsJustTheDate(): void {
        $dates = $this->dates('', 'Europe/Budapest');
        $this->assertSame('2014-03-09', $dates->input('2014-03-08 23:00:00'));
        $this->assertSame('2014-03-09 14:30:00', $dates->input('2014-03-09 13:30:00'));
    }

    public function testNothingStoredPutsNothingInTheBox(): void {
        $this->assertSame('', $this->dates()->input(null));
        $this->assertSame('', $this->dates()->input(''));
    }
}
