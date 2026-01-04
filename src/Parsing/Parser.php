<?php

declare(strict_types=1);

namespace Rhinox\FracturedJson\Parsing;

use Rhinox\FracturedJson\Enums\CommentPolicy;
use Rhinox\FracturedJson\Enums\JsonItemType;
use Rhinox\FracturedJson\Enums\TokenType;
use Rhinox\FracturedJson\Exceptions\FracturedJsonException;
use Rhinox\FracturedJson\Formatting\FracturedJsonOptions;
use Rhinox\FracturedJson\Scanning\InputPosition;
use Rhinox\FracturedJson\Scanning\JsonToken;
use Rhinox\FracturedJson\Scanning\TokenEnumerator;
use Rhinox\FracturedJson\Scanning\TokenGenerator;

class Parser
{
    public FracturedJsonOptions $options;

    public function __construct(?FracturedJsonOptions $options = null)
    {
        $this->options = $options ?? new FracturedJsonOptions();
    }

    /**
     * @return JsonItem[]
     */
    public function parseTopLevel(string $inputJson, bool $stopAfterFirstElem): array
    {
        $tokenStream = new TokenEnumerator(TokenGenerator::generate($inputJson));
        return $this->parseTopLevelFromEnum($tokenStream, $stopAfterFirstElem);
    }

    /**
     * @return JsonItem[]
     */
    private function parseTopLevelFromEnum(TokenEnumerator $enumerator, bool $stopAfterFirstElem): array
    {
        $topLevelItems = [];

        $topLevelElemSeen = false;
        while (true) {
            if (!$enumerator->moveNext()) {
                return $topLevelItems;
            }

            $item = $this->parseItem($enumerator);
            $isComment = $item->type === JsonItemType::BlockComment || $item->type === JsonItemType::LineComment;
            $isBlank = $item->type === JsonItemType::BlankLine;

            if ($isBlank) {
                if ($this->options->preserveBlankLines) {
                    $topLevelItems[] = $item;
                }
            } elseif ($isComment) {
                if ($this->options->commentPolicy === CommentPolicy::TreatAsError) {
                    throw new FracturedJsonException('Comments not allowed with current options', $item->inputPosition);
                }
                if ($this->options->commentPolicy === CommentPolicy::Preserve) {
                    $topLevelItems[] = $item;
                }
            } else {
                if ($stopAfterFirstElem && $topLevelElemSeen) {
                    throw new FracturedJsonException('Unexpected start of second top level element', $item->inputPosition);
                }
                $topLevelItems[] = $item;
                $topLevelElemSeen = true;
            }
        }
    }

    private function parseItem(TokenEnumerator $enumerator): JsonItem
    {
        return match ($enumerator->getCurrent()->type) {
            TokenType::BeginArray => $this->parseArray($enumerator),
            TokenType::BeginObject => $this->parseObject($enumerator),
            default => $this->parseSimple($enumerator->getCurrent()),
        };
    }

    private function parseSimple(JsonToken $token): JsonItem
    {
        $item = new JsonItem();
        $item->type = self::itemTypeFromTokenType($token);
        $item->value = $token->text;
        $item->inputPosition = $token->inputPosition;
        $item->complexity = 0;

        return $item;
    }

    /**
     * Parse the stream of tokens into a JSON array (recursively).
     * The enumerator should be pointing to the open square bracket token at the start of the call.
     * It will be pointing to the closing bracket when the call returns.
     */
    private function parseArray(TokenEnumerator $enumerator): JsonItem
    {
        if ($enumerator->getCurrent()->type !== TokenType::BeginArray) {
            throw new FracturedJsonException('Parser logic error', $enumerator->getCurrent()->inputPosition);
        }

        $startingInputPosition = $enumerator->getCurrent()->inputPosition;

        // Holder for an element that was already added to the child list that is eligible for a postfix comment.
        $elemNeedingPostComment = null;
        $elemNeedingPostEndRow = -1;

        // A single-line block comment that HAS NOT been added to the child list, that might serve as a prefix comment.
        $unplacedComment = null;

        $childList = [];
        $commaStatus = CommaStatus::EmptyCollection;
        $endOfArrayFound = false;
        $thisArrayComplexity = 0;

        while (!$endOfArrayFound) {
            $token = self::getNextTokenOrThrow($enumerator, $startingInputPosition);

            // If the token we're about to deal with isn't on the same line as an unplaced comment or is the end of the
            // array, this is our last chance to find a place for that comment.
            $unplacedCommentNeedsHome = $unplacedComment
                && ($unplacedComment->inputPosition->row !== $token->inputPosition->row || $token->type === TokenType::EndArray);

            if ($unplacedCommentNeedsHome) {
                if ($elemNeedingPostComment !== null) {
                    $elemNeedingPostComment->postfixComment = $unplacedComment->value;
                    $elemNeedingPostComment->isPostCommentLineStyle = ($unplacedComment->type === JsonItemType::LineComment);
                } else {
                    $childList[] = $unplacedComment;
                }
                $unplacedComment = null;
            }

            // If the token we're about to deal with isn't on the same line as the last element, the new token obviously
            // won't be a postfix comment.
            if ($elemNeedingPostComment !== null && $elemNeedingPostEndRow !== $token->inputPosition->row) {
                $elemNeedingPostComment = null;
            }

            switch ($token->type) {
                case TokenType::EndArray:
                    if ($commaStatus === CommaStatus::CommaSeen && !$this->options->allowTrailingCommas) {
                        throw new FracturedJsonException('Array may not end with a comma with current options', $token->inputPosition);
                    }
                    $endOfArrayFound = true;
                    break;

                case TokenType::Comma:
                    if ($commaStatus !== CommaStatus::ElementSeen) {
                        throw new FracturedJsonException('Unexpected comma in array', $token->inputPosition);
                    }
                    $commaStatus = CommaStatus::CommaSeen;
                    break;

                case TokenType::BlankLine:
                    if (!$this->options->preserveBlankLines) {
                        break;
                    }
                    $childList[] = $this->parseSimple($token);
                    break;

                case TokenType::BlockComment:
                    if ($this->options->commentPolicy === CommentPolicy::Remove) {
                        break;
                    }
                    if ($this->options->commentPolicy === CommentPolicy::TreatAsError) {
                        throw new FracturedJsonException('Comments not allowed with current options', $token->inputPosition);
                    }

                    if ($unplacedComment !== null) {
                        $childList[] = $unplacedComment;
                        $unplacedComment = null;
                    }

                    $commentItem = $this->parseSimple($token);
                    if (self::isMultilineComment($commentItem)) {
                        $childList[] = $commentItem;
                        break;
                    }

                    if ($elemNeedingPostComment !== null && $commaStatus === CommaStatus::ElementSeen) {
                        $elemNeedingPostComment->postfixComment = $commentItem->value;
                        $elemNeedingPostComment->isPostCommentLineStyle = false;
                        $elemNeedingPostComment = null;
                        break;
                    }

                    $unplacedComment = $commentItem;
                    break;

                case TokenType::LineComment:
                    if ($this->options->commentPolicy === CommentPolicy::Remove) {
                        break;
                    }
                    if ($this->options->commentPolicy === CommentPolicy::TreatAsError) {
                        throw new FracturedJsonException('Comments not allowed with current options', $token->inputPosition);
                    }

                    if ($unplacedComment !== null) {
                        $childList[] = $unplacedComment;
                        $childList[] = $this->parseSimple($token);
                        $unplacedComment = null;
                        break;
                    }

                    if ($elemNeedingPostComment !== null) {
                        $elemNeedingPostComment->postfixComment = $token->text;
                        $elemNeedingPostComment->isPostCommentLineStyle = true;
                        $elemNeedingPostComment = null;
                        break;
                    }

                    $childList[] = $this->parseSimple($token);
                    break;

                case TokenType::False:
                case TokenType::True:
                case TokenType::Null:
                case TokenType::String:
                case TokenType::Number:
                case TokenType::BeginArray:
                case TokenType::BeginObject:
                    if ($commaStatus === CommaStatus::ElementSeen) {
                        throw new FracturedJsonException('Comma missing while processing array', $token->inputPosition);
                    }

                    $element = $this->parseItem($enumerator);
                    $commaStatus = CommaStatus::ElementSeen;
                    $thisArrayComplexity = max($thisArrayComplexity, $element->complexity + 1);

                    if ($unplacedComment !== null) {
                        $element->prefixComment = $unplacedComment->value;
                        $unplacedComment = null;
                    }

                    $childList[] = $element;

                    $elemNeedingPostComment = $element;
                    $elemNeedingPostEndRow = $enumerator->getCurrent()->inputPosition->row;
                    break;

                default:
                    throw new FracturedJsonException('Unexpected token in array', $token->inputPosition);
            }
        }

        $arrayItem = new JsonItem();
        $arrayItem->type = JsonItemType::Array;
        $arrayItem->inputPosition = $startingInputPosition;
        $arrayItem->complexity = $thisArrayComplexity;
        $arrayItem->children = $childList;

        return $arrayItem;
    }

    /**
     * Parse the stream of tokens into a JSON object (recursively).
     * The enumerator should be pointing to the open curly bracket token at the start of the call.
     * It will be pointing to the closing bracket when the call returns.
     */
    private function parseObject(TokenEnumerator $enumerator): JsonItem
    {
        if ($enumerator->getCurrent()->type !== TokenType::BeginObject) {
            throw new FracturedJsonException('Parser logic error', $enumerator->getCurrent()->inputPosition);
        }

        $startingInputPosition = $enumerator->getCurrent()->inputPosition;
        $childList = [];

        $propertyName = null;
        $propertyValue = null;
        $linePropValueEnds = -1;
        $beforePropComments = [];
        $midPropComments = [];
        $afterPropComment = null;
        $afterPropCommentWasAfterComma = false;

        $phase = ObjectPhase::BeforePropName;
        $thisObjComplexity = 0;
        $endOfObject = false;

        while (!$endOfObject) {
            $token = self::getNextTokenOrThrow($enumerator, $startingInputPosition);

            $isNewLine = ($linePropValueEnds !== $token->inputPosition->row);
            $isEndOfObject = ($token->type === TokenType::EndObject);
            $startingNextPropName = ($token->type === TokenType::String && $phase === ObjectPhase::AfterComma);
            $isExcessPostComment = $afterPropComment !== null
                && ($token->type === TokenType::BlockComment || $token->type === TokenType::LineComment);
            $needToFlush = $propertyName !== null && $propertyValue !== null
                && ($isNewLine || $isEndOfObject || $startingNextPropName || $isExcessPostComment);

            if ($needToFlush) {
                $commentToHoldForNextElem = null;
                if ($startingNextPropName && $afterPropCommentWasAfterComma && !$isNewLine) {
                    $commentToHoldForNextElem = $afterPropComment;
                    $afterPropComment = null;
                }

                self::attachObjectValuePieces(
                    $childList,
                    $propertyName,
                    $propertyValue,
                    $linePropValueEnds,
                    $beforePropComments,
                    $midPropComments,
                    $afterPropComment
                );
                $thisObjComplexity = max($thisObjComplexity, $propertyValue->complexity + 1);
                $propertyName = null;
                $propertyValue = null;
                $beforePropComments = [];
                $midPropComments = [];
                $afterPropComment = null;

                if ($commentToHoldForNextElem !== null) {
                    $beforePropComments[] = $commentToHoldForNextElem;
                }
            }

            switch ($token->type) {
                case TokenType::BlankLine:
                    if (!$this->options->preserveBlankLines) {
                        break;
                    }
                    if ($phase === ObjectPhase::AfterPropName || $phase === ObjectPhase::AfterColon) {
                        break;
                    }

                    array_push($childList, ...$beforePropComments);
                    $beforePropComments = [];
                    $childList[] = $this->parseSimple($token);
                    break;

                case TokenType::BlockComment:
                case TokenType::LineComment:
                    if ($this->options->commentPolicy === CommentPolicy::Remove) {
                        break;
                    }
                    if ($this->options->commentPolicy === CommentPolicy::TreatAsError) {
                        throw new FracturedJsonException('Comments not allowed with current options', $token->inputPosition);
                    }
                    if ($phase === ObjectPhase::BeforePropName || $propertyName === null) {
                        $beforePropComments[] = $this->parseSimple($token);
                    } elseif ($phase === ObjectPhase::AfterPropName || $phase === ObjectPhase::AfterColon) {
                        $midPropComments[] = $token;
                    } else {
                        $afterPropComment = $this->parseSimple($token);
                        $afterPropCommentWasAfterComma = ($phase === ObjectPhase::AfterComma);
                    }
                    break;

                case TokenType::EndObject:
                    if ($phase === ObjectPhase::AfterPropName || $phase === ObjectPhase::AfterColon) {
                        throw new FracturedJsonException('Unexpected end of object', $token->inputPosition);
                    }
                    $endOfObject = true;
                    break;

                case TokenType::String:
                    if ($phase === ObjectPhase::BeforePropName || $phase === ObjectPhase::AfterComma) {
                        $propertyName = $token;
                        $phase = ObjectPhase::AfterPropName;
                    } elseif ($phase === ObjectPhase::AfterColon) {
                        $propertyValue = $this->parseItem($enumerator);
                        $linePropValueEnds = $enumerator->getCurrent()->inputPosition->row;
                        $phase = ObjectPhase::AfterPropValue;
                    } else {
                        throw new FracturedJsonException('Unexpected string found while processing object', $token->inputPosition);
                    }
                    break;

                case TokenType::False:
                case TokenType::True:
                case TokenType::Null:
                case TokenType::Number:
                case TokenType::BeginArray:
                case TokenType::BeginObject:
                    if ($phase !== ObjectPhase::AfterColon) {
                        throw new FracturedJsonException('Unexpected element while processing object', $token->inputPosition);
                    }
                    $propertyValue = $this->parseItem($enumerator);
                    $linePropValueEnds = $enumerator->getCurrent()->inputPosition->row;
                    $phase = ObjectPhase::AfterPropValue;
                    break;

                case TokenType::Colon:
                    if ($phase !== ObjectPhase::AfterPropName) {
                        throw new FracturedJsonException('Unexpected colon while processing object', $token->inputPosition);
                    }
                    $phase = ObjectPhase::AfterColon;
                    break;

                case TokenType::Comma:
                    if ($phase !== ObjectPhase::AfterPropValue) {
                        throw new FracturedJsonException('Unexpected comma while processing object', $token->inputPosition);
                    }
                    $phase = ObjectPhase::AfterComma;
                    break;

                default:
                    throw new FracturedJsonException('Unexpected token while processing object', $token->inputPosition);
            }
        }

        if (!$this->options->allowTrailingCommas && $phase === ObjectPhase::AfterComma) {
            throw new FracturedJsonException('Object may not end with comma with current options', $enumerator->getCurrent()->inputPosition);
        }

        $objItem = new JsonItem();
        $objItem->type = JsonItemType::Object;
        $objItem->inputPosition = $startingInputPosition;
        $objItem->complexity = $thisObjComplexity;
        $objItem->children = $childList;

        return $objItem;
    }

