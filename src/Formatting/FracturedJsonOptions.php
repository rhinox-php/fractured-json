<?php

declare(strict_types=1);

namespace Rhinox\FracturedJson\Formatting;

use Rhinox\FracturedJson\Enums\CommentPolicy;
use Rhinox\FracturedJson\Enums\EolStyle;
use Rhinox\FracturedJson\Enums\NumberListAlignment;
use Rhinox\FracturedJson\Enums\TableCommaPlacement;

/**
 * Settings controlling the output of FracturedJson-formatted JSON documents.
 *
 * Note that the constructor will give defaults that with stable behavior within all releases with the same major
 * version number. If new features are added in a minor version release, you can use the static factory method
 * FracturedJsonOptions::recommended() (instead of new) to get the most up-to-date preferred behavior.
 * This might not be backward compatible, though.
 */
class FracturedJsonOptions
{
    /**
     * Specifies the line break style (e.g., LF or CRLF) for the formatted JSON output.
     */
    public EolStyle $jsonEolStyle = EolStyle::Lf;

    /**
     * Maximum length (in characters, including indentation) when more than one simple value is put on a line.
     * Individual values (e.g., long strings) may exceed this limit.
     */
    public int $maxTotalLineLength = 120;

    /**
     * Maximum nesting level of arrays/objects that may be written on a single line.
     * 0 disables inlining (but see related settings).
     * 1 allows inlining of arrays/objects that contain only simple items.
     * 2 allows inlining of arrays/objects that contain other arrays/objects as long as the child containers only contain simple items.
     * Higher values allow deeper nesting.
     */
    public int $maxInlineComplexity = 2;

    /**
     * Maximum nesting level for arrays formatted with multiple items per row across multiple lines.
     * Set to 0 to disable this format.
     * 1 allows arrays containing only simple values to be formatted this way.
     * 2 allows arrays containing arrays or elements that contain only simple values.
     * Higher values allow deeper nesting.
     */
    public int $maxCompactArrayComplexity = 2;

    /**
     * Maximum nesting level of the rows of an array or object formatted as a table with aligned columns.
     * When set to 0, the rows may only be simple values and there will only be one column.
     * When set to 1, each row can be an array or object containing only simple values.
     * Higher values allow deeper nesting.
     */
    public int $maxTableRowComplexity = 2;

    /**
     * Maximum length difference between property names in an object to align them vertically in expanded (non-table) formatting.
     */
    public int $maxPropNamePadding = 16;

    /**
     * If true, colons in aligned object properties are placed right after the property name (e.g., 'name:    value');
     * if false, colons align vertically after padding (e.g., 'name   : value').
     * Applies to table and expanded formatting.
     */
    public bool $colonBeforePropNamePadding = false;

    /**
     * Determines whether commas in table-formatted rows are lined up in their own column after padding
     * or placed directly after each element, before padding spaces.
     */
    public TableCommaPlacement $tableCommaPlacement = TableCommaPlacement::BeforePaddingExceptNumbers;

    /**
     * Minimum items per row to format an array with multiple items per line across multiple lines.
     * This is a guideline, not a strict rule.
     */
    public int $minCompactArrayRowItems = 3;

    /**
     * Depth at which lists/objects are always fully expanded, regardless of other settings.
     * -1 = none; 0 = root node only; 1 = root node and its children.
     */
    public int $alwaysExpandDepth = -1;

    /**
     * If an inlined array or object contains other arrays or objects, setting NestedBracketPadding to true
     * will include spaces inside the outer brackets.
     */
    public bool $nestedBracketPadding = true;

    /**
     * If an inlined array or object does NOT contain other arrays/objects, setting SimpleBracketPadding to true
     * will include spaces inside the brackets.
     *
     * Example:
     * true: [ [ 1, 2, 3 ], [ 4 ] ]
     * false: [ [1, 2, 3], [4] ]
     */
    public bool $simpleBracketPadding = false;

    /**
     * If true, includes a space after property colons.
     */
    public bool $colonPadding = true;

    /**
     * If true, includes a space after commas separating array items and object properties.
     */
    public bool $commaPadding = true;

    /**
     * If true, spaces are included between JSON data and comments that precede or follow them on the same line.
     */
    public bool $commentPadding = true;

    /**
     * Controls alignment of numbers in table columns or compact multiline arrays.
     * When set to NumberListAlignment::Normalize, numbers are rewritten to have the same decimal precision as others in the same column.
     * Other settings preserve input numbers exactly.
     */
    public NumberListAlignment $numberListAlignment = NumberListAlignment::Decimal;

    /**
     * Number of spaces to use per indent level.
     * If UseTabToIndent is true, spaces won't be used but this number will still be used in length computations.
     */
    public int $indentSpaces = 4;

    /**
     * Uses a single tab per indent level, instead of spaces.
     */
    public bool $useTabToIndent = false;

    /**
     * String attached to the beginning of every line, before regular indentation.
     * If this string contains anything other than whitespace, this will probably make the output invalid JSON,
     * but it might be useful for output to documentation, for instance.
     */
    public string $prefixString = '';

    /**
     * Determines how the parser and formatter should treat comments.
     * The JSON standard does not allow comments, but it's a common unofficial extension.
     * (Such files are often given the extension ".jsonc".)
     */
    public CommentPolicy $commentPolicy = CommentPolicy::TreatAsError;

    /**
     * If true, blank lines in the original input should be preserved in the output.
     */
    public bool $preserveBlankLines = false;

    /**
     * If true, allows a comma after the last element in arrays or objects,
     * which is non-standard JSON but supported by some systems.
     */
    public bool $allowTrailingCommas = false;

    /**
     * Creates a new FracturedJsonOptions with recommended settings, prioritizing sensible defaults
     * over backward compatibility. Constructor defaults maintain consistent behavior across minor versions,
     * while this method may adopt newer, preferred settings.
     */
    public static function recommended(): self
    {
        // At the beginning of version 5, the defaults are the recommended settings.
        // This may change in future minor versions.
        return new self();
    }
}
