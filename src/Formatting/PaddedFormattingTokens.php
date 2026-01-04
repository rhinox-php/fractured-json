<?php

declare(strict_types=1);

namespace Rhinox\FracturedJson\Formatting;

use Rhinox\FracturedJson\Enums\BracketPaddingType;
use Rhinox\FracturedJson\Enums\EolStyle;
use Rhinox\FracturedJson\Enums\JsonItemType;

class PaddedFormattingTokens
{
    private string $comma;
    private string $colon;
    private string $comment;
    private string $eol;
    private string $dummyComma;
    private int $commaLen;
    private int $colonLen;
    private int $commentLen;
    private int $literalNullLen;
    private int $literalTrueLen;
    private int $literalFalseLen;
    private int $prefixStringLen;

    /** @var array<int, string> */
    private array $arrStart;

    /** @var array<int, string> */
    private array $arrEnd;

    /** @var array<int, string> */
    private array $objStart;

    /** @var array<int, string> */
    private array $objEnd;

    /** @var array<int, int> */
    private array $arrStartLen;

    /** @var array<int, int> */
    private array $arrEndLen;

    /** @var array<int, int> */
    private array $objStartLen;

    /** @var array<int, int> */
    private array $objEndLen;

    /** @var string[] */
    private array $indentStrings;

    /** @var callable(string): int */
    private $strLenFunc;

    /**
     * @param callable(string): int $strLenFunc
     */
    public function __construct(FracturedJsonOptions $opts, callable $strLenFunc)
    {
        $this->strLenFunc = $strLenFunc;

        $this->arrStart = [
            BracketPaddingType::Empty->value => '[',
            BracketPaddingType::Simple->value => $opts->simpleBracketPadding ? '[ ' : '[',
            BracketPaddingType::Complex->value => $opts->nestedBracketPadding ? '[ ' : '[',
        ];

        $this->arrEnd = [
            BracketPaddingType::Empty->value => ']',
            BracketPaddingType::Simple->value => $opts->simpleBracketPadding ? ' ]' : ']',
            BracketPaddingType::Complex->value => $opts->nestedBracketPadding ? ' ]' : ']',
        ];

        $this->objStart = [
            BracketPaddingType::Empty->value => '{',
            BracketPaddingType::Simple->value => $opts->simpleBracketPadding ? '{ ' : '{',
            BracketPaddingType::Complex->value => $opts->nestedBracketPadding ? '{ ' : '{',
        ];

        $this->objEnd = [
            BracketPaddingType::Empty->value => '}',
            BracketPaddingType::Simple->value => $opts->simpleBracketPadding ? ' }' : '}',
            BracketPaddingType::Complex->value => $opts->nestedBracketPadding ? ' }' : '}',
        ];

        $this->comma = $opts->commaPadding ? ', ' : ',';
        $this->colon = $opts->colonPadding ? ': ' : ':';
        $this->comment = $opts->commentPadding ? ' ' : '';
        $this->eol = $opts->jsonEolStyle === EolStyle::Crlf ? "\r\n" : "\n";

        $this->arrStartLen = array_map($strLenFunc, $this->arrStart);
        $this->arrEndLen = array_map($strLenFunc, $this->arrEnd);
        $this->objStartLen = array_map($strLenFunc, $this->objStart);
        $this->objEndLen = array_map($strLenFunc, $this->objEnd);

        // Create pre-made indent strings for levels 0 and 1 now. We'll construct and cache others as needed.
        $this->indentStrings = [
            '',
            $opts->useTabToIndent ? "\t" : str_repeat(' ', $opts->indentSpaces),
        ];

        $this->commaLen = $strLenFunc($this->comma);
        $this->colonLen = $strLenFunc($this->colon);
        $this->commentLen = $strLenFunc($this->comment);
        $this->literalNullLen = $strLenFunc('null');
        $this->literalTrueLen = $strLenFunc('true');
        $this->literalFalseLen = $strLenFunc('false');
        $this->prefixStringLen = $strLenFunc($opts->prefixString);
        $this->dummyComma = str_repeat(' ', $this->commaLen);
    }

    public function getComma(): string
    {
        return $this->comma;
    }

    public function getColon(): string
    {
        return $this->colon;
    }

    public function getComment(): string
    {
        return $this->comment;
    }

    public function getEol(): string
    {
        return $this->eol;
    }

    public function getCommaLen(): int
    {
        return $this->commaLen;
    }

    public function getColonLen(): int
    {
        return $this->colonLen;
    }

    public function getCommentLen(): int
    {
        return $this->commentLen;
    }

    public function getLiteralNullLen(): int
    {
        return $this->literalNullLen;
    }

    public function getLiteralTrueLen(): int
    {
        return $this->literalTrueLen;
    }

    public function getLiteralFalseLen(): int
    {
        return $this->literalFalseLen;
    }

    public function getPrefixStringLen(): int
    {
        return $this->prefixStringLen;
    }

    public function getDummyComma(): string
    {
        return $this->dummyComma;
    }

    public function arrStart(BracketPaddingType $type): string
    {
        return $this->arrStart[$type->value];
    }

    public function arrEnd(BracketPaddingType $type): string
    {
        return $this->arrEnd[$type->value];
    }

    public function objStart(BracketPaddingType $type): string
    {
        return $this->objStart[$type->value];
    }

    public function objEnd(BracketPaddingType $type): string
    {
        return $this->objEnd[$type->value];
    }

    public function start(JsonItemType $elemType, BracketPaddingType $bracketType): string
    {
        return $elemType === JsonItemType::Array
            ? $this->arrStart($bracketType)
            : $this->objStart($bracketType);
    }

    public function end(JsonItemType $elemType, BracketPaddingType $bracketType): string
    {
        return $elemType === JsonItemType::Array
            ? $this->arrEnd($bracketType)
            : $this->objEnd($bracketType);
    }

    public function arrStartLen(BracketPaddingType $type): int
    {
        return $this->arrStartLen[$type->value];
    }

    public function arrEndLen(BracketPaddingType $type): int
    {
        return $this->arrEndLen[$type->value];
    }

    public function objStartLen(BracketPaddingType $type): int
    {
        return $this->objStartLen[$type->value];
    }

    public function objEndLen(BracketPaddingType $type): int
    {
        return $this->objEndLen[$type->value];
    }

    public function startLen(JsonItemType $elemType, BracketPaddingType $bracketType): int
    {
        return $elemType === JsonItemType::Array
            ? $this->arrStartLen($bracketType)
            : $this->objStartLen($bracketType);
    }

    public function endLen(JsonItemType $elemType, BracketPaddingType $bracketType): int
    {
        return $elemType === JsonItemType::Array
            ? $this->arrEndLen($bracketType)
            : $this->objEndLen($bracketType);
    }

    public function indent(int $level): string
    {
        if ($level >= count($this->indentStrings)) {
            for ($i = count($this->indentStrings); $i <= $level; $i++) {
                $this->indentStrings[] = $this->indentStrings[$i - 1] . $this->indentStrings[1];
            }
        }

        return $this->indentStrings[$level];
    }
}
