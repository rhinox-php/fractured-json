<?php

declare(strict_types=1);

namespace Rhinox\FracturedJson\Formatting;

/**
 * A place where strings are piled up sequentially to eventually make one big string.
 */
class StringJoinBuffer implements BufferInterface
{
    private const SPACES_CACHE_SIZE = 64;

    /** @var string[] */
    private static array $spacesCache = [];

    /** @var string[] */
    private array $lineBuff = [];

    /** @var string[] */
    private array $docBuff = [];

    public function __construct()
    {
        if (empty(self::$spacesCache)) {
            for ($i = 0; $i < self::SPACES_CACHE_SIZE; $i++) {
                self::$spacesCache[$i] = str_repeat(' ', $i);
            }
        }
    }

    /**
     * Add zero or more strings to the buffer.
     */
    public function add(string ...$values): BufferInterface
    {
        foreach ($values as $value) {
            $this->lineBuff[] = $value;
        }
        return $this;
    }

    /**
     * Add the requested number of spaces.
     */
    public function spaces(int $count): BufferInterface
    {
        if ($count <= 0) {
            return $this;
        }
        $spacesStr = $count < self::SPACES_CACHE_SIZE
            ? self::$spacesCache[$count]
            : str_repeat(' ', $count);
        $this->lineBuff[] = $spacesStr;
        return $this;
    }

    /**
     * Used to indicate the end of a line. Triggers special processing like trimming whitespace.
     */
    public function endLine(string $eolString): BufferInterface
    {
        $this->addLineToWriter($eolString);
        return $this;
    }

    /**
     * Call this to let the buffer finish up any work in progress.
     */
    public function flush(): BufferInterface
    {
        $this->addLineToWriter('');
        return $this;
    }

    /**
     * Converts the buffer's contents into a single string.
     */
    public function asString(): string
    {
        return implode('', $this->docBuff);
    }

    /**
     * Takes the contents of lineBuff and merges them into a string and adds it to docBuff.
     * We trim trailing whitespace in the process.
     */
    private function addLineToWriter(string $eolString): void
    {
        if (count($this->lineBuff) === 0 && $eolString === '') {
            return;
        }

        $line = rtrim(implode('', $this->lineBuff));

        $this->docBuff[] = $line . $eolString;
        $this->lineBuff = [];
    }
}
