<?php

declare(strict_types=1);

namespace Rhinox\FracturedJson\Parsing;

use Rhinox\FracturedJson\Enums\JsonItemType;
use Rhinox\FracturedJson\Scanning\InputPosition;

/**
 * A distinct thing that can be where ever JSON values are expected in a JSON-with-comments doc.
 * This could be an actual data value, such as a string, number, array, etc. (generally referred to here as "elements"),
 * or it could be a blank line or standalone comment.
 * In some cases, comments won't be standalone JsonItems, but will instead be attached to elements to which they seem to belong.
 *
 * Much of this data is produced by the Parser, but some of the properties - like all the
 * length ones - are not set by Parser, but rather, provided for use by Formatter.
 */
class JsonItem
{
    public JsonItemType $type = JsonItemType::Null;

    /**
     * Line number from the input - if available - where this element began.
     */
    public InputPosition $inputPosition;

    /**
     * Nesting level of this item's contents if any.
     * A simple item, or an empty array or object, has a complexity of zero.
     * Non-empty arrays/objects have a complexity 1 greater than that of their child with the greatest complexity.
     */
    public int $complexity = 0;

    /**
     * Property name, if this is an element (real JSON value) that is contained in an object.
     */
    public string $name = '';

    /**
     * The text value of this item, non-recursively. Null for objects and arrays.
     */
    public string $value = '';

    /**
     * Comment that belongs in front of this element on the same line, if any.
     */
    public string $prefixComment = '';

    /**
     * Comment (or, possibly many of them) that belongs in between the property name and value, if any.
     */
    public string $middleComment = '';

    /**
     * True if there's a line-style middle comment or a block style one with a newline in it.
     */
    public bool $middleCommentHasNewLine = false;

    /**
     * Comment that belongs after this element on the same line, if any.
     */
    public string $postfixComment = '';

    /**
     * True if the postfix comment is to-end-of-line rather than block style.
     */
    public bool $isPostCommentLineStyle = false;

    public int $nameLength = 0;

    /**
     * String length of the value part. If it's an array or object, it's the sum of the children, with padding.
     */
    public int $valueLength = 0;
    public int $prefixCommentLength = 0;
    public int $middleCommentLength = 0;
    public int $postfixCommentLength = 0;

    /**
     * The smallest possible size this item - including all comments and children if appropriate - can be written.
     */
    public int $minimumTotalLength = 0;

    /**
     * True if this item can't be written on a single line.
     * For example, an item ending in a postfix line comment (like //) can often be written on a single line,
     * because the comment is the last thing. But if it's a container with such an item inside it,
     * it's impossible to inline the container, because there's no way to write the line comment and then a closing bracket.
     */
    public bool $requiresMultipleLines = false;

    /**
     * List of this item's contents, if it's an array or object.
     *
     * @var JsonItem[]
     */
    public array $children = [];

    public function __construct()
    {
        $this->inputPosition = new InputPosition(0, 0, 0);
    }
}
