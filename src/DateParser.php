<?php

declare(strict_types=1);

namespace Devengine\AnyDateParser;

use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;
use Devengine\AnyDateParser\Enum\DateStateEnum;
use Devengine\AnyDateParser\Exceptions\ParseException;

class DateParser
{
    protected string $dateStr;

    protected string $isoFormat = "";

    private array $dateChars;

    private DateStateEnum $dateState = DateStateEnum::DateStart;

    private bool $preferMonthFirst = true;

    private int $yearPos = 0;

    private int $yearLen = 0;

    private int $monthPos = 0;

    private int $monthLen = 0;

    private int $dayPos = 0;

    private int $dayLen = 0;

    private int $firstPartLen = 0;

    private string $fullMonth = "";

    private int $skipPos = 0;

    public function __construct(string $dateStr)
    {
        $this->dateStr = trim($dateStr);
        $this->dateChars = mb_str_split($this->dateStr) ?? [];
    }

    public static function new(string $dateStr): self
    {
        return new static($dateStr);
    }

    /**
     * Parse an unknown date string format.
     * Raises an exception when an unexpected position found.
     *
     * @return Carbon
     * @throws ParseException
     */
    public function parseStrict(): Carbon
    {
        $this->performDateParse();

        if ($this->preferMonthFirst && is_numeric($this->getMonthStr()) && !$this->isValidNumericMonth($this->getMonthStr())) {
            $this->preferMonthFirst = false;

            $this->performDateParse();
        }

        try {
            Carbon::createFromIsoFormat($this->getFormat(), $this->getDateStr());
        } catch (InvalidFormatException $e) {
            // Swap month with day and retry.
            $this->preferMonthFirst = !$this->preferMonthFirst;

            $this->performDateParse();
        }

        return Carbon::createFromIsoFormat($this->getFormat(), $this->getDateStr());
    }

    /**
     * Parse an unknown date string format.
     * Suppresses parse exceptions.
     *
     * @return Carbon|null
     */
    public function parseSilent(): ?Carbon
    {
        try {
            return $this->parseStrict();
        } catch (InvalidFormatException|ParseException $e) {
            return null;
        }

    }

    /**
     * @throws ParseException
     */
    protected function performDateParse(): void
    {
        $this->dateState = DateStateEnum::DateStart;
        $this->isoFormat = implode('', $this->dateChars);
        $this->yearPos = $this->yearLen = $this->monthPos = $this->monthLen = $this->dayLen = $this->dayPos = 0;
        $charsCount = count($this->dateChars);

        $i = 0;

        for (; $i < $charsCount; $i++) {
            $this->processDateState($i, $this->dateChars[$i]);

            if ($this->dateState === DateStateEnum::DateStartOver) {
                $this->performDateParse();
                return;
            }
        }

        $this->coalesceDate($i);

        if (!empty($this->fullMonth)) {
            $this->setFullMonthFormat();
        }

        switch ($this->dateState) {
            case DateStateEnum::DateYearDashAlphaDash:
                // 2013-Feb-03
                // 2013-Feb-3
                $this->dayLen = $i - $this->dayPos;
                $this->setDayFormat();
                break;

            case DateStateEnum::DateDigitWsMonthLong:
                // 18 January 2018
                // 8 January 2018
                if ($this->dayLen === 2) {
                    $this->isoFormat = 'DD MMMM YYYY';
                    break;
                }

                $this->isoFormat = 'D MMMM YYYY';

                break;
            default:
                break;
        }
    }

    public function getFormat(): string
    {
        return $this->isoFormat;
    }

