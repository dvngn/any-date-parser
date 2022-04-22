<?php

declare(strict_types=1);

namespace Devengine\AnyDateParser;

use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;
use Devengine\AnyDateParser\Exceptions\ParseException;

class DateParser
{
    protected string $dateStr;

    protected string $isoFormat = "";

    private array $dateChars;

    private string $dateState = self::DATE_START;

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

        if ($this->preferMonthFirst && is_numeric($this->getMonthStr()) && ! $this->isValidNumericMonth($this->getMonthStr())) {
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
        $this->dateState = self::DATE_START;
        $this->isoFormat = implode('', $this->dateChars);
        $this->yearPos = $this->yearLen = $this->monthPos = $this->monthLen = $this->dayLen = $this->dayPos = 0;
        $charsCount = count($this->dateChars);

        $i = 0;

        for (; $i < $charsCount; $i++) {
            $this->processDateState($i, $this->dateChars[$i]);

            if ($this->dateState === self::DATE_START_OVER) {
                $this->performDateParse();
                return;
            }
        }

        $this->coalesceDate($i);

        if (!empty($this->fullMonth)) {
            $this->setFullMonthFormat();
        }

        switch ($this->dateState) {
            case self::DATE_YEAR_DASH_ALPHA_DASH:
                // 2013-Feb-03
                // 2013-Feb-3
                $this->dayLen = $i - $this->dayPos;
                $this->setDayFormat();
                break;

            case self::DATE_DIGIT_WS_MONTH_LONG:
                // 18 January 2018
                // 8 January 2018
                if ($this->dayLen === 2) {
                    $this->isoFormat = 'DD MMMM YYYY';
                    break;

                }

                $this->isoFormat = 'D MMMM YYYY';

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
        switch ($this->dateState) {

            case self::DATE_START:
                if (ctype_digit($char)) {
                    $this->dateState = self::DATE_DIGIT;
                } elseif (ctype_alpha($char)) {
                    $this->dateState = self::DATE_ALPHA;
                } else {
                    throw ParseException::unexpectedDateStartChar();
                }
                break;

            case self::DATE_DIGIT:
                switch ($char) {
                    case '-':
                    case '\u2212':
                        // 2006-01-02
                        // 2013-Feb-03
                        // 13-Feb-03
                        // 29-Jun-2016
                        if ($i === 4) {
                            $this->dateState = self::DATE_YEAR_DASH;
                            $this->yearPos = 0;
                            $this->yearLen = $i;
                            $this->monthPos = $i + 1;
                            $this->setYearFormat();
                        } else {
                            $this->dateState = self::DATE_DIGIT_DASH;
                        }
                        break;
                    case '/':
                        // 03/31/2005
                        // 2014/02/24
                        $this->dateState = self::DATE_DIGIT_SLASH;

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
                        $this->dateState = self::DATE_DIGIT_COLON;

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
                        $this->dateState = self::DATE_DIGIT_DOT;

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
                        $this->dateState = self::DATE_DIGIT_WS;
                        $this->dayPos = 0;
                        $this->dayLen = $i;
                        break;
                }
                $this->firstPartLen = $i;
                break;


            case self::DATE_DIGIT_WS:
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
                            $this->dateState = self::DATE_DIGIT_WS_MONTH_LONG;
                        } else {
                            // If len=3, the might be Feb or May?  Ie ambiguous abbreviated but
                            // we can parse may with either.  BUT, that means the
                            // format may not be correct?
                            $this->monthPos = $this->dayLen + 1;
                            $this->monthLen = $i - $this->monthPos;
                            $this->setMonthFormat();
                            $this->dateState = self::DATE_DIGIT_WS_MONTH_YEAR;
                        }
                        break;
                }

                break;

            case self::DATE_DIGIT_WS_MONTH_YEAR:
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
                break;

            case self::DATE_YEAR_DASH:
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
                        $this->dateState = self::DATE_YEAR_DASH_DASH;
                        $this->setMonthFormat();
                        break;
                    default:
                        if (ctype_alpha($char)) {
                            $this->dateState = self::DATE_YEAR_DASH_ALPHA_DASH;
                        }
                }
                break;

