<?php

declare(strict_types=1);

namespace Rhinox\FracturedJson\Scanning;

use Rhinox\FracturedJson\Enums\TokenType;
use Rhinox\FracturedJson\Exceptions\FracturedJsonException;

/**
 * Class for keeping track of info while scanning text into JSON tokens.
 */
class ScannerState
{
    private const MAX_DOC_SIZE = 2000000000;

    public int $currentIndex = 0;
    public int $currentRow = 0;
    public int $currentColumn = 0;

    public int $tokenIndex = 0;
    public int $tokenRow = 0;
    public int $tokenColumn = 0;

    public bool $nonWhitespaceSinceLastNewline = false;

    private string $originalText;
    private int $textLength;

    public function __construct(string $originalText)
    {
        $this->originalText = $originalText;
        $this->textLength = strlen($originalText);
    }

    public function advance(bool $isWhitespace): void
    {
        if ($this->currentIndex >= self::MAX_DOC_SIZE) {
            throw new \RuntimeException('Maximum document length exceeded');
        }
        $this->currentIndex++;
        $this->currentColumn++;
        $this->nonWhitespaceSinceLastNewline = $this->nonWhitespaceSinceLastNewline || !$isWhitespace;
    }

    public function newLine(): void
    {
        if ($this->currentIndex >= self::MAX_DOC_SIZE) {
            throw new \RuntimeException('Maximum document length exceeded');
        }
        $this->currentIndex++;
        $this->currentRow++;
        $this->currentColumn = 0;
        $this->nonWhitespaceSinceLastNewline = false;
    }

    public function setTokenStart(): void
    {
        $this->tokenIndex = $this->currentIndex;
        $this->tokenRow = $this->currentRow;
        $this->tokenColumn = $this->currentColumn;
    }

    public function makeTokenFromBuffer(TokenType $type, bool $trimEnd = false): JsonToken
    {
        $substring = substr($this->originalText, $this->tokenIndex, $this->currentIndex - $this->tokenIndex);
        return new JsonToken(
            $type,
            $trimEnd ? rtrim($substring) : $substring,
            new InputPosition($this->tokenIndex, $this->tokenRow, $this->tokenColumn),
        );
    }

    public function makeToken(TokenType $type, string $text): JsonToken
    {
        return new JsonToken(
            $type,
            $text,
            new InputPosition($this->tokenIndex, $this->tokenRow, $this->tokenColumn),
        );
    }

    public function current(): ?string
    {
        return $this->atEnd() ? null : $this->originalText[$this->currentIndex];
    }

    public function currentOrd(): int
    {
        return $this->atEnd() ? -1 : ord($this->originalText[$this->currentIndex]);
    }

    public function atEnd(): bool
    {
        return $this->currentIndex >= $this->textLength;
    }

    public function throw(string $message): never
    {
        throw new FracturedJsonException(
            $message,
            new InputPosition($this->currentIndex, $this->currentRow, $this->currentColumn),
        );
    }

    public function getCurrentPosition(): InputPosition
    {
        return new InputPosition($this->currentIndex, $this->currentRow, $this->currentColumn);
    }
}