    /**
     * @param int $i
     * @param string $char
     * @throws ParseException
     */
    private function processDateState(int &$i, string $char): void
    {
        match ($this->dateState) {
            DateStateEnum::DateStart => (function () use (&$i, $char): void {
                if (ctype_digit($char)) {
                    $this->dateState = DateStateEnum::DateDigit;
                } elseif (ctype_alpha($char)) {
                    $this->dateState = DateStateEnum::DateAlpha;
                } else {
                    throw ParseException::unexpectedDateStartChar();
                }
            })(),
            DateStateEnum::DateDigit => (function () use (&$i, $char): void {
                switch ($char) {
                    case '-':
                    case '\u2212':
                        // 2006-01-02
                        // 2013-Feb-03
                        // 13-Feb-03
                        // 29-Jun-2016
                        if ($i === 4) {
                            $this->dateState = DateStateEnum::DateYearDash;
                            $this->yearPos = 0;
                            $this->yearLen = $i;
                            $this->monthPos = $i + 1;
                            $this->setYearFormat();
                        } else {
                            $this->dateState = DateStateEnum::DateDigitDash;
                        }
                        break;
                    case '/':
                        // 03/31/2005
                        // 2014/02/24
                        $this->dateState = DateStateEnum::DateDigitSlash;

                        if ($i === 4) {
                            $this->yearPos = 0;
                            $this->yearLen = $i;
                            $this->monthPos = $i + 1;
                            $this->setYearFormat();
                        } else {
                            if ($this->preferMonthFirst && $this->monthLen === 0) {
                                $this->monthLen = $i;
                                $this->dayPos = $i + 1;
                                $this->setMonthFormat();
                            } elseif ($this->dayLen === 0) {
                                $this->dayLen = $i;
                                $this->monthPos = $i + 1;
                                $this->setDayFormat();
                            }
                        }
                        break;
                    case ':':
                        // 03/31/2005
                        // 2014/02/24
                        $this->dateState = DateStateEnum::DateDigitColon;

                        if ($i === 4) {
                            $this->yearLen = $i;
                            $this->monthPos = $i + 1;
                            $this->setYearFormat();
                        } else {
                            if ($this->monthLen === 0) {
                                $this->monthLen = $i;
                                $this->dayPos = $i + 1;
                                $this->setMonthFormat();
                            }
                        }
                        break;
                    case '.':
                        // 3.31.2014
                        // 08.21.71
                        // 2014.05
                        $this->dateState = DateStateEnum::DateDigitDot;

                        if ($i === 4) {
                            $this->yearLen = $i;
                            $this->monthPos = $i + 1;
                            $this->setYearFormat();
                        } else {
                            $this->monthPos = 0;
                            $this->monthLen = $i;
                            $this->dayPos = $i + 1;
                            $this->setMonthFormat();
                        }
                        break;
                    case ' ':
                        // 18 January 2018
                        // 8 January 2018
                        // 8 jan 2018
                        // 02 Jan 2018 23:59
                        // 02 Jan 2018 23:59:34
                        // 12 Feb 2006, 19:17
                        // 12 Feb 2006, 19:17:22
                        $this->dateState = DateStateEnum::DateDigitWs;
                        $this->dayPos = 0;
                        $this->dayLen = $i;
                        break;
                }
                $this->firstPartLen = $i;
            })(),
            DateStateEnum::DateDigitWs => (function () use (&$i, $char): void {
                // 18 January 2018
                // 8 January 2018
                // 8 jan 2018
                // 1 jan 18
                // 02 Jan 2018 23:59
                // 02 Jan 2018 23:59:34
                // 12 Feb 2006, 19:17
                // 12 Feb 2006, 19:17:22
                switch ($char) {
                    case ' ':
                        $this->yearPos = $i + 1;
                        $this->dayPos = 0;
                        $this->dayLen = $this->firstPartLen;
                        $this->setDayFormat();

                        if ($i > $this->dayLen + mb_strlen(' Sep')) {
                            // If len greater than space + 3 it must be full month
                            $this->dateState = DateStateEnum::DateDigitWsMonthLong;
                        } else {
                            // If len=3, the might be Feb or May?  Ie ambiguous abbreviated but
                            // we can parse may with either.  BUT, that means the
                            // format may not be correct?
                            $this->monthPos = $this->dayLen + 1;
                            $this->monthLen = $i - $this->monthPos;
                            $this->setMonthFormat();
                            $this->dateState = DateStateEnum::DateDigitWsMonthYear;
                        }
                        break;
                }
            })(),
            DateStateEnum::DateDigitWsMonthYear => (function () use (&$i, $char): void {
                // 8 jan 2018
                // 02 Jan 2018 23:59
                // 02 Jan 2018 23:59:34
                // 12 Feb 2006, 19:17
                // 12 Feb 2006, 19:17:2
                switch ($char) {
                    case ',':
                        $this->yearLen = $i - $this->yearPos;
                        $this->setYearFormat();
                        $i++;
                        break;
                    case ' ':
                        $this->yearLen = $i - $this->yearPos;
                        $this->setYearFormat();
                        break;
                }
            })(),
            DateStateEnum::DateYearDash => (function () use (&$i, $char): void {
                // dateYearDashDashT
                //  2006-01-02T15:04:05Z07:00
                // dateYearDashDashWs
                //  2013-04-01 22:43:22
                // dateYearDashAlphaDash
                //   2013-Feb-03
                switch ($char) {
                    case '-':
                        $this->monthLen = $i - $this->monthPos;
                        $this->dayPos = $i + 1;
                        $this->dateState = DateStateEnum::DateYearDashDash;
                        $this->setMonthFormat();
                        break;
                    default:
                        if (ctype_alpha($char)) {
                            $this->dateState = DateStateEnum::DateYearDashAlphaDash;
                        }
                }
            })(),
            DateStateEnum::DateYearDashAlphaDash => (function () use (&$i, $char): void {
                // 2013-Feb-03
                switch ($char) {
                    case '-':
                        $this->monthLen = $i - $this->monthPos;
                        $this->dayPos = $i + 1;
                        $this->setMonthFormat();
                }
            })(),
            DateStateEnum::DateDigitSlash => (function () use (&$i, $char): void {
                // 2014/07/10 06:55:38.156283
                // 03/19/2012 10:11:59
                // 04/2/2014 03:00:37
                // 3/1/2012 10:11:59
                // 4/8/2014 22:05
                // 3/1/2014
                // 10/13/2014
                // 01/02/2006
                // 1/2/06
                switch ($char) {
                    case ' ':
                        throw ParseException::notImplementedCase();
                    case '/':
                        if ($this->yearLen > 0) {
                            // 2014/07/10 06:55:38.156283
                            if ($this->monthLen === 0) {
                                $this->monthLen = $i - $this->monthPos;
                                $this->dayPos = $i + 1;
                                $this->setMonthFormat();
                            }
                        } elseif ($this->preferMonthFirst) {
                            if ($this->dayLen === 0) {
                                $this->dayLen = $i - $this->dayPos;
                                $this->yearPos = $i + 1;
                                $this->setDayFormat();
                            }
                        } else {
                            if ($this->monthLen === 0) {
                                $this->monthLen = $i - $this->monthPos;
                                $this->yearPos = $i + 1;
                                $this->setMonthFormat();
                            }
                        }
                        break;
                }
            })(),
            DateStateEnum::DateDigitDash => (function () use (&$i, $char): void {
                // 13-Feb-03
                // 29-Jun-2016
                if (ctype_alpha($char)) {
                    $this->dateState = DateStateEnum::DateDigitDashAlpha;
                    $this->monthPos = $i;
                } else {
                    throw ParseException::unexpectedCharPosition($i);
                }
            })(),
            DateStateEnum::DateDigitDashAlphaDash => (function () use (&$i, $char): void {
                // 13-Feb-03
                // 28-Feb-03
                // 29-Jun-2016
                switch ($char) {
                    case '-':
                        $this->monthLen = $i - $this->monthPos;
                        $this->yearPos = $i + 1;
//                        $this->dateState = self::DATE_DIGIT_DASH_ALPHA_DASH;
                }
            })(),
            DateStateEnum::DateDigitWsMonthLong => (function () use (&$i, $char): void {
                // 18 January 2018
                // 8 January 2018
            })(),
            DateStateEnum::DateDigitDot => (function () use (&$i, $char): void {
                // This is the 2nd period
                // 3.31.2014
                // 08.21.71
                // 2014.05
                // 2018.09.30
                switch ($char) {
                    case '.':
                        if ($this->monthPos === 0) {
                            // 3.31.2014
                            $this->dayLen = $i - $this->dayPos;
                            $this->yearPos = $i + 1;
                            $this->setDayFormat();
                            $this->dateState = DateStateEnum::DateDigitDotDot;
                        } else {
                            $this->monthLen = $i - $this->monthPos;
                            $this->dayPos = $i + 1;
                            $this->setMonthFormat();
                            $this->dateState = DateStateEnum::DateDigitDotDot;
                        }
                        break;
                }
            })(),
            DateStateEnum::DateDigitDotDot => (function () use (&$i, $char): void {
            })(),
            DateStateEnum::DateAlpha => (function () use (&$i, $char): void {
                // dateAlphaWS
                //  Mon Jan _2 15:04:05 2006
                //  Mon Jan _2 15:04:05 MST 2006
                //  Mon Jan 02 15:04:05 -0700 2006
                //  Mon Aug 10 15:44:11 UTC+0100 2015
                //  Fri Jul 03 2015 18:04:07 GMT+0100 (GMT Daylight Time)
                //  dateAlphaWSDigit
                //    May 8, 2009 5:57:51 PM
                //    oct 1, 1970
                //  dateAlphaWsMonth
                //    April 8, 2009
                //  dateAlphaWsMore
                //    dateAlphaWsAtTime
                //      January 02, 2006 at 3:04pm MST-07
                //
                //  dateAlphaPeriodWsDigit
                //    oct. 1, 1970
                // dateWeekdayComma
                //   Monday, 02 Jan 2006 15:04:05 MST
                //   Monday, 02-Jan-06 15:04:05 MST
                //   Monday, 02 Jan 2006 15:04:05 -0700
                //   Monday, 02 Jan 2006 15:04:05 +0100
                // dateWeekdayAbbrevComma
                //   Mon, 02 Jan 2006 15:04:05 MST
                //   Mon, 02 Jan 2006 15:04:05 -0700
                //   Thu, 13 Jul 2017 08:58:40 +0100
                //   Tue, 11 Jul 2017 16:28:13 +0200 (CEST)
                //   Mon, 02-Jan-06 15:04:05 MST
                switch ($char) {
                    case ' ':
                        //      X
                        // April 8, 2009
                        if ($i > 3) {
                            // Determine whether it's the alpha name of month or day.
                            $possibleMonthUnit = mb_strtolower(mb_substr($this->getDateStr(), 0, $i));

                            if ($this->isFullTextualMonth($possibleMonthUnit)) {
                                $this->fullMonth = $possibleMonthUnit;

                                // mb_strlen(" 31, 2018")   = 9
                                if (mb_strlen(mb_substr($this->getDateStr(), $i)) < 10) {
                                    $this->dateState = DateStateEnum::DateAlphaWsMonth;
                                } else {
                                    $this->dateState = DateStateEnum::DateAlphaWsMore;
                                }

                                $this->dayPos = $i + 1;
                                break;
                            }
                        } else {
                            // dateAlphaWs
                            //   May 05, 2005, 05:05:05
                            //   May 05 2005, 05:05:05
                            //   Jul 05, 2005, 05:05:05
                            //   May 8 17:57:51 2009
                            //   May  8 17:57:51 2009
                            // skip & return to dateStart
                            //   Tue 05 May 2020, 05:05:05
                            //   Mon Jan  2 15:04:05 2006
                            $possibleDayUnit = mb_strtolower(mb_substr($this->getDateStr(), 0, $i));

                            if ($this->isTextualWeekDay($possibleDayUnit)) {
                                $this->dateStr = mb_substr($this->dateStr, $i + 1);
                                $this->dateState = DateStateEnum::DateStartOver;
                                break;
                            }
                            $this->dateState = DateStateEnum::DateAlphaWs;
                        }
                        break;
                    case ',':
                        // Mon, 02 Jan 2006
                        // $this->monthPos = 0
                        // $this->monthLen = i
                        if ($i === 3) {
                            $this->dateState = DateStateEnum::DateWeekdayAbbrComma;
                            $this->monthPos = 0;
                            $this->setMonthFormat();
                        } else {
                            $this->dateState = DateStateEnum::DateWeekdayComma;
                            $this->skipPos = $i + 2;
                            $i++;
                            // TODO: implement skip pos
                        }
                        break;
                    case '.':
                        // sept. 28, 2017
                        // jan. 28, 2017
                        $this->dateState = DateStateEnum::DateAlphaPeriodWsDigit;
                        if ($i === 3) {
                            $this->monthLen = $i;
                            $this->monthPos = 0;
                            $this->setMonthFormat();
                        } elseif ($i === 4) {
                            array_splice($this->dateChars, $i);
                            $this->dateState = DateStateEnum::DateStartOver;
                        } else {
                            throw ParseException::unexpectedCharPosition($i);
                        }
                        break;
                }
            })(),
            DateStateEnum::DateAlphaWs => (function () use (&$i, $char): void {
                // dateAlphaWsAlpha
                //   Mon Jan _2 15:04:05 2006
                //   Mon Jan _2 15:04:05 MST 2006
                //   Mon Jan 02 15:04:05 -0700 2006
                //   Fri Jul 03 2015 18:04:07 GMT+0100 (GMT Daylight Time)
                //   Mon Aug 10 15:44:11 UTC+0100 2015
                // dateAlphaWsDigit
                //   May 8, 2009 5:57:51 PM
                //   May 8 2009 5:57:51 PM
                //   May 8 17:57:51 2009
                //   May  8 17:57:51 2009
                //   May 08 17:57:51 2009
                //   oct 1, 1970
                //   oct 7, '70
                if (ctype_alpha($char)) {
                    $this->monthPos = $i;
                    $this->monthLen = 3;
                    $this->dayPos = 0;
                    $this->dayLen = 3;
                    $this->setMonthFormat();
                    $this->setDayFormat();
                    $this->dateState = DateStateEnum::DateAlphaWsAlpha;
                } elseif (ctype_digit($char)) {
                    $this->monthPos = 0;
                    $this->monthLen = 3;
                    $this->dayPos = $i;
                    $this->setMonthFormat();
                    $this->dateState = DateStateEnum::DateAlphaWsDigit;
                }
            })(),
            DateStateEnum::DateAlphaWsMore => (function () use (&$i, $char): void {
                // January 02, 2006, 15:04:05
                // January 02 2006, 15:04:05
                // January 2nd, 2006, 15:04:05
                // January 2nd 2006, 15:04:05
                // September 17, 2012 at 5:00pm UTC-05
                switch (true) {
                    case $char === ',':
                        //           x
                        // January 02, 2006, 15:04:05
                        if ($this->nextCharIs($i, ' ')) {
                            $this->dayLen = $i - $this->dayPos;
                            $this->yearPos = $i + 2;
                            $this->dateState = DateStateEnum::DateAlphaWsMonthMore;
                            $this->setDayFormat();
                            $i++;
                        }
                        break;
                    case $char === ' ':
                        //           x
                        // January 02 2006, 15:04:05
                        $this->dayLen = $i - $this->dayPos;
                        $this->yearPos = $i + 1;
                        $this->setDayFormat();
                        $this->dateState = DateStateEnum::DateAlphaWsMonthMore;
                        break;
                    case ctype_digit($char):
                        //         XX
                        // January 02, 2006, 15:04:05
                        break;
                    case ctype_alpha($char):
                        $this->dayLen = $i - $this->dayPos;
                        $this->dateState = DateStateEnum::DateAlphaWsMonthSuffix;
                        $i--;
                        break;
                }
            })(),
            DateStateEnum::DateAlphaWsDigit => (function () use (&$i, $char): void {
                // May 8, 2009 5:57:51 PM
                // May 8 2009 5:57:51 PM
                // oct 1, 1970
                // oct 7, '70
                // oct. 7, 1970
                // May 8 17:57:51 2009
                // May  8 17:57:51 2009
                // May 08 17:57:51 2009
                if ($char === ',') {
                    $this->dayLen = $i - $this->dayPos;
                    $this->dateState = DateStateEnum::DateAlphaWsDigitMore;
                    $this->setDayFormat();
                } elseif ($char === ' ') {
                    $this->dayLen = $i - $this->dayPos;
                    $this->yearPos = $i + 1;
                    $this->dateState = DateStateEnum::DateAlphaWsDigitYearPossible;

                    $this->setDayFormat();
                } elseif (ctype_alpha($char)) {
                    $this->dateState = DateStateEnum::DateAlphaWsMonthSuffix;
                    $i--;
                }
            })(),
            DateStateEnum::DateAlphaWsDigitMore => (function () use (&$i, $char): void {
                //       x
                // May 8, 2009 5:57:51 PM
                // May 05, 2005, 05:05:05
                // May 05 2005, 05:05:05
                // oct 1, 1970
                // oct 7, '70
                if ($char === ' ') {
                    $this->yearPos = $i + 1;
                    $this->dateState = DateStateEnum::DateAlphaWsDigitMoreWs;
                }
            })(),
            DateStateEnum::DateAlphaWsDigitMoreWs => (function () use (&$i, $char): void {
                //            x
                // May 8, 2009 5:57:51 PM
                // May 05, 2005, 05:05:05
                // oct 1, 1970
                // oct 7, '70
                switch ($char) {
                    case '\'':
                        $this->yearPos = $i + 1;
                        break;
                    case ' ':
                    case ',':
                        //            x
                        // May 8, 2009 5:57:51 PM
                        //            x
                        // May 8, 2009, 5:57:51 PM
                        $this->yearLen = $i - $this->yearPos;
                        $this->setYearFormat();
                        $this->dateState = DateStateEnum::DateAlphaWsDigitMoreWsYear;
                        break;
                }
            })(),
            DateStateEnum::DateAlphaWsMonth => (function () use (&$i, $char): void {
                // April 8, 2009
                // April 8 2009
                switch ($char) {
                    case ' ':
                    case ',':
                        //       x
                        // June 8, 2009
                        //       x
                        // June 8 2009
                        if ($this->dayLen === 0) {
                            $this->dayLen = $i - $this->dayPos;
                            $this->setDayFormat();
                        }
                        break;
                    case 's':
                    case 'S':
                    case 'r':
                    case 'R':
                    case 't':
                    case 'T':
                    case 'n':
                    case 'N':
                        // st, rd, nd, st
                        $this->dateState = DateStateEnum::DateAlphaWsMonthSuffix;
                        $i--;
                        break;
                    default:
                        if ($this->dayLen > 0 && $this->yearPos === 0) {
                            $this->yearPos = $i;
                        }
                }
            })(),
            DateStateEnum::DateAlphaPeriodWsDigit => (function () use (&$i, $char): void {
                //    oct. 7, '70
                switch (true) {
                    case $char === ' ':
                        break;
                    case ctype_digit($char):
                        $this->dateState = DateStateEnum::DateAlphaWsDigit;
                        $this->dayPos = $i;
                        break;
                    default:
                        throw ParseException::unexpectedCharPosition($i);
                }
            })(),
            DateStateEnum::DateAlphaWsMonthSuffix => (function () use (&$i, $char): void {
                //        x
                // April 8th, 2009
                // April 8th 2009
                switch ($char) {
                    case 't':
                    case 'T':
                        if ($this->nextCharIs($i, 'h') || $this->nextCharIs($i, 'H')) {
                            if (count($this->dateChars) > $i + 2) {
                                array_splice($this->dateChars, $i, 2);

                                $this->dateState = DateStateEnum::DateStartOver;
                            }
                        }
                        break;
                    case 'r':
                    case 'R':
                    case 'n':
                    case 'N':
                        if ($this->nextCharIs($i, 'd') || $this->nextCharIs($i, 'D')) {
                            if (count($this->dateChars) > $i + 2) {
                                array_splice($this->dateChars, $i, 2);

                                $this->dateState = DateStateEnum::DateStartOver;
                            }
                        }
                        break;
                    case 's':
                    case 'S':
                        if ($this->nextCharIs($i, 't') || $this->nextCharIs($i, 'T')) {
                            if (count($this->dateChars) > $i + 2) {
                                array_splice($this->dateChars, $i, 2);

                                $this->dateState = DateStateEnum::DateStartOver;
                            }
                        }
                        break;
                }
            })(),
            default => null
        };
    }

