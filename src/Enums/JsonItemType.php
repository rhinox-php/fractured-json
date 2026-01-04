<?php

declare(strict_types=1);

namespace Rhinox\FracturedJson\Enums;

enum JsonItemType: int
{
    case Null = 0;
    case False = 1;
    case True = 2;
    case String = 3;
    case Number = 4;
    case Object = 5;
    case Array = 6;
    case BlankLine = 7;
    case LineComment = 8;
    case BlockComment = 9;
}