            case self::DATE_YEAR_DASH_ALPHA_DASH:
                // 2013-Feb-03
                switch ($char) {
                    case '-':
                        $this->monthLen = $i - $this->monthPos;
                        $this->dayPos = $i + 1;
                        $this->setMonthFormat();
                }
                break;

            case self::DATE_DIGIT_SLASH:
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

                break;

            case self::DATE_DIGIT_DASH:
                // 13-Feb-03
                // 29-Jun-2016
                if (ctype_alpha($char)) {
                    $this->dateState = self::DATE_DIGIT_DASH_ALPHA;
                    $this->monthPos = $i;
                } else {
                    throw ParseException::unexpectedCharPosition($i);
                }
                break;

            case self::DATE_DIGIT_DASH_ALPHA_DASH:
                // 13-Feb-03
                // 28-Feb-03
                // 29-Jun-2016
                switch ($char) {
                    case '-':
                        $this->monthLen = $i - $this->monthPos;
                        $this->yearPos = $i + 1;
//                        $this->dateState = self::DATE_DIGIT_DASH_ALPHA_DASH;
                }
                break;

            case self::DATE_DIGIT_WS_MONTH_LONG:
                // 18 January 2018
                // 8 January 2018

                break;
            case self::DATE_DIGIT_DOT:
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
                            $this->dateState = self::DATE_DIGIT_DOT_DOT;
                        } else {
                            $this->monthLen = $i - $this->monthPos;
                            $this->dayPos = $i + 1;
                            $this->setMonthFormat();
                            $this->dateState = self::DATE_DIGIT_DOT_DOT;
                        }
                        break;
                }
                break;

            case self::DATE_DIGIT_DOT_DOT:

                break;
            case self::DATE_ALPHA:
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
                                    $this->dateState = self::DATE_ALPHA_WS_MONTH;
                                } else {
                                    $this->dateState = self::DATE_ALPHA_WS_MORE;
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

                                $this->dateState = self::DATE_START_OVER;

                                break;
                            }

                            $this->dateState = self::DATE_ALPHA_WS;
                        }

                        break;

                    case ',':
                        // Mon, 02 Jan 2006
                        // $this->monthPos = 0
                        // $this->monthLen = i
                        if ($i === 3) {

                            $this->dateState = self::DATE_WEEKDAY_ABBR_COMMA;
                            $this->monthPos = 0;

                            $this->setMonthFormat();

                        } else {
                            $this->dateState = self::DATE_WEEKDAY_COMMA;
                            $this->skipPos = $i + 2;
                            $i++;

                            // TODO: implement skip pos
                        }

                        break;

                    case '.':
                        // sept. 28, 2017
                        // jan. 28, 2017

                        $this->dateState = self::DATE_ALPHA_PERIOD_WS_DIGIT;

                        if ($i === 3) {
                            $this->monthLen = $i;
                            $this->monthPos = 0;
                            $this->setMonthFormat();
                        } elseif ($i === 4) {
                            array_splice($this->dateChars, $i);

                            $this->dateState = self::DATE_START_OVER;

                        } else {
                            throw ParseException::unexpectedCharPosition($i);
                        }

                        break;


                }

                break;

            case self::DATE_ALPHA_WS:
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

                    $this->dateState = self::DATE_ALPHA_WS_ALPHA;
                } elseif (ctype_digit($char)) {
                    $this->monthPos = 0;
                    $this->monthLen = 3;
                    $this->dayPos = $i;

                    $this->setMonthFormat();

                    $this->dateState = self::DATE_ALPHA_WS_DIGIT;
                }

                break;

            case self::DATE_ALPHA_WS_MORE:
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
                            $this->dateState = self::DATE_ALPHA_WS_MONTH_MORE;
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
                        $this->dateState = self::DATE_ALPHA_WS_MONTH_MORE;
                        break;

                    case ctype_digit($char):
                        //         XX
                        // January 02, 2006, 15:04:05
                        break;

                    case ctype_alpha($char):
                        $this->dayLen = $i - $this->dayPos;
                        $this->dateState = self::DATE_ALPHA_WS_MONTH_SUFFIX;
                        $i--;
                        break;
                }
                break;

            case self::DATE_ALPHA_WS_DIGIT:
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
                    $this->dateState = self::DATE_ALPHA_WS_DIGIT_MORE;

                    $this->setDayFormat();
                } elseif ($char === ' ') {
                    $this->dayLen = $i - $this->dayPos;
                    $this->yearPos = $i + 1;
                    $this->dateState = self::DATE_ALPHA_WS_DIGIT_YEAR_POSSIBLE;

                    $this->setDayFormat();
                } elseif (ctype_alpha($char)) {
                    $this->dateState = self::DATE_ALPHA_WS_MONTH_SUFFIX;
                    $i--;
                }

                break;

            case self::DATE_ALPHA_WS_DIGIT_MORE:
                //       x
                // May 8, 2009 5:57:51 PM
                // May 05, 2005, 05:05:05
                // May 05 2005, 05:05:05
                // oct 1, 1970
                // oct 7, '70
                if ($char === ' ') {
                    $this->yearPos = $i + 1;
                    $this->dateState = self::DATE_ALPHA_WS_DIGIT_MORE_WS;
                }

                break;

            case self::DATE_ALPHA_WS_DIGIT_MORE_WS:
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
                        $this->dateState = self::DATE_ALPHA_WS_DIGIT_MORE_WS_YEAR;
                        break;

                }
                break;

            case self::DATE_ALPHA_WS_MONTH:
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
                        $this->dateState = self::DATE_ALPHA_WS_MONTH_SUFFIX;
                        $i--;
                        break;
                    default:
                        if ($this->dayLen > 0 && $this->yearPos === 0) {
                            $this->yearPos = $i;
                        }
                }

                break;


            case self::DATE_ALPHA_PERIOD_WS_DIGIT:
                //    oct. 7, '70
                switch (true) {
                    case $char === ' ':
                        break;
                    case ctype_digit($char):
                        $this->dateState = self::DATE_ALPHA_WS_DIGIT;
                        $this->dayPos = $i;
                        break;
                    default:
                        throw ParseException::unexpectedCharPosition($i);
                }

                break;

            case self::DATE_ALPHA_WS_MONTH_SUFFIX:
                //        x
                // April 8th, 2009
                // April 8th 2009

                switch ($char) {
                    case 't':
                    case 'T':
                        if ($this->nextCharIs($i, 'h') || $this->nextCharIs($i, 'H')) {
                            if (count($this->dateChars) > $i + 2) {
                                array_splice($this->dateChars, $i, 2);

                                $this->dateState = self::DATE_START_OVER;
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

                                $this->dateState = self::DATE_START_OVER;
                            }
                        }
                        break;
                    case 's':
                    case 'S':
                        if ($this->nextCharIs($i, 't') || $this->nextCharIs($i, 'T')) {
                            if (count($this->dateChars) > $i + 2) {
                                array_splice($this->dateChars, $i, 2);

                                $this->dateState = self::DATE_START_OVER;
                            }
                        }
                        break;
                }

                break;
        }


    }

    private function nextCharIs(int $pos, string $char): bool
    {
        return isset($this->dateChars[$pos + 1]) && ($this->dateChars[$pos + 1] === $char);
    }

    /**
     * @throws ParseException
     */
    private function setDayFormat()
    {
        if ($this->dayLen === 2) {
            $this->setFormat($this->dayPos, 'DD');
            return;
        }

        if ($this->dayLen === 1) {
            $this->setFormat($this->dayPos, 'D');
            return;
        }

        throw ParseException::unexpectedDateUnitLength('day', $this->dayLen);
    }

    /**
     * @throws ParseException
     */
    private function setMonthFormat()
    {
        if ($this->monthLen === 4) {
            $this->setFormat($this->monthPos, 'MMMM');

            return;
        }

        if ($this->monthLen === 3) {
            $this->setFormat($this->monthPos, 'MMM');

            return;
        }

        if ($this->monthLen === 2) {
            $this->setFormat($this->monthPos, 'MM');

            return;
        }

        if ($this->monthLen === 1) {
            $this->setFormat($this->monthPos, 'M');

            return;
        }

        throw ParseException::unexpectedDateUnitLength('month', $this->monthLen);
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
    private function setYearFormat()
    {
        if ($this->yearLen === 2) {
            $this->setFormat($this->yearPos, 'YY');

            return;
        }

        if ($this->yearLen === 4) {
            $this->setFormat($this->yearPos, 'YYYY');

            return;
        }

        throw ParseException::unexpectedDateUnitLength('year', $this->yearLen);
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

    private const
        DATE_START_OVER = "9d88a1fd-77e1-48dc-a607-da5821fa4c44",
        DATE_START = "8b9e4038-9648-4fe7-b38b-a79730aa2fab",
        DATE_ALPHA = "5842206e-7bd4-41f0-9216-baa0c4d6280d",
        DATE_DIGIT = "973382e5-0398-4249-9978-da6b9eb1145c",
        DATE_YEAR_DASH = "85a3c3ed-0e59-448a-bcee-99628b2a8a25",
        DATE_DIGIT_DASH = "18dbe092-e384-4414-8d13-7ab9548dcc54",
        DATE_DIGIT_SLASH = "a3b3b48c-ab6a-4bbb-b6d7-54b7094b8f67",
        DATE_DIGIT_COLON = "81a9bdd0-de3d-4034-962d-41ec62dfeb86",
        DATE_DIGIT_DOT = "8223ef2d-34b9-4dd8-a83e-ecb9f3dfad4c",
        DATE_DIGIT_WS = "ff6a857e-ea41-49a1-bb46-28f3a80d407e",
        DATE_YEAR_DASH_DASH = "69930bb8-7bc8-4727-8797-1abf10c4dca6",
        DATE_DIGIT_DASH_ALPHA = "3b3f0329-a548-414d-9d98-7b89a9c2da6e",
        DATE_YEAR_DASH_ALPHA_DASH = "184c2d33-979f-42e2-8dc1-0c5b0b14dcf1",
        DATE_DIGIT_DASH_ALPHA_DASH = "ad87987d-4a79-4ece-b900-6b5c8b284412",
        DATE_YEAR_DASH_DASH_WS = "407b7fd7-4285-40c8-a829-619b3c49d56a",
        DATE_DIGIT_DOT_DOT = "19b9614c-9025-4444-a81d-44fb6b0d3e88",
        DATE_ALPHA_WS = "cf9f4c89-46f4-4716-a7e4-9b9e58cccde4",
        DATE_ALPHA_WS_MONTH = "a598220b-0447-43a6-9f86-26597387350a",
        DATE_ALPHA_WS_MORE = "dabbe4af-da3a-497d-a0a8-e9388bae8b7e",
        DATE_WEEKDAY_ABBR_COMMA = "754462df-2b0d-4f75-979e-ec8846f21828",
        DATE_WEEKDAY_COMMA = "e10a30a0-bf88-4a1e-b16f-19b01940b3df",
        DATE_ALPHA_PERIOD_WS_DIGIT = "1aa263c9-229c-48a4-a45d-c0ad436e877c",
        DATE_ALPHA_WS_ALPHA = "00519364-acc1-44ac-a924-83a469cf0aa7",
        DATE_ALPHA_WS_DIGIT = "91337c68-6ae1-432f-8fb9-f84be30d6825",
        DATE_ALPHA_WS_DIGIT_MORE = "dbc6ee99-14e1-4fec-a2ce-7d3969bb2431",
        DATE_ALPHA_WS_DIGIT_YEAR_POSSIBLE = "31bf5453-e437-4bba-bfb9-7f9f2ae2cdd3",
        DATE_ALPHA_WS_MONTH_SUFFIX = "4b29c47b-99a3-43b5-9cb6-d9e43aeb0234",
        DATE_ALPHA_WS_DIGIT_MORE_WS = "3fa62338-5de7-4827-833b-6c0e9b158014",
        DATE_ALPHA_WS_DIGIT_MORE_WS_YEAR = "54386979-5e8c-4e18-8d9a-bf483a1a4180",
        DATE_ALPHA_WS_MONTH_MORE = "b4184fde-2664-4ba2-b326-69eeac175272",
        DATE_DIGIT_WS_MONTH_LONG = "e63f1367-8546-4e65-b241-1e1c1d56acc3",
        DATE_DIGIT_WS_MONTH_YEAR = "3044b007-eeab-4693-81fd-c72648fabaac";

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