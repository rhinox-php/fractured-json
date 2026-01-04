<?php

declare(strict_types=1);

namespace Rhinox\FracturedJson\Formatting;

use Rhinox\FracturedJson\Enums\BracketPaddingType;
use Rhinox\FracturedJson\Enums\JsonItemType;
use Rhinox\FracturedJson\Enums\TableColumnType;
use Rhinox\FracturedJson\Enums\TableCommaPlacement;
use Rhinox\FracturedJson\Exceptions\FracturedJsonException;
use Rhinox\FracturedJson\Parsing\JsonItem;
use Rhinox\FracturedJson\Parsing\Parser;

/**
 * Class that writes JSON data in a human-friendly format.
 * Comments are optionally supported.
 */
class Formatter
{
    /**
     * Settings to control the appearance of the output and what sort of input is permissible.
     */
    public FracturedJsonOptions $options;

    /**
     * Function that measures strings for alignment purposes.
     *
     * @var callable(string): int
     */
    public $stringLengthFunc;

    private BufferInterface $buffer;
    private PaddedFormattingTokens $pads;

    public function __construct(?FracturedJsonOptions $options = null)
    {
        $this->options = $options ?? new FracturedJsonOptions();
        $this->stringLengthFunc = self::stringLengthByCharCount(...);
        $this->buffer = new StringJoinBuffer();
        $this->pads = new PaddedFormattingTokens($this->options, $this->stringLengthFunc);
    }

    /**
     * The default string length function. Returns character count.
     * Uses mb_strlen to match JavaScript's string.length behavior.
     */
    public static function stringLengthByCharCount(string $str): int
    {
        return mb_strlen($str, 'UTF-8');
    }

    /**
     * Reads in JSON text (or JSON-with-comments), and returns a nicely-formatted string of the same content.
     */
    public function reformat(string $jsonText, int $startingDepth = 0): string
    {
        $buffer = new StringJoinBuffer();
        $parser = new Parser($this->options);
        $docModel = $parser->parseTopLevel($jsonText, true);
        $this->formatTopLevel($docModel, $startingDepth, $buffer);

        $buffer->flush();
        return $buffer->asString();
    }

    /**
     * Writes the serialized object as a nicely-formatted string.
     */
    public function serialize(mixed $element, int $startingDepth = 0, int $recursionLimit = 100): ?string
    {
        $buffer = new StringJoinBuffer();

        $docModel = DataToDomConverter::convert($element, null, $recursionLimit);
        if ($docModel === null) {
            return null;
        }

        $this->formatTopLevel([$docModel], $startingDepth, $buffer);

        $buffer->flush();
        return $buffer->asString();
    }

    /**
     * Writes a version of the given JSON (or JSON-with-comments) text that has all unnecessary space removed
     * while still preserving comments and blank lines, if that's what the settings require.
     */
    public function minify(string $jsonText): string
    {
        $buffer = new StringJoinBuffer();
        $parser = new Parser($this->options);
        $docModel = $parser->parseTopLevel($jsonText, true);
        $this->minifyTopLevel($docModel, $buffer);

        $buffer->flush();
        return $buffer->asString();
    }

    /**
     * @param JsonItem[] $docModel
     */
    private function formatTopLevel(array $docModel, int $startingDepth, BufferInterface $buffer): void
    {
        $this->buffer = $buffer;
        $this->pads = new PaddedFormattingTokens($this->options, $this->stringLengthFunc);

        foreach ($docModel as $item) {
            $this->computeItemLengths($item);
            $this->formatItem($item, $startingDepth, false, null);
        }

        $this->buffer = new StringJoinBuffer();
    }

    /**
     * @param JsonItem[] $docModel
     */
    private function minifyTopLevel(array $docModel, BufferInterface $buffer): void
    {
        $this->buffer = $buffer;
        $this->pads = new PaddedFormattingTokens($this->options, $this->stringLengthFunc);

        $atStartOfNewLine = true;
        foreach ($docModel as $item) {
            $atStartOfNewLine = $this->minifyItem($item, $atStartOfNewLine);
        }

        $this->buffer = new StringJoinBuffer();
    }

