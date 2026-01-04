<?php

declare(strict_types=1);

namespace Rhinox\FracturedJson\Scanning;

use Generator;
use Rhinox\FracturedJson\Enums\TokenType;

/**
 * Converts a sequence of characters into a sequence of JSON tokens.
 * There's no guarantee that the tokens make sense - just that they're lexically correct.
 */
class TokenGenerator
{
    /**
     * @return Generator<JsonToken>
     */
    public static function generate(string $inputJson): Generator
    {
        $state = new ScannerState($inputJson);

        while (true) {
            if ($state->atEnd()) {
                return;
            }

            $ch = $state->current();
            switch ($ch) {
                case ' ':
                case "\t":
                case "\r":
                    $state->advance(true);
                    break;

                case "\n":
                    if (!$state->nonWhitespaceSinceLastNewline) {
                        yield $state->makeToken(TokenType::BlankLine, "\n");
                    }
                    $state->newLine();
                    $state->setTokenStart();
                    break;

                case '{':
                    yield self::processSingleChar($state, '{', TokenType::BeginObject);
                    break;
                case '}':
                    yield self::processSingleChar($state, '}', TokenType::EndObject);
                    break;
                case '[':
                    yield self::processSingleChar($state, '[', TokenType::BeginArray);
                    break;
                case ']':
                    yield self::processSingleChar($state, ']', TokenType::EndArray);
                    break;
                case ':':
                    yield self::processSingleChar($state, ':', TokenType::Colon);
                    break;
                case ',':
                    yield self::processSingleChar($state, ',', TokenType::Comma);
                    break;
                case 't':
                    yield self::processKeyword($state, 'true', TokenType::True);
                    break;
                case 'f':
                    yield self::processKeyword($state, 'false', TokenType::False);
                    break;
                case 'n':
                    yield self::processKeyword($state, 'null', TokenType::Null);
                    break;
                case '/':
                    yield self::processComment($state);
                    break;
                case '"':
                    yield self::processString($state);
                    break;
                case '-':
                    yield self::processNumber($state);
                    break;
                default:
                    if (!self::isDigit($ch)) {
                        $state->throw('Unexpected character');
                    }
                    yield self::processNumber($state);
                    break;
            }
        }
    }

    private static function processSingleChar(ScannerState $state, string $symbol, TokenType $type): JsonToken
    {
        $state->setTokenStart();
        $token = $state->makeToken($type, $symbol);
        $state->advance(false);
        return $token;
    }

    private static function processKeyword(ScannerState $state, string $keyword, TokenType $type): JsonToken
    {
        $state->setTokenStart();
        $len = strlen($keyword);
        for ($i = 1; $i < $len; $i++) {
            if ($state->atEnd()) {
                $state->throw('Unexpected end of input while processing keyword');
            }
            $state->advance(false);
            if ($state->current() !== $keyword[$i]) {
                $state->throw('Unexpected keyword');
            }
        }

        $token = $state->makeToken($type, $keyword);
        $state->advance(false);
        return $token;
    }

    private static function processComment(ScannerState $state): JsonToken
    {
        $state->setTokenStart();

        if ($state->atEnd()) {
            $state->throw('Unexpected end of input while processing comment');
        }

        $state->advance(false);
        $isBlockComment = false;
        if ($state->current() === '*') {
            $isBlockComment = true;
        } elseif ($state->current() !== '/') {
            $state->throw('Bad character for start of comment');
        }

        $state->advance(false);
        $lastCharWasAsterisk = false;
        while (true) {
            if ($state->atEnd()) {
                if ($isBlockComment) {
                    $state->throw('Unexpected end of input while processing comment');
                }
                return $state->makeTokenFromBuffer(TokenType::LineComment, true);
            }

            $ch = $state->current();
            if ($ch === "\n") {
                $state->newLine();
                if (!$isBlockComment) {
                    return $state->makeTokenFromBuffer(TokenType::LineComment, true);
                }
                continue;
            }

            $state->advance(false);
            if ($ch === '/' && $lastCharWasAsterisk) {
                return $state->makeTokenFromBuffer(TokenType::BlockComment);
            }

            $lastCharWasAsterisk = ($ch === '*');
        }
    }

    private static function processString(ScannerState $state): JsonToken
    {
        $state->setTokenStart();
        $state->advance(false);

        $lastCharBeganEscape = false;
        $expectedHexCount = 0;
        while (true) {
            if ($state->atEnd()) {
                $state->throw('Unexpected end of input while processing string');
            }

            $ch = $state->current();
            $ord = $state->currentOrd();

            if ($expectedHexCount > 0) {
                if (!self::isHex($ch)) {
                    $state->throw('Bad unicode escape in string');
                }
                $expectedHexCount--;
                $state->advance(false);
                continue;
            }

            if ($lastCharBeganEscape) {
                if (!self::isLegalAfterBackslash($ch)) {
                    $state->throw('Bad escaped character in string');
                }
                if ($ch === 'u') {
                    $expectedHexCount = 4;
                }
                $lastCharBeganEscape = false;
                $state->advance(false);
                continue;
            }

            if (self::isControl($ord)) {
                $state->throw('Control characters are not allowed in strings');
            }

            $state->advance(false);
            if ($ch === '"') {
                return $state->makeTokenFromBuffer(TokenType::String);
            }
            if ($ch === '\\') {
                $lastCharBeganEscape = true;
            }
        }
    }

    private static function processNumber(ScannerState $state): JsonToken
    {
        $state->setTokenStart();

        $phase = NumberPhase::Beginning;
        while (true) {
            $ch = $state->current();
            $handling = CharHandling::ValidAndConsumed;

            switch ($phase) {
                case NumberPhase::Beginning:
                    if ($ch === '-') {
                        $phase = NumberPhase::PastLeadingSign;
                    } elseif ($ch === '0') {
                        $phase = NumberPhase::PastWhole;
                    } elseif ($ch !== null && self::isDigit($ch)) {
                        $phase = NumberPhase::PastFirstDigitOfWhole;
                    } else {
                        $handling = CharHandling::InvalidatesToken;
                    }
                    break;

                case NumberPhase::PastLeadingSign:
                    if ($ch === null || !self::isDigit($ch)) {
                        $handling = CharHandling::InvalidatesToken;
                    } elseif ($ch === '0') {
                        $phase = NumberPhase::PastWhole;
                    } else {
                        $phase = NumberPhase::PastFirstDigitOfWhole;
                    }
                    break;

                case NumberPhase::PastFirstDigitOfWhole:
                    if ($ch === '.') {
                        $phase = NumberPhase::PastDecimalPoint;
                    } elseif ($ch === 'e' || $ch === 'E') {
                        $phase = NumberPhase::PastE;
                    } elseif ($ch === null || !self::isDigit($ch)) {
                        $handling = CharHandling::StartOfNewToken;
                    }
                    break;

                case NumberPhase::PastWhole:
                    if ($ch === '.') {
                        $phase = NumberPhase::PastDecimalPoint;
                    } elseif ($ch === 'e' || $ch === 'E') {
                        $phase = NumberPhase::PastE;
                    } else {
                        $handling = CharHandling::StartOfNewToken;
                    }
                    break;

                case NumberPhase::PastDecimalPoint:
                    if ($ch !== null && self::isDigit($ch)) {
                        $phase = NumberPhase::PastFirstDigitOfFractional;
                    } else {
                        $handling = CharHandling::InvalidatesToken;
                    }
                    break;

                case NumberPhase::PastFirstDigitOfFractional:
                    if ($ch === 'e' || $ch === 'E') {
                        $phase = NumberPhase::PastE;
                    } elseif ($ch === null || !self::isDigit($ch)) {
                        $handling = CharHandling::StartOfNewToken;
                    }
                    break;

                case NumberPhase::PastE:
                    if ($ch === '+' || $ch === '-') {
                        $phase = NumberPhase::PastExpSign;
                    } elseif ($ch !== null && self::isDigit($ch)) {
                        $phase = NumberPhase::PastFirstDigitOfExponent;
                    } else {
                        $handling = CharHandling::InvalidatesToken;
                    }
                    break;

                case NumberPhase::PastExpSign:
                    if ($ch !== null && self::isDigit($ch)) {
                        $phase = NumberPhase::PastFirstDigitOfExponent;
                    } else {
                        $handling = CharHandling::InvalidatesToken;
                    }
                    break;

                case NumberPhase::PastFirstDigitOfExponent:
                    if ($ch === null || !self::isDigit($ch)) {
                        $handling = CharHandling::StartOfNewToken;
                    }
                    break;
            }

            if ($handling === CharHandling::InvalidatesToken) {
                $state->throw('Bad character while processing number');
            }

            if ($handling === CharHandling::StartOfNewToken) {
                return $state->makeTokenFromBuffer(TokenType::Number);
            }

            if (!$state->atEnd()) {
                $state->advance(false);
                continue;
            }

            // We've reached the end of the input
            return match ($phase) {
                NumberPhase::PastFirstDigitOfWhole,
                NumberPhase::PastWhole,
                NumberPhase::PastFirstDigitOfFractional,
                NumberPhase::PastFirstDigitOfExponent => $state->makeTokenFromBuffer(TokenType::Number),
                default => $state->throw('Unexpected end of input while processing number'),
            };
        }
    }

    private static function isDigit(?string $char): bool
    {
        if ($char === null) {
            return false;
        }
        return $char >= '0' && $char <= '9';
    }

    private static function isHex(?string $char): bool
    {
        if ($char === null) {
            return false;
        }
        return ($char >= '0' && $char <= '9')
            || ($char >= 'a' && $char <= 'f')
            || ($char >= 'A' && $char <= 'F');
    }

    private static function isLegalAfterBackslash(?string $char): bool
    {
        return match ($char) {
            '"', '\\', '/', 'b', 'f', 'n', 'r', 't', 'u' => true,
            default => false,
        };
    }

    private static function isControl(int $charCode): bool
    {
        // Only check for ASCII control characters (0x00-0x1F and 0x7F).
        // In UTF-8, bytes 0x80-0xBF are continuation bytes of multi-byte sequences,
        // not control characters. The Unicode C1 control codes (U+0080-U+009F)
        // would be encoded as 2-byte sequences, so checking raw bytes is wrong.
        return ($charCode >= 0x00 && $charCode <= 0x1F)
            || ($charCode === 0x7F);
    }
}

enum NumberPhase
{
    case Beginning;
    case PastLeadingSign;
    case PastFirstDigitOfWhole;
    case PastWhole;
    case PastDecimalPoint;
    case PastFirstDigitOfFractional;
    case PastE;
    case PastExpSign;
    case PastFirstDigitOfExponent;
}

enum CharHandling
{
    case InvalidatesToken;
    case ValidAndConsumed;
    case StartOfNewToken;
}
