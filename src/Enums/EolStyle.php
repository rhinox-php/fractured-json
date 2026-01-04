<?php

declare(strict_types=1);

namespace Rhinox\FracturedJson\Enums;

/**
 * Specifies what sort of line endings to use.
 */
enum EolStyle: int
{
    /**
     * Carriage Return, followed by a line feed. Windows-style.
     */
    case Crlf = 0;

    /**
     * Just a line feed. Unix-style (including Mac).
     */
    case Lf = 1;
}
