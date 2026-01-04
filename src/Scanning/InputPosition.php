<?php

declare(strict_types=1);

namespace Rhinox\FracturedJson\Scanning;

/**
 * Structure representing a location in an input stream.
 */
readonly class InputPosition
{
    public function __construct(
        public int $index,
        public int $row,
        public int $column,
    ) {}
}
