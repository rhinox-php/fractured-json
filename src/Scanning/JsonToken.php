<?php

declare(strict_types=1);

namespace Rhinox\FracturedJson\Scanning;

use Rhinox\FracturedJson\Enums\TokenType;

/**
 * A piece of JSON text that makes sense to treat as a whole thing when analyzing a document's structure.
 * For example, a string is a token, regardless of whether it represents a value or an object key.
 */
readonly class JsonToken
{
    public function __construct(
        public TokenType $type,
        public string $text,
        public InputPosition $inputPosition,
    ) {}
}