    /**
     * Runs StringLengthFunc on every part of every item and stores the value.
     */
    private function computeItemLengths(JsonItem $item): void
    {
        foreach ($item->children as $child) {
            $this->computeItemLengths($child);
        }

        $item->valueLength = match ($item->type) {
            JsonItemType::Null => $this->pads->getLiteralNullLen(),
            JsonItemType::True => $this->pads->getLiteralTrueLen(),
            JsonItemType::False => $this->pads->getLiteralFalseLen(),
            default => ($this->stringLengthFunc)($item->value),
        };

        $item->nameLength = ($this->stringLengthFunc)($item->name);
        $item->prefixCommentLength = ($this->stringLengthFunc)($item->prefixComment);
        $item->middleCommentLength = ($this->stringLengthFunc)($item->middleComment);
        $item->postfixCommentLength = ($this->stringLengthFunc)($item->postfixComment);

        $item->requiresMultipleLines =
            self::isCommentOrBlankLine($item->type)
            || $this->anyChildRequiresMultipleLines($item)
            || str_contains($item->prefixComment, "\n")
            || str_contains($item->middleComment, "\n")
            || str_contains($item->postfixComment, "\n")
            || str_contains($item->value, "\n");

        if ($item->type === JsonItemType::Array || $item->type === JsonItemType::Object) {
            $padType = self::getPaddingType($item);
            $childrenLength = array_sum(array_map(fn($ch) => $ch->minimumTotalLength, $item->children));
            $item->valueLength =
                $this->pads->startLen($item->type, $padType)
                + $this->pads->endLen($item->type, $padType)
                + $childrenLength
                + max(0, $this->pads->getCommaLen() * (count($item->children) - 1));
        }

        $item->minimumTotalLength =
            ($item->prefixCommentLength > 0 ? $item->prefixCommentLength + $this->pads->getCommentLen() : 0)
            + ($item->nameLength > 0 ? $item->nameLength + $this->pads->getColonLen() : 0)
            + ($item->middleCommentLength > 0 ? $item->middleCommentLength + $this->pads->getCommentLen() : 0)
            + $item->valueLength
            + ($item->postfixCommentLength > 0 ? $item->postfixCommentLength + $this->pads->getCommentLen() : 0);
    }

    private function anyChildRequiresMultipleLines(JsonItem $item): bool
    {
        foreach ($item->children as $child) {
            if ($child->requiresMultipleLines || $child->isPostCommentLineStyle) {
                return true;
            }
        }
        return false;
    }

    /**
     * Adds a formatted version of any item to the buffer, including indentation and newlines as needed.
     */
    private function formatItem(JsonItem $item, int $depth, bool $includeTrailingComma, ?TableTemplate $parentTemplate): void
    {
        switch ($item->type) {
            case JsonItemType::Array:
            case JsonItemType::Object:
                $this->formatContainer($item, $depth, $includeTrailingComma, $parentTemplate);
                break;
            case JsonItemType::BlankLine:
                $this->formatBlankLine();
                break;
            case JsonItemType::BlockComment:
            case JsonItemType::LineComment:
                $this->formatStandaloneComment($item, $depth);
                break;
            default:
                if ($item->requiresMultipleLines) {
                    $this->formatSplitKeyValue($item, $depth, $includeTrailingComma, $parentTemplate);
                } else {
                    $this->formatInlineElement($item, $depth, $includeTrailingComma, $parentTemplate);
                }
                break;
        }
    }

    /**
     * Adds the representation for an array or object to the buffer.
     */
    private function formatContainer(JsonItem $item, int $depth, bool $includeTrailingComma, ?TableTemplate $parentTemplate): void
    {
        if ($depth > $this->options->alwaysExpandDepth) {
            if ($this->formatContainerInline($item, $depth, $includeTrailingComma, $parentTemplate)) {
                return;
            }
        }

        $recursiveTemplate = ($item->complexity <= $this->options->maxCompactArrayComplexity)
            || ($item->complexity <= $this->options->maxTableRowComplexity + 1);
        $template = new TableTemplate($this->pads, $this->options->numberListAlignment);
        $template->measureTableRoot($item, $recursiveTemplate);

        if ($depth > $this->options->alwaysExpandDepth) {
            if ($this->formatContainerCompactMultiline($item, $depth, $includeTrailingComma, $template, $parentTemplate)) {
                return;
            }
        }

        if ($depth >= $this->options->alwaysExpandDepth) {
            if ($this->formatContainerTable($item, $depth, $includeTrailingComma, $template, $parentTemplate)) {
                return;
            }
        }

        $this->formatContainerExpanded($item, $depth, $includeTrailingComma, $template, $parentTemplate);
    }

