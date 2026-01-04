<?php

declare(strict_types=1);

namespace Rhinox\FracturedJson\Enums;

enum BracketPaddingType: int
{
    case Empty = 0;
    case Simple = 1;
    case Complex = 2;
}
