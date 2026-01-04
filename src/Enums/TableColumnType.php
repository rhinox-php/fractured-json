<?php

declare(strict_types=1);

namespace Rhinox\FracturedJson\Enums;

/**
 * The data type represented by a TableTemplate, or "Mixed".
 */
enum TableColumnType: int
{
    /**
     * Initial value. Not useful by itself.
     */
    case Unknown = 0;

    /**
     * Non-container and non-number. Could be a mix of strings, booleans, nulls, and/or numbers (but not all numbers).
     */
    case Simple = 1;

    /**
     * All values in the column are numbers or nulls.
     */
    case Number = 2;

    /**
     * All values in the column are arrays or nulls.
     */
    case Array = 3;

    /**
     * All values in the column are objects or nulls.
     */
    case Object = 4;

    /**
     * Multiple types in the column - for instance, a mix of arrays and strings.
     */
    case Mixed = 5;
}