    /**
     * Tries to add the representation for an array or object inline.
     */
    private function formatContainerInline(JsonItem $item, int $depth, bool $includeTrailingComma, ?TableTemplate $parentTemplate): bool
    {
        if ($item->requiresMultipleLines) {
            return false;
        }

        if ($parentTemplate !== null) {
            $prefixLength = $parentTemplate->prefixCommentLength > 0
                ? $parentTemplate->prefixCommentLength + $this->pads->getCommentLen()
                : 0;
            $nameLength = $parentTemplate->nameLength > 0
                ? $parentTemplate->nameLength + $this->pads->getColonLen()
                : 0;
        } else {
            $prefixLength = $item->prefixCommentLength > 0
                ? $item->prefixCommentLength + $this->pads->getCommentLen()
                : 0;
            $nameLength = $item->nameLength > 0
                ? $item->nameLength + $this->pads->getColonLen()
                : 0;
        }

        $lengthToConsider = $prefixLength
            + $nameLength
            + ($item->middleCommentLength > 0 ? $item->middleCommentLength + $this->pads->getCommentLen() : 0)
            + $item->valueLength
            + ($item->postfixCommentLength > 0 ? $item->postfixCommentLength + $this->pads->getCommentLen() : 0)
            + ($includeTrailingComma ? $this->pads->getCommaLen() : 0);

        if ($item->complexity > $this->options->maxInlineComplexity || $lengthToConsider > $this->availableLineSpace($depth)) {
            return false;
        }

        $this->buffer->add($this->options->prefixString, $this->pads->indent($depth));
        $this->inlineElement($item, $includeTrailingComma, $parentTemplate);
        $this->buffer->endLine($this->pads->getEol());

        return true;
    }

    /**
     * Tries to add the representation of this array as compact multiline.
     */
    private function formatContainerCompactMultiline(
        JsonItem $item,
        int $depth,
        bool $includeTrailingComma,
        TableTemplate $template,
        ?TableTemplate $parentTemplate
    ): bool {
        if ($item->type !== JsonItemType::Array) {
            return false;
        }
        if (count($item->children) === 0 || count($item->children) < $this->options->minCompactArrayRowItems) {
            return false;
        }
        if ($item->complexity > $this->options->maxCompactArrayComplexity) {
            return false;
        }
        if ($item->requiresMultipleLines) {
            return false;
        }

        $useTableFormatting = $template->type !== TableColumnType::Unknown && $template->type !== TableColumnType::Mixed;

        $likelyAvailableLineSpace = $this->availableLineSpace($depth + 1);

        $avgItemWidth = $this->pads->getCommaLen();
        if ($useTableFormatting) {
            $avgItemWidth += $template->totalLength;
        } else {
            $totalMinLen = array_sum(array_map(fn($ch) => $ch->minimumTotalLength, $item->children));
            $avgItemWidth += $totalMinLen / count($item->children);
        }
        if ($avgItemWidth * $this->options->minCompactArrayRowItems > $likelyAvailableLineSpace) {
            return false;
        }

        $depthAfterColon = $this->standardFormatStart($item, $depth, $parentTemplate);
        $this->buffer->add($this->pads->start($item->type, BracketPaddingType::Empty));

        $availableLineSpace = $this->availableLineSpace($depthAfterColon + 1);
        $remainingLineSpace = -1;
        $childCount = count($item->children);
        for ($i = 0; $i < $childCount; $i++) {
            $child = $item->children[$i];
            $needsComma = ($i < $childCount - 1);
            $spaceNeededForNext = ($needsComma ? $this->pads->getCommaLen() : 0)
                + ($useTableFormatting ? $template->totalLength : $child->minimumTotalLength);

            if ($remainingLineSpace < $spaceNeededForNext) {
                $this->buffer->endLine($this->pads->getEol())
                    ->add($this->options->prefixString, $this->pads->indent($depthAfterColon + 1));
                $remainingLineSpace = $availableLineSpace;
            }

            if ($useTableFormatting) {
                $this->inlineTableRowSegment($template, $child, $needsComma, false);
            } else {
                $this->inlineElement($child, $needsComma, null);
            }
            $remainingLineSpace -= $spaceNeededForNext;
        }

        $this->buffer->endLine($this->pads->getEol())
            ->add($this->options->prefixString, $this->pads->indent($depthAfterColon), $this->pads->end($item->type, BracketPaddingType::Empty));

        $this->standardFormatEnd($item, $includeTrailingComma);
        return true;
    }

