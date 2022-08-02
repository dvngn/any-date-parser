<?php

namespace Devengine\AnyDateParser\Enum;

enum DateStateEnum
{
    case DateStartOver;
    case DateStart;
    case DateAlpha;
    case DateDigit;
    case DateYearDash;
    case DateDigitDash;
    case DateDigitSlash;
    case DateDigitColon;
    case DateDigitDot;
    case DateDigitWs;
    case DateYearDashDash;
    case DateDigitDashAlpha;
    case DateYearDashAlphaDash;
    case DateDigitDashAlphaDash;
    case DateYearDashDashWs;
    case DateDigitDotDot;
    case DateAlphaWs;
    case DateAlphaWsMonth;
    case DateAlphaWsMore;
    case DateWeekdayAbbrComma;
    case DateWeekdayComma;
    case DateAlphaPeriodWsDigit;
    case DateAlphaWsAlpha;
    case DateAlphaWsDigit;
    case DateAlphaWsDigitMore;
    case DateAlphaWsDigitYearPossible;
    case DateAlphaWsMonthSuffix;
    case DateAlphaWsDigitMoreWs;
    case DateAlphaWsDigitMoreWsYear;
    case DateAlphaWsMonthMore;
    case DateDigitWsMonthLong;
    case DateDigitWsMonthYear;
}