    private function nextCharIs(int $pos, string $char): bool
    {
        return isset($this->dateChars[$pos + 1]) && ($this->dateChars[$pos + 1] === $char);
    }

    /**
     * @throws ParseException
     */
    private function setDayFormat(): void
    {
        if (!in_array($this->dayLen, [1, 2], true)) {
            throw ParseException::unexpectedDateUnitLength('day', $this->dayLen);
        }

        $this->setFormat($this->dayPos, str_repeat('D', $this->dayLen));
    }

    /**
     * @throws ParseException
     */
    private function setMonthFormat(): void
    {
        if ($this->monthLen < 1 || $this->monthLen > 4) {
            throw ParseException::unexpectedDateUnitLength('month', $this->monthLen);
        }

        $this->setFormat($this->monthPos, str_repeat('M', $this->monthLen));
    }

    private function setFullMonthFormat(): void
    {
        if ($this->monthPos !== 0) {
            return;
        }

        $this->isoFormat = substr_replace($this->isoFormat, 'MMMM', $this->monthPos, mb_strlen($this->fullMonth));
        $this->monthLen = mb_strlen($this->fullMonth);
    }

    /**
     * @throws ParseException
     */
    private function setYearFormat(): void
    {
        if (!in_array($this->yearLen, [2, 4], true)) {
            throw ParseException::unexpectedDateUnitLength('year', $this->yearLen);
        }

        $this->setFormat($this->yearPos, str_repeat('Y', $this->yearLen));
    }