    /**
     * Tries to format this array/object as a table.
     */
    private function formatContainerTable(
        JsonItem $item,
        int $depth,
        bool $includeTrailingComma,
        TableTemplate $template,
        ?TableTemplate $parentTemplate
    ): bool {
        if ($item->complexity > $this->options->maxTableRowComplexity + 1) {
            return false;
        }

        if ($template->requiresMultipleLines) {
            return false;
        }

        $availableSpaceDepth = $item->middleCommentHasNewLine ? $depth + 2 : $depth + 1;
        $availableSpace = $this->availableLineSpace($availableSpaceDepth) - $this->pads->getCommaLen();

        foreach ($item->children as $child) {
            if (!self::isCommentOrBlankLine($child->type) && $child->minimumTotalLength > $availableSpace) {
                return false;
            }
        }

        if ($template->type === TableColumnType::Mixed || !$template->tryToFit($availableSpace)) {
            return false;
        }

        $depthAfterColon = $this->standardFormatStart($item, $depth, $parentTemplate);
        $this->buffer->add($this->pads->start($item->type, BracketPaddingType::Empty))->endLine($this->pads->getEol());

        $lastElementIndex = self::indexOfLastElement($item->children);
        $childCount = count($item->children);
        for ($i = 0; $i < $childCount; $i++) {
            $rowItem = $item->children[$i];
            if ($rowItem->type === JsonItemType::BlankLine) {
                $this->formatBlankLine();
                continue;
            }
            if ($rowItem->type === JsonItemType::LineComment || $rowItem->type === JsonItemType::BlockComment) {
                $this->formatStandaloneComment($rowItem, $depthAfterColon + 1);
                continue;
            }

            $this->buffer->add($this->options->prefixString, $this->pads->indent($depthAfterColon + 1));
            $this->inlineTableRowSegment($template, $rowItem, ($i < $lastElementIndex), true);
            $this->buffer->endLine($this->pads->getEol());
        }

        $this->buffer->add($this->options->prefixString, $this->pads->indent($depthAfterColon), $this->pads->end($item->type, BracketPaddingType::Empty));
        $this->standardFormatEnd($item, $includeTrailingComma);

        return true;
    }

    /**
     * Adds the representation for an array or object to the buffer, broken out on separate lines.
     */
    private function formatContainerExpanded(
        JsonItem $item,
        int $depth,
        bool $includeTrailingComma,
        TableTemplate $template,
        ?TableTemplate $parentTemplate
    ): void {
        $depthAfterColon = $this->standardFormatStart($item, $depth, $parentTemplate);
        $this->buffer->add($this->pads->start($item->type, BracketPaddingType::Empty))->endLine($this->pads->getEol());

        $alignProps = $item->type === JsonItemType::Object
            && $template->nameLength - $template->nameMinimum <= $this->options->maxPropNamePadding
            && !$template->anyMiddleCommentHasNewline
            && $this->availableLineSpace($depth + 1) >= $template->atomicItemSize();
        $templateToPass = $alignProps ? $template : null;

        $lastElementIndex = self::indexOfLastElement($item->children);
        $childCount = count($item->children);
        for ($i = 0; $i < $childCount; $i++) {
            $this->formatItem($item->children[$i], $depthAfterColon + 1, ($i < $lastElementIndex), $templateToPass);
        }

        $this->buffer->add($this->options->prefixString, $this->pads->indent($depthAfterColon), $this->pads->end($item->type, BracketPaddingType::Empty));
        $this->standardFormatEnd($item, $includeTrailingComma);
    }

    /**
     * Adds a (possibly multiline) standalone comment to the buffer.
     */
    private function formatStandaloneComment(JsonItem $item, int $depth): void
    {
        $commentRows = self::normalizeMultilineComment($item->value, $item->inputPosition->column);

        foreach ($commentRows as $line) {
            $this->buffer->add($this->options->prefixString, $this->pads->indent($depth), $line)->endLine($this->pads->getEol());
        }
    }

    private function formatBlankLine(): void
    {
        $this->buffer->add($this->options->prefixString)->endLine($this->pads->getEol());
    }

