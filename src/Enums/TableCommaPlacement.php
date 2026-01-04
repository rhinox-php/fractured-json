<?php

declare(strict_types=1);

namespace Rhinox\FracturedJson\Enums;

/**
 * Specifies where commas should be in table-formatted elements.
 */
enum TableCommaPlacement: int
{
    /**
     * Commas come right after the element that comes before them.
     */
    case BeforePadding = 0;

    /**
     * Commas come after the column padding, all lined with each other.
     */
    case AfterPadding = 1;

    /**
     * Commas come right after the element, except in the case of columns of numbers.
     */
    case BeforePaddingExceptNumbers = 2;
}