    private function setFormat(int $pos, string $unit): void
    {
        if (0 > $pos) {
            return;
        }

        $i = 0;

        for (; $i < strlen($unit); $i++) {
            $this->isoFormat[$pos + $i] = $unit[$i];
        }
    }

    /**
     * @param int $end
     * @throws ParseException
     */
    private function coalesceDate(int $end): void
    {
        if ($this->yearPos > 0) {
            if ($this->yearLen === 0) {
                $this->yearLen = $end - $this->yearPos;
            }

            $this->setYearFormat();
        }

        if ($this->monthPos > 0 && $this->monthLen === 0) {
            $this->monthLen = $end - $this->monthPos;

            $this->setMonthFormat();
        }

        if ($this->dayPos > 0 && $this->dayLen === 0) {
            $this->dayLen = $end - $this->dayPos;

            $this->setDayFormat();
        }
    }

    private function getDayStr(): string
    {
        return mb_substr($this->dateStr, $this->dayPos, $this->dayLen);
    }

    private function getMonthStr(): string
    {
        return mb_substr($this->dateStr, $this->monthPos, $this->monthLen);
    }

    private function getYearStr(): string
    {
        return mb_substr($this->dateStr, $this->yearPos, $this->yearLen);
    }

    private function isValidNumericMonth(string $value): bool
    {
        return Carbon::canBeCreatedFromFormat($value, 'm')
            || Carbon::canBeCreatedFromFormat($value, 'n');
    }

    private function isFullTextualMonth(string $value): bool
    {
        return Carbon::canBeCreatedFromFormat($value, 'F');
    }

    private function isTextualWeekDay(string $value): bool
    {
        return Carbon::canBeCreatedFromFormat($value, 'D') || Carbon::canBeCreatedFromFormat($value, 'l');
    }

    /**
     * @return string
     */
    public function getDateStr(): string
    {
        return implode('', $this->dateChars);
    }

    /**
     * @return bool
     */
    public function isMonthPreferredAsFirst(): bool
    {
        return $this->preferMonthFirst;
    }

    public function preferMonthFirst(bool $value = true): self
    {
        $this->preferMonthFirst = $value;

        return $this;
    }
}