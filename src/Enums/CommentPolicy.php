<?php

declare(strict_types=1);

namespace Rhinox\FracturedJson\Enums;

/**
 * Instructions on what to do about comments found in the input text.
 * According to the JSON standard, comments aren't allowed.
 * But "JSON with comments" is pretty wide-spread these days, thanks largely to Microsoft,
 * so it's nice to have options.
 */
enum CommentPolicy: int
{
    /**
     * An exception will be thrown if comments are found in the input.
     */
    case TreatAsError = 0;

    /**
     * Comments are allowed in the input, but won't be included in the output.
     */
    case Remove = 1;

    /**
     * Comments found in the input should be included in the output.
     */
    case Preserve = 2;
}
