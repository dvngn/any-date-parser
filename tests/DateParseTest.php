<?php

namespace Devengine\Tests;

use Carbon\Carbon;
use Devengine\AnyDateParser\DateParser;
use PHPUnit\Framework\TestCase;

class DateParseTest extends TestCase
{
    public function testConstructs(): void
    {
        $dateParser = new DateParser('2021-01-01');

        $this->assertInstanceOf(DateParser::class, $dateParser);

        $dateParser = DateParser::new('2021-01-01');

        $this->assertInstanceOf(DateParser::class, $dateParser);
    }

    public function testCanSetPreferenceOfMonthPosition(): void
    {
        $dateParser = DateParser::new('03/04/2014');

        // prefer month set to true by default
        $this->assertTrue($dateParser->isMonthPreferredAsFirst());

        $this->assertSame(3, $dateParser->parseSilent()->month);
        $this->assertSame(4, $dateParser->parseSilent()->day);

        $dateParser->preferMonthFirst(false);
        $this->assertFalse($dateParser->isMonthPreferredAsFirst());

        $this->assertSame(4, $dateParser->parseSilent()->month);
        $this->assertSame(3, $dateParser->parseSilent()->day);
    }

    public function testParsesUnknownDateFormat(): void
    {
        $dateStrings = [
            // mm/dd/yy
            "3/31/2014" => "M/DD/YYYY",
            "03/31/2014" => "MM/DD/YYYY",
            "08/21/71" => "MM/DD/YY",
            "8/1/71" => "M/D/YY",
            // yyyy/mm/dd
            "2014/3/31" => "YYYY/M/DD",
            "2014/03/31" => "YYYY/MM/DD",
            "2014-04-26" => "YYYY-MM-DD",
            // mm.dd.yy
            "3.31.2014" => "M.DD.YYYY",
            "03.31.2014" => "MM.DD.YYYY",
            "08.21.71" => "MM.DD.YY",
            "2014.03" => "YYYY.MM",
            "2014.03.30" => "YYYY.MM.DD",

            "oct 7, 1970" => "MMM D, YYYY",
            "oct 7, '70" => "MMM D, 'YY",
            "oct. 7, 1970" => "MMM. D, YYYY",
            "oct. 7, 70" => "MMM. D, YY",
            "October 7, 1970" => "MMMM D, YYYY",
            "October 7th, 1970" => "MMMM D, YYYY",
            "7 oct 70" => "D MMM YY",
            "7 oct 1970" => "D MMM YYYY",
            "03 February 2013" => "DD MMMM YYYY",
            "1 July 2013" => "D MMMM YYYY",
            "2013-Feb-03" => "YYYY-MMM-DD",
            "30/04/2025" => "DD/MM/YYYY",
        ];

        foreach ($dateStrings as $dateString => $format) {
            $dateParser = new DateParser($dateString);

            $dateParser->parseStrict();

            $this->assertEquals($format, $dateParser->getFormat(), $dateString);
        }
    }

    public function testConstructsCarbonInstance(): void
    {
        $dateStrings = [
            // mm/dd/yy
            "3/31/2014" => Carbon::createFromIsoFormat("M/DD/YYYY", "3/31/2014"),
            "03/31/2014" => Carbon::createFromIsoFormat("MM/DD/YYYY", "03/31/2014"),
            "08/21/71" => Carbon::createFromIsoFormat("MM/DD/YY", "08/21/71"),
            "8/1/71" => Carbon::createFromIsoFormat("M/D/YY", "8/1/71"),
            // yyyy/mm/dd
            "2014/3/31" => Carbon::createFromIsoFormat("YYYY/M/DD", "2014/3/31"),
            "2014/03/31" => Carbon::createFromIsoFormat("YYYY/MM/DD", "2014/03/31"),
            "2014-04-26" => Carbon::createFromIsoFormat("YYYY-MM-DD", "2014-04-26"),
            // mm.dd.yy
            "3.31.2014" => Carbon::createFromIsoFormat("M.DD.YYYY", "3.31.2014"),
            "03.31.2014" => Carbon::createFromIsoFormat("MM.DD.YYYY", "03.31.2014"),
            "08.21.71" => Carbon::createFromIsoFormat("MM.DD.YY", "08.21.71"),
            "2014.03" => Carbon::createFromIsoFormat("YYYY.MM", "2014.03"),
            "2014.03.30" => Carbon::createFromIsoFormat("YYYY.MM.DD", "2014.03.30"),

            "oct 7, 1970" => Carbon::createFromIsoFormat("MMM D, YYYY", "oct 7, 1970"),
            "oct 7, '70" => Carbon::createFromIsoFormat("MMM D, 'YY", "oct 7, '70"),
            "oct. 7, 1970" => Carbon::createFromIsoFormat("MMM. D, YYYY", "oct. 7, 1970"),
            "oct. 7, 70" => Carbon::createFromIsoFormat("MMM. D, YY", "oct. 7, 70"),
            "October 7, 1970" => Carbon::createFromIsoFormat("MMMM D, YYYY", "October 7, 1970"),
            "October 7th, 1970" => Carbon::createFromIsoFormat("MMMM D, YYYY", "October 7, 1970"),
            "7 oct 70" => Carbon::createFromIsoFormat("D MMM YY", "7 oct 70"),
            "7 oct 1970" => Carbon::createFromIsoFormat("D MMM YYYY", "7 oct 1970"),
            "03 February 2013" => Carbon::createFromIsoFormat("DD MMMM YYYY", "03 February 2013"),
            "1 July 2013" => Carbon::createFromIsoFormat("D MMMM YYYY", "1 July 2013"),
            "2013-Feb-03" => Carbon::createFromIsoFormat("YYYY-MMM-DD", "2013-Feb-03"),
        ];

        foreach ($dateStrings as $dateString => $expectedCarbon) {
            $dateParser = new DateParser($dateString);

            $carbonInstance = $dateParser->parseStrict();

            $this->assertInstanceOf(Carbon::class, $carbonInstance);

            $this->assertEquals($expectedCarbon, $carbonInstance);
        }
    }
}