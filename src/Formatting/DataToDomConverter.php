<?php

declare(strict_types=1);

namespace Rhinox\FracturedJson\Formatting;

use Rhinox\FracturedJson\Enums\JsonItemType;
use Rhinox\FracturedJson\Exceptions\FracturedJsonException;
use Rhinox\FracturedJson\Parsing\JsonItem;

/**
 * Converts from PHP data (arrays, objects, etc) to FracturedJson's DOM, to allow it to be formatted.
 */
class DataToDomConverter
{
    public static function convert(mixed $element, ?string $propName = null, int $recursionLimit = 100): ?JsonItem
    {
        if ($recursionLimit <= 0) {
            throw new FracturedJsonException('Depth limit exceeded - possible circular reference');
        }

        // Handle closures and resources
        if (is_resource($element) || $element instanceof \Closure) {
            return null;
        }

        // If it's an object with a jsonSerialize method (like JsonSerializable), use it
        if (is_object($element) && method_exists($element, 'jsonSerialize')) {
            $convertedElement = json_decode(json_encode($element), true);
            return self::convert($convertedElement, $propName, $recursionLimit - 1);
        }

        $item = new JsonItem();
        $item->name = $propName !== null ? json_encode($propName) : '';

        if ($element === null) {
            $item->type = JsonItemType::Null;
            $item->value = 'null';
        } elseif (is_array($element)) {
            // Check if it's an associative array (object) or sequential array
            if (self::isAssociativeArray($element)) {
                // Treat as object
                $item->type = JsonItemType::Object;
                foreach ($element as $key => $value) {
                    $childItem = self::convert($value, (string) $key, $recursionLimit - 1);
                    if ($childItem !== null) {
                        $item->children[] = $childItem;
                    }
                }
            } else {
                // Treat as array
                $item->type = JsonItemType::Array;
                $count = count($element);
                for ($i = 0; $i < $count; $i++) {
                    $childItem = self::convert($element[$i] ?? null, null, $recursionLimit - 1)
                        ?? self::convert(null, null, $recursionLimit - 1);
                    $item->children[] = $childItem;
                }
            }
        } elseif (is_object($element)) {
            // Convert object to array of properties
            $item->type = JsonItemType::Object;
            foreach (get_object_vars($element) as $key => $value) {
                $childItem = self::convert($value, $key, $recursionLimit - 1);
                if ($childItem !== null) {
                    $item->children[] = $childItem;
                }
            }
        } elseif (is_string($element)) {
            $item->type = JsonItemType::String;
            $item->value = json_encode($element);
        } elseif (is_int($element) || is_float($element)) {
            $item->type = JsonItemType::Number;
            if (is_nan($element) || is_infinite($element)) {
                $item->type = JsonItemType::Null;
                $item->value = 'null';
            } else {
                $item->value = json_encode($element);
            }
        } elseif (is_bool($element)) {
            $item->type = $element ? JsonItemType::True : JsonItemType::False;
            $item->value = $element ? 'true' : 'false';
        } else {
            return null;
        }

        if (count($item->children) > 0) {
            $highestChildComplexity = max(array_map(fn($ch) => $ch->complexity, $item->children));
            $item->complexity = $highestChildComplexity + 1;
        }

        return $item;
    }

    private static function isAssociativeArray(array $arr): bool
    {
        if (empty($arr)) {
            return false;
        }
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}
