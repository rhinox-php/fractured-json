<?php

declare(strict_types=1);

namespace Rhinox\FracturedJson\Formatting;

use Rhinox\FracturedJson\Enums\BracketPaddingType;
use Rhinox\FracturedJson\Enums\JsonItemType;
use Rhinox\FracturedJson\Enums\NumberListAlignment;
use Rhinox\FracturedJson\Enums\TableColumnType;
use Rhinox\FracturedJson\Parsing\JsonItem;

/**
 * Collects spacing information about the columns of a potential table.
 * Each TableTemplate corresponds to a part of a row, and they're nested recursively to match the JSON structure.
 * (Also used in complex multiline arrays to try to fit them all nicely together.)
 */
class TableTemplate
{
    private const DOT_OR_E = '/[.eE]/';
    private const TRULY_ZERO_VAL_STRING = '/^-?[0.]+([eE].*)?$/';
    private const MAX_CHARS_FOR_NORMALIZE = 16;

    /**
     * The property name in the table that this segment matches up with.
     */
    public ?string $locationInParent = null;

    /**
     * Type of the column, for table formatting purposes.
     */
    public TableColumnType $type = TableColumnType::Unknown;

    public int $rowCount = 0;

    /**
     * Length of the longest property name.
     */
    public int $nameLength = 0;

    /**
     * Length of the shortest property name.
     */
    public int $nameMinimum = PHP_INT_MAX;

    /**
     * Largest length for the value parts of the column, not counting any table formatting padding.
     */
    public int $maxValueLength = 0;

    /**
     * Length of the largest value that can't be split apart; i.e., values other than arrays and objects.
     */
    public int $maxAtomicValueLength = 0;

    public int $prefixCommentLength = 0;
    public int $middleCommentLength = 0;
    public bool $anyMiddleCommentHasNewline = false;
    public int $postfixCommentLength = 0;
    public bool $isAnyPostCommentLineStyle = false;
    public BracketPaddingType $padType = BracketPaddingType::Simple;
    public bool $requiresMultipleLines = false;

    /**
     * Length of the value for this template when things are complicated.
     */
    public int $compositeValueLength = 0;

    /**
     * Length of the entire template, including space for the value, property name, and all comments.
     */
    public int $totalLength = 0;

    /**
     * If the row contains non-empty array or objects whose value is shorter than the literal null,
     * an extra adjustment is needed.
     */
    public int $shorterThanNullAdjustment = 0;

    /**
     * True if at least one row in the column this represents has a null value.
     */
    public bool $containsNull = false;

    /**
     * If this TableTemplate corresponds to an object or array, Children contains sub-templates
     * for the array/object's children.
     *
     * @var TableTemplate[]
     */
    public array $children = [];

    private PaddedFormattingTokens $pads;
    private NumberListAlignment $numberListAlignment;
    private int $maxDigBeforeDec = 0;
    private int $maxDigAfterDec = 0;

    public function __construct(PaddedFormattingTokens $pads, NumberListAlignment $numberListAlignment)
    {
        $this->pads = $pads;
        $this->numberListAlignment = $numberListAlignment;
    }

    /**
     * Analyzes an object/array for formatting as a table, formatting as a compact multiline array, or
     * formatting as an expanded object with aligned properties.
     */
    public function measureTableRoot(JsonItem $tableRoot, bool $recursive): void
    {
        foreach ($tableRoot->children as $child) {
            $this->measureRowSegment($child, $recursive);
        }
        $this->pruneAndRecompute(PHP_INT_MAX);
    }

    /**
     * Check if the template's width fits in the given size.
     */
    public function tryToFit(int $maximumLength): bool
    {
        $complexity = $this->getTemplateComplexity();
        while (true) {
            if ($this->totalLength <= $maximumLength) {
                return true;
            }
            if ($complexity <= 0) {
                return false;
            }
            $complexity--;
            $this->pruneAndRecompute($complexity);
        }
    }

    /**
     * Added the number, properly aligned and possibly reformatted, according to our measurements.
     */
    public function formatNumber(BufferInterface $buffer, JsonItem $item, string $commaBeforePadType): void
    {
        switch ($this->numberListAlignment) {
            case NumberListAlignment::Left:
                $buffer->add($item->value, $commaBeforePadType)->spaces($this->maxValueLength - $item->valueLength);
                return;
            case NumberListAlignment::Right:
                $buffer->spaces($this->maxValueLength - $item->valueLength)->add($item->value, $commaBeforePadType);
                return;
        }

        if ($item->type === JsonItemType::Null) {
            $buffer->spaces($this->maxDigBeforeDec - $item->valueLength)
                ->add($item->value, $commaBeforePadType)
                ->spaces($this->compositeValueLength - $this->maxDigBeforeDec);
            return;
        }

        // Normalize case - rewrite the number with the appropriate precision
        if ($this->numberListAlignment === NumberListAlignment::Normalize) {
            $parsedVal = (float) $item->value;
            $reformattedStr = number_format($parsedVal, $this->maxDigAfterDec, '.', '');
            $buffer->spaces($this->compositeValueLength - strlen($reformattedStr))
                ->add($reformattedStr, $commaBeforePadType);
            return;
        }

        // Decimal case - line up the decimals (or E's) but leave the value exactly as it was in the source
        if (preg_match(self::DOT_OR_E, $item->value, $matches, PREG_OFFSET_CAPTURE)) {
            $indexOfDot = (int) $matches[0][1];
            $leftPad = $this->maxDigBeforeDec - $indexOfDot;
            $rightPad = $this->compositeValueLength - $leftPad - $item->valueLength;
        } else {
            $leftPad = $this->maxDigBeforeDec - $item->valueLength;
            $rightPad = $this->compositeValueLength - $this->maxDigBeforeDec;
        }

        $buffer->spaces($leftPad)->add($item->value, $commaBeforePadType)->spaces($rightPad);
    }

    /**
     * Length of the largest item that can't be split across multiple lines.
     */
    public function atomicItemSize(): int
    {
        return $this->nameLength
            + $this->pads->getColonLen()
            + $this->middleCommentLength
            + ($this->middleCommentLength > 0 ? $this->pads->getCommentLen() : 0)
            + $this->maxAtomicValueLength
            + $this->postfixCommentLength
            + ($this->postfixCommentLength > 0 ? $this->pads->getCommentLen() : 0)
            + $this->pads->getCommaLen();
    }

    /**
     * Adjusts this TableTemplate (and its children) to make room for the given rowSegment (and its children).
     */
    private function measureRowSegment(JsonItem $rowSegment, bool $recursive): void
    {
        // Standalone comments and blank lines don't figure into template measurements
        if ($rowSegment->type === JsonItemType::BlankLine
            || $rowSegment->type === JsonItemType::BlockComment
            || $rowSegment->type === JsonItemType::LineComment) {
            return;
        }

        $rowTableType = match ($rowSegment->type) {
            JsonItemType::Null => TableColumnType::Unknown,
            JsonItemType::Number => TableColumnType::Number,
            JsonItemType::Array => TableColumnType::Array,
            JsonItemType::Object => TableColumnType::Object,
            default => TableColumnType::Simple,
        };

        if ($this->type === TableColumnType::Unknown) {
            $this->type = $rowTableType;
        } elseif ($rowTableType !== TableColumnType::Unknown && $this->type !== $rowTableType) {
            $this->type = TableColumnType::Mixed;
        }

        if ($rowSegment->type === JsonItemType::Null) {
            $this->maxDigBeforeDec = max($this->maxDigBeforeDec, $this->pads->getLiteralNullLen());
            $this->containsNull = true;
        }

        if ($rowSegment->requiresMultipleLines) {
            $this->requiresMultipleLines = true;
            $this->type = TableColumnType::Mixed;
        }

        $this->rowCount++;
        $this->nameLength = max($this->nameLength, $rowSegment->nameLength);
        $this->nameMinimum = min($this->nameMinimum, $rowSegment->nameLength);
        $this->maxValueLength = max($this->maxValueLength, $rowSegment->valueLength);
        $this->middleCommentLength = max($this->middleCommentLength, $rowSegment->middleCommentLength);
        $this->prefixCommentLength = max($this->prefixCommentLength, $rowSegment->prefixCommentLength);
        $this->postfixCommentLength = max($this->postfixCommentLength, $rowSegment->postfixCommentLength);
        $this->isAnyPostCommentLineStyle = $this->isAnyPostCommentLineStyle || $rowSegment->isPostCommentLineStyle;
        $this->anyMiddleCommentHasNewline = $this->anyMiddleCommentHasNewline || $rowSegment->middleCommentHasNewLine;

        if ($rowSegment->type !== JsonItemType::Array && $rowSegment->type !== JsonItemType::Object) {
            $this->maxAtomicValueLength = max($this->maxAtomicValueLength, $rowSegment->valueLength);
        }

        if ($rowSegment->complexity >= 2) {
            $this->padType = BracketPaddingType::Complex;
        }

        if ($this->requiresMultipleLines || $rowSegment->type === JsonItemType::Null) {
            return;
        }

        if ($this->type === TableColumnType::Array && $recursive) {
            for ($i = 0; $i < count($rowSegment->children); $i++) {
                if (count($this->children) <= $i) {
                    $this->children[] = new TableTemplate($this->pads, $this->numberListAlignment);
                }
                $this->children[$i]->measureRowSegment($rowSegment->children[$i], true);
            }
        } elseif ($this->type === TableColumnType::Object && $recursive) {
            if ($this->containsDuplicateKeys($rowSegment->children)) {
                $this->type = TableColumnType::Simple;
                return;
            }

            foreach ($rowSegment->children as $rowSegChild) {
                $subTemplate = null;
                foreach ($this->children as $tt) {
                    if ($tt->locationInParent === $rowSegChild->name) {
                        $subTemplate = $tt;
                        break;
                    }
                }
                if ($subTemplate === null) {
                    $subTemplate = new TableTemplate($this->pads, $this->numberListAlignment);
                    $subTemplate->locationInParent = $rowSegChild->name;
                    $this->children[] = $subTemplate;
                }
                $subTemplate->measureRowSegment($rowSegChild, true);
            }
        }

        // The rest is only relevant to number columns where we plan to align the decimal points
        $skipDecimalStuff = $this->type !== TableColumnType::Number
            || $this->numberListAlignment === NumberListAlignment::Left
            || $this->numberListAlignment === NumberListAlignment::Right;
        if ($skipDecimalStuff) {
            return;
        }

        $normalizedStr = $rowSegment->value;
        if ($this->numberListAlignment === NumberListAlignment::Normalize) {
            $parsedVal = (float) $normalizedStr;
            $normalizedStr = (string) $parsedVal;

            $canNormalize = !is_nan($parsedVal)
                && $parsedVal !== INF && $parsedVal !== -INF
                && strlen($normalizedStr) <= self::MAX_CHARS_FOR_NORMALIZE
                && strpos($normalizedStr, 'e') === false
                && strpos($normalizedStr, 'E') === false
                && ($parsedVal !== 0.0 || preg_match(self::TRULY_ZERO_VAL_STRING, $rowSegment->value));

            if (!$canNormalize) {
                $this->numberListAlignment = NumberListAlignment::Left;
                return;
            }
        }

        if (preg_match(self::DOT_OR_E, $normalizedStr, $matches, PREG_OFFSET_CAPTURE)) {
            $indexOfDot = (int) $matches[0][1];
            $this->maxDigBeforeDec = max($this->maxDigBeforeDec, $indexOfDot);
            $this->maxDigAfterDec = max($this->maxDigAfterDec, strlen($normalizedStr) - $indexOfDot - 1);
        } else {
            $this->maxDigBeforeDec = max($this->maxDigBeforeDec, strlen($normalizedStr));
        }
    }

    private function pruneAndRecompute(int $maxAllowedComplexity): void
    {
        $clearChildren = $maxAllowedComplexity <= 0
            || ($this->type !== TableColumnType::Array && $this->type !== TableColumnType::Object)
            || $this->rowCount < 2;

        if ($clearChildren) {
            $this->children = [];
        }

        foreach ($this->children as $subTemplate) {
            $subTemplate->pruneAndRecompute($maxAllowedComplexity - 1);
        }

        if ($this->type === TableColumnType::Number) {
            $this->compositeValueLength = $this->getNumberFieldWidth();
        } elseif (count($this->children) > 0) {
            $totalChildLen = array_sum(array_map(fn($ch) => $ch->totalLength, $this->children));
            $this->compositeValueLength = $totalChildLen
                + max(0, $this->pads->getCommaLen() * (count($this->children) - 1))
                + $this->pads->arrStartLen($this->padType)
                + $this->pads->arrEndLen($this->padType);

            if ($this->containsNull && $this->compositeValueLength < $this->pads->getLiteralNullLen()) {
                $this->shorterThanNullAdjustment = $this->pads->getLiteralNullLen() - $this->compositeValueLength;
                $this->compositeValueLength = $this->pads->getLiteralNullLen();
            }
        } else {
            $this->compositeValueLength = $this->maxValueLength;
        }

        $this->totalLength =
            ($this->prefixCommentLength > 0 ? $this->prefixCommentLength + $this->pads->getCommentLen() : 0)
            + ($this->nameLength > 0 ? $this->nameLength + $this->pads->getColonLen() : 0)
            + ($this->middleCommentLength > 0 ? $this->middleCommentLength + $this->pads->getCommentLen() : 0)
            + $this->compositeValueLength
            + ($this->postfixCommentLength > 0 ? $this->postfixCommentLength + $this->pads->getCommentLen() : 0);
    }

    private function getTemplateComplexity(): int
    {
        if (count($this->children) === 0) {
            return 0;
        }
        $childComplexities = array_map(fn($ch) => $ch->getTemplateComplexity(), $this->children);
        return 1 + max($childComplexities);
    }

    /**
     * @param JsonItem[] $list
     */
    private function containsDuplicateKeys(array $list): bool
    {
        $seenNames = [];
        foreach ($list as $item) {
            if (isset($seenNames[$item->name])) {
                return true;
            }
            $seenNames[$item->name] = true;
        }
        return false;
    }

    private function getNumberFieldWidth(): int
    {
        if ($this->numberListAlignment === NumberListAlignment::Normalize
            || $this->numberListAlignment === NumberListAlignment::Decimal) {
            $rawDecLen = $this->maxDigAfterDec > 0 ? 1 : 0;
            return $this->maxDigBeforeDec + $rawDecLen + $this->maxDigAfterDec;
        }

        return $this->maxValueLength;
    }
}