    /**
     * Adds an element to the buffer that can be written as a single line.
     */
    private function formatInlineElement(JsonItem $item, int $depth, bool $includeTrailingComma, ?TableTemplate $parentTemplate): void
    {
        $this->buffer->add($this->options->prefixString, $this->pads->indent($depth));
        $this->inlineElement($item, $includeTrailingComma, $parentTemplate);
        $this->buffer->endLine($this->pads->getEol());
    }

    /**
     * Adds an item to the buffer where a comment between prop name and prop value needs to span multiple lines.
     */
    private function formatSplitKeyValue(JsonItem $item, int $depth, bool $includeTrailingComma, ?TableTemplate $parentTemplate): void
    {
        $this->standardFormatStart($item, $depth, $parentTemplate);
        $this->buffer->add($item->value);
        $this->standardFormatEnd($item, $includeTrailingComma);
    }

    /**
     * Do the stuff that's the same for the start of every formatted item.
     */
    private function standardFormatStart(JsonItem $item, int $depth, ?TableTemplate $parentTemplate): int
    {
        $this->buffer->add($this->options->prefixString, $this->pads->indent($depth));

        if ($parentTemplate !== null) {
            $this->addToBufferFixed(
                $item->prefixComment,
                $item->prefixCommentLength,
                $parentTemplate->prefixCommentLength,
                $this->pads->getComment(),
                false
            );
            $this->addToBufferFixed(
                $item->name,
                $item->nameLength,
                $parentTemplate->nameLength,
                $this->pads->getColon(),
                $this->options->colonBeforePropNamePadding
            );
        } else {
            $this->addToBuffer($item->prefixComment, $item->prefixCommentLength, $this->pads->getComment());
            $this->addToBuffer($item->name, $item->nameLength, $this->pads->getColon());
        }

        if ($item->middleCommentLength === 0) {
            return $depth;
        }

        if (!$item->middleCommentHasNewLine) {
            $middlePad = $parentTemplate !== null
                ? $parentTemplate->middleCommentLength - $item->middleCommentLength
                : 0;
            $this->buffer->add($item->middleComment)->spaces($middlePad)->add($this->pads->getComment());
            return $depth;
        }

        $commentRows = self::normalizeMultilineComment($item->middleComment, PHP_INT_MAX);
        $this->buffer->endLine($this->pads->getEol());

        foreach ($commentRows as $row) {
            $this->buffer->add($this->options->prefixString, $this->pads->indent($depth + 1), $row)->endLine($this->pads->getEol());
        }

        $this->buffer->add($this->options->prefixString, $this->pads->indent($depth + 1));
        return $depth + 1;
    }

    /**
     * Do the stuff that's usually the same for the end of all formatted items.
     */
    private function standardFormatEnd(JsonItem $item, bool $includeTrailingComma): void
    {
        if ($includeTrailingComma && $item->isPostCommentLineStyle) {
            $this->buffer->add($this->pads->getComma());
        }
        if ($item->postfixCommentLength > 0) {
            $this->buffer->add($this->pads->getComment(), $item->postfixComment);
        }
        if ($includeTrailingComma && !$item->isPostCommentLineStyle) {
            $this->buffer->add($this->pads->getComma());
        }
        $this->buffer->endLine($this->pads->getEol());
    }

    /**
     * Adds the inline representation of this item to the buffer.
     */
    private function inlineElement(JsonItem $item, bool $includeTrailingComma, ?TableTemplate $parentTemplate): void
    {
        if ($item->requiresMultipleLines) {
            throw new FracturedJsonException('Logic error - trying to inline invalid element');
        }

        if ($parentTemplate !== null) {
            $this->addToBufferFixed(
                $item->prefixComment,
                $item->prefixCommentLength,
                $parentTemplate->prefixCommentLength,
                $this->pads->getComment(),
                false
            );
            $this->addToBufferFixed(
                $item->name,
                $item->nameLength,
                $parentTemplate->nameLength,
                $this->pads->getColon(),
                $this->options->colonBeforePropNamePadding
            );
            $this->addToBufferFixed(
                $item->middleComment,
                $item->middleCommentLength,
                $parentTemplate->middleCommentLength,
                $this->pads->getComment(),
                false
            );
        } else {
            $this->addToBuffer($item->prefixComment, $item->prefixCommentLength, $this->pads->getComment());
            $this->addToBuffer($item->name, $item->nameLength, $this->pads->getColon());
            $this->addToBuffer($item->middleComment, $item->middleCommentLength, $this->pads->getComment());
        }

        $this->inlineElementRaw($item);

        if ($includeTrailingComma && $item->isPostCommentLineStyle) {
            $this->buffer->add($this->pads->getComma());
        }
        if ($item->postfixCommentLength > 0) {
            $this->buffer->add($this->pads->getComment(), $item->postfixComment);
        }
        if ($includeTrailingComma && !$item->isPostCommentLineStyle) {
            $this->buffer->add($this->pads->getComma());
        }
    }

    /**
     * Adds just this element's value to the buffer, inlined.
     */
    private function inlineElementRaw(JsonItem $item): void
    {
        if ($item->type === JsonItemType::Array) {
            $padType = self::getPaddingType($item);
            $this->buffer->add($this->pads->arrStart($padType));

            $childCount = count($item->children);
            for ($i = 0; $i < $childCount; $i++) {
                $this->inlineElement($item->children[$i], ($i < $childCount - 1), null);
            }

            $this->buffer->add($this->pads->arrEnd($padType));
        } elseif ($item->type === JsonItemType::Object) {
            $padType = self::getPaddingType($item);
            $this->buffer->add($this->pads->objStart($padType));

            $childCount = count($item->children);
            for ($i = 0; $i < $childCount; $i++) {
                $this->inlineElement($item->children[$i], ($i < $childCount - 1), null);
            }

            $this->buffer->add($this->pads->objEnd($padType));
        } else {
            $this->buffer->add($item->value);
        }
    }

    /**
     * Adds this item's representation to the buffer inlined, formatted according to the given TableTemplate.
     */
    private function inlineTableRowSegment(TableTemplate $template, JsonItem $item, bool $includeTrailingComma, bool $isWholeRow): void
    {
        $this->addToBufferFixed(
            $item->prefixComment,
            $item->prefixCommentLength,
            $template->prefixCommentLength,
            $this->pads->getComment(),
            false
        );
        $this->addToBufferFixed(
            $item->name,
            $item->nameLength,
            $template->nameLength,
            $this->pads->getColon(),
            $this->options->colonBeforePropNamePadding
        );
        $this->addToBufferFixed(
            $item->middleComment,
            $item->middleCommentLength,
            $template->middleCommentLength,
            $this->pads->getComment(),
            false
        );

        $commaBeforePad = $this->options->tableCommaPlacement === TableCommaPlacement::BeforePadding
            || ($this->options->tableCommaPlacement === TableCommaPlacement::BeforePaddingExceptNumbers
                && $template->type !== TableColumnType::Number);

        if ($template->postfixCommentLength > 0 && !$template->isAnyPostCommentLineStyle) {
            if ($item->postfixCommentLength > 0) {
                $commaPos = $commaBeforePad ? CommaPosition::BeforeCommentPadding : CommaPosition::AfterCommentPadding;
            } else {
                $commaPos = $commaBeforePad ? CommaPosition::BeforeValuePadding : CommaPosition::AfterCommentPadding;
            }
        } else {
            $commaPos = $commaBeforePad ? CommaPosition::BeforeValuePadding : CommaPosition::AfterValuePadding;
        }

        $commaType = $includeTrailingComma
            ? $this->pads->getComma()
            : ($isWholeRow ? $this->pads->getDummyComma() : '');

        if (count($template->children) > 0 && $item->type !== JsonItemType::Null) {
            if ($template->type === TableColumnType::Array) {
                $this->inlineTableRawArray($template, $item);
            } else {
                $this->inlineTableRawObject($template, $item);
            }
            if ($commaPos === CommaPosition::BeforeValuePadding) {
                $this->buffer->add($commaType);
            }

            if ($template->shorterThanNullAdjustment > 0) {
                $this->buffer->spaces($template->shorterThanNullAdjustment);
            }
        } elseif ($template->type === TableColumnType::Number) {
            $numberCommaType = $commaPos === CommaPosition::BeforeValuePadding ? $commaType : '';
            $template->formatNumber($this->buffer, $item, $numberCommaType);
        } else {
            $this->inlineElementRaw($item);
            if ($commaPos === CommaPosition::BeforeValuePadding) {
                $this->buffer->add($commaType);
            }
            $this->buffer->spaces($template->compositeValueLength - $item->valueLength);
        }

        if ($commaPos === CommaPosition::AfterValuePadding) {
            $this->buffer->add($commaType);
        }

        if ($template->postfixCommentLength > 0) {
            $this->buffer->add($this->pads->getComment(), $item->postfixComment);
        }

        if ($commaPos === CommaPosition::BeforeCommentPadding) {
            $this->buffer->add($commaType);
        }

        $this->buffer->spaces($template->postfixCommentLength - $item->postfixCommentLength);

        if ($commaPos === CommaPosition::AfterCommentPadding) {
            $this->buffer->add($commaType);
        }
    }

    /**
     * Adds just this ARRAY's value inlined.
     */
    private function inlineTableRawArray(TableTemplate $template, JsonItem $item): void
    {
        $this->buffer->add($this->pads->arrStart($template->padType));
        $templateChildCount = count($template->children);
        $itemChildCount = count($item->children);

        for ($i = 0; $i < $templateChildCount; $i++) {
            $isLastInTemplate = ($i === $templateChildCount - 1);
            $isLastInArray = ($i === $itemChildCount - 1);
            $isPastEndOfArray = ($i >= $itemChildCount);
            $subTemplate = $template->children[$i];

            if ($isPastEndOfArray) {
                $this->buffer->spaces($subTemplate->totalLength);
                if (!$isLastInTemplate) {
                    $this->buffer->add($this->pads->getDummyComma());
                }
            } else {
                $this->inlineTableRowSegment($subTemplate, $item->children[$i], !$isLastInArray, false);
                if ($isLastInArray && !$isLastInTemplate) {
                    $this->buffer->add($this->pads->getDummyComma());
                }
            }
        }
        $this->buffer->add($this->pads->arrEnd($template->padType));
    }

    /**
     * Adds just this OBJECT's value inlined.
     */
    private function inlineTableRawObject(TableTemplate $template, JsonItem $item): void
    {
        $matches = [];
        foreach ($template->children as $sub) {
            $matchingChild = null;
            foreach ($item->children as $ch) {
                if ($ch->name === $sub->locationInParent) {
                    $matchingChild = $ch;
                    break;
                }
            }
            $matches[] = ['tt' => $sub, 'ji' => $matchingChild];
        }

        $lastNonNullIdx = count($matches) - 1;
        while ($lastNonNullIdx >= 0 && $matches[$lastNonNullIdx]['ji'] === null) {
            $lastNonNullIdx--;
        }

        $this->buffer->add($this->pads->objStart($template->padType));
        $matchCount = count($matches);
        for ($i = 0; $i < $matchCount; $i++) {
            $subTemplate = $matches[$i]['tt'];
            $subItem = $matches[$i]['ji'];
            $isLastInObject = ($i === $lastNonNullIdx);
            $isLastInTemplate = ($i === $matchCount - 1);

            if ($subItem !== null) {
                $this->inlineTableRowSegment($subTemplate, $subItem, !$isLastInObject, false);
                if ($isLastInObject && !$isLastInTemplate) {
                    $this->buffer->add($this->pads->getDummyComma());
                }
            } else {
                $this->buffer->spaces($subTemplate->totalLength);
                if (!$isLastInTemplate) {
                    $this->buffer->add($this->pads->getDummyComma());
                }
            }
        }
        $this->buffer->add($this->pads->objEnd($template->padType));
    }

    /**
     * Figures out how much room is allowed for inlining at this indentation level.
     */
    private function availableLineSpace(int $depth): int
    {
        return $this->options->maxTotalLineLength - $this->pads->getPrefixStringLen() - $this->options->indentSpaces * $depth;
    }

    /**
     * Recursively write a minified version of the item to the buffer, while preserving comments.
     */
    private function minifyItem(JsonItem $item, bool $atStartOfNewLine): bool
    {
        $this->buffer->add($item->prefixComment);
        if (strlen($item->name) > 0) {
            $this->buffer->add($item->name, ':');
        }

        if (str_contains($item->middleComment, "\n")) {
            $normalizedComment = self::normalizeMultilineComment($item->middleComment, PHP_INT_MAX);
            foreach ($normalizedComment as $line) {
                $this->buffer->add($line, "\n");
            }
        } else {
            $this->buffer->add($item->middleComment);
        }

        if ($item->type === JsonItemType::Array || $item->type === JsonItemType::Object) {
            if ($item->type === JsonItemType::Array) {
                $this->buffer->add('[');
                $closeBracket = ']';
            } else {
                $this->buffer->add('{');
                $closeBracket = '}';
            }

            $needsComma = false;
            $atStartOfNewLine = false;
            foreach ($item->children as $child) {
                if (!self::isCommentOrBlankLine($child->type)) {
                    if ($needsComma) {
                        $this->buffer->add(',');
                    }
                    $needsComma = true;
                }
                $atStartOfNewLine = $this->minifyItem($child, $atStartOfNewLine);
            }
            $this->buffer->add($closeBracket);
        } elseif ($item->type === JsonItemType::BlankLine) {
            if (!$atStartOfNewLine) {
                $this->buffer->add("\n");
            }
            $this->buffer->add("\n");
            return true;
        } elseif ($item->type === JsonItemType::LineComment) {
            if (!$atStartOfNewLine) {
                $this->buffer->add("\n");
            }
            $this->buffer->add($item->value, "\n");
            return true;
        } elseif ($item->type === JsonItemType::BlockComment) {
            if (!$atStartOfNewLine) {
                $this->buffer->add("\n");
            }

            if (str_contains($item->value, "\n")) {
                $normalizedComment = self::normalizeMultilineComment($item->value, $item->inputPosition->column);
                foreach ($normalizedComment as $line) {
                    $this->buffer->add($line, "\n");
                }
                return true;
            }

            $this->buffer->add($item->value, "\n");
            return true;
        } else {
            $this->buffer->add($item->value);
        }

        $this->buffer->add($item->postfixComment);
        if (strlen($item->postfixComment) > 0 && $item->isPostCommentLineStyle) {
            $this->buffer->add("\n");
            return true;
        }

        return false;
    }

    private function addToBuffer(string $value, int $valueWidth, string $separator): void
    {
        if ($valueWidth <= 0) {
            return;
        }
        $this->buffer->add($value, $separator);
    }

    private function addToBufferFixed(string $value, int $valueWidth, int $fieldWidth, string $separator, bool $separatorBeforePadding): void
    {
        if ($fieldWidth <= 0) {
            return;
        }
        $padWidth = $fieldWidth - $valueWidth;
        if ($separatorBeforePadding) {
            $this->buffer->add($value, $separator)->spaces($padWidth);
        } else {
            $this->buffer->add($value)->spaces($padWidth)->add($separator);
        }
    }

    private static function getPaddingType(JsonItem $arrOrObj): BracketPaddingType
    {
        if (count($arrOrObj->children) === 0) {
            return BracketPaddingType::Empty;
        }

        return ($arrOrObj->complexity >= 2) ? BracketPaddingType::Complex : BracketPaddingType::Simple;
    }

    /**
     * Returns a multiline comment string as an array of strings where newlines have been removed
     * and leading space on each line has been trimmed as smartly as possible.
     *
     * @return string[]
     */
    private static function normalizeMultilineComment(string $comment, int $firstLineColumn): array
    {
        $normalized = str_replace("\r", '', $comment);
        $commentRows = array_filter(explode("\n", $normalized), fn($line) => strlen($line) > 0);
        $commentRows = array_values($commentRows);

        for ($i = 1; $i < count($commentRows); $i++) {
            $line = $commentRows[$i];

            $nonWsIdx = 0;
            while ($nonWsIdx < strlen($line) && $nonWsIdx < $firstLineColumn && ctype_space($line[$nonWsIdx])) {
                $nonWsIdx++;
            }

            $commentRows[$i] = substr($line, $nonWsIdx);
        }

        return $commentRows;
    }

    /**
     * @param JsonItem[] $itemList
     */
    private static function indexOfLastElement(array $itemList): int
    {
        for ($i = count($itemList) - 1; $i >= 0; $i--) {
            if (!self::isCommentOrBlankLine($itemList[$i]->type)) {
                return $i;
            }
        }

        return -1;
    }

    private static function isCommentOrBlankLine(JsonItemType $type): bool
    {
        return $type === JsonItemType::BlankLine
            || $type === JsonItemType::BlockComment
            || $type === JsonItemType::LineComment;
    }
}

enum CommaPosition
{
    case BeforeValuePadding;
    case AfterValuePadding;
    case BeforeCommentPadding;
    case AfterCommentPadding;
}
