<?php

declare(strict_types=1);

namespace Rhinox\FracturedJson\Enums;

/**
 * Types of tokens that can be read from a stream of JSON text.
 * Comments aren't part of the official JSON standard, but we're supporting them anyway.
 * BlankLine isn't typically a token by itself, but we want to try to preserve those.
 */
enum TokenType: int
{
    case Invalid = 0;
    case BeginArray = 1;
    case EndArray = 2;
    case BeginObject = 3;
    case EndObject = 4;
    case String = 5;
    case Number = 6;
    case Null = 7;
    case True = 8;
    case False = 9;
    case BlockComment = 10;
    case LineComment = 11;
    case BlankLine = 12;
    case Comma = 13;
    case Colon = 14;
}