    private static function itemTypeFromTokenType(JsonToken $token): JsonItemType
    {
        return match ($token->type) {
            TokenType::False => JsonItemType::False,
            TokenType::True => JsonItemType::True,
            TokenType::Null => JsonItemType::Null,
            TokenType::Number => JsonItemType::Number,
            TokenType::String => JsonItemType::String,
            TokenType::BlankLine => JsonItemType::BlankLine,
            TokenType::BlockComment => JsonItemType::BlockComment,
            TokenType::LineComment => JsonItemType::LineComment,
            default => throw new FracturedJsonException('Unexpected Token', $token->inputPosition),
        };
    }

    private static function getNextTokenOrThrow(TokenEnumerator $enumerator, InputPosition $startPosition): JsonToken
    {
        if (!$enumerator->moveNext()) {
            throw new FracturedJsonException('Unexpected end of input while processing array or object starting', $startPosition);
        }
        return $enumerator->getCurrent();
    }

    private static function isMultilineComment(JsonItem $item): bool
    {
        return $item->type === JsonItemType::BlockComment && str_contains($item->value, "\n");
    }

    /**
     * @param JsonItem[] $objItemList
     * @param JsonItem[] $beforeComments
     * @param JsonToken[] $midComments
     */
    private static function attachObjectValuePieces(
        array &$objItemList,
        JsonToken $name,
        JsonItem $element,
        int $valueEndingLine,
        array $beforeComments,
        array $midComments,
        ?JsonItem $afterComment
    ): void {
        $element->name = $name->text;

        // Deal with any comments between the property name and its element.
        if (count($midComments) > 0) {
            $combined = '';
            $count = count($midComments);
            for ($i = 0; $i < $count; $i++) {
                $combined .= $midComments[$i]->text;
                if ($i < $count - 1 || $midComments[$i]->type === TokenType::LineComment) {
                    $combined .= "\n";
                }
            }

            $element->middleComment = $combined;
            $element->middleCommentHasNewLine = str_contains($combined, "\n");
        }

        // Figure out if the last of the comments before the prop name should be attached to this element.
        if (count($beforeComments) > 0) {
            $lastOfBefore = array_pop($beforeComments);
            if ($lastOfBefore->type === JsonItemType::BlockComment
                && $lastOfBefore->inputPosition->row === $element->inputPosition->row) {
                $element->prefixComment = $lastOfBefore->value;
                array_push($objItemList, ...$beforeComments);
            } else {
                array_push($objItemList, ...$beforeComments);
                $objItemList[] = $lastOfBefore;
            }
        }

        $objItemList[] = $element;

        // Figure out if the first of the comments after the element should be attached to the element.
        if ($afterComment !== null) {
            if (!self::isMultilineComment($afterComment) && $afterComment->inputPosition->row === $valueEndingLine) {
                $element->postfixComment = $afterComment->value;
                $element->isPostCommentLineStyle = ($afterComment->type === JsonItemType::LineComment);
            } else {
                $objItemList[] = $afterComment;
            }
        }
    }
}

enum CommaStatus
{
    case EmptyCollection;
    case ElementSeen;
    case CommaSeen;
}

enum ObjectPhase
{
    case BeforePropName;
    case AfterPropName;
    case AfterColon;
    case AfterPropValue;
    case AfterComma;
}
