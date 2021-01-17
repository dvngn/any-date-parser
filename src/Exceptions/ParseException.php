<?php

namespace Devengine\AnyDateParser\Exceptions;

use Exception;

class ParseException extends Exception
{
    public static function unexpectedCharPosition(int $pos): ParseException
    {
        return new static("Unexpected char at $pos position.");
    }

    public static function unexpectedDateStartChar(): ParseException
    {
        return new static("Unexpected date start char.");
    }

    public static function notImplementedCase(): ParseException
    {
        return new static("Not implemented case.");
    }

    public static function unexpectedDateUnitLength(string $unit, int $length): ParseException
    {
        return new static("Unexpected $unit unit length. Got $length.");
    }
}