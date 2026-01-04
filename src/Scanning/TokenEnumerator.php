<?php

declare(strict_types=1);

namespace Rhinox\FracturedJson\Scanning;

use Generator;
use Rhinox\FracturedJson\Exceptions\FracturedJsonException;

/**
 * Provides .NET-like Enumerator semantics wrapped around a PHP Generator.
 */
class TokenEnumerator
{
    private Generator $generator;
    private ?JsonToken $current = null;

    public function __construct(Generator $generator)
    {
        $this->generator = $generator;
    }

    public function getCurrent(): JsonToken
    {
        if ($this->current === null) {
            throw new FracturedJsonException('Illegal enumerator usage');
        }
        return $this->current;
    }

    public function moveNext(): bool
    {
        if (!$this->generator->valid()) {
            $this->current = null;
            return false;
        }

        $this->current = $this->generator->current();
        $this->generator->next();
        return true;
    }
}
