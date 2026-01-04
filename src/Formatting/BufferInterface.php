<?php

declare(strict_types=1);

namespace Rhinox\FracturedJson\Formatting;

/**
 * A place where strings are piled up sequentially to eventually make one big string.
 * Or maybe straight to a stream or whatever.
 */
interface BufferInterface
{
    /**
     * Add zero or more strings to the buffer.
     */
    public function add(string ...$values): self;

    /**
     * Add the requested number of spaces.
     */
    public function spaces(int $count): self;

    /**
     * Used to indicate the end of a line. Triggers special processing like trimming whitespace.
     */
    public function endLine(string $eolString): self;

    /**
     * Call this to let the buffer finish up any work in progress.
     */
    public function flush(): self;
}
