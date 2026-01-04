<?php

declare(strict_types=1);

namespace Rhinox\FracturedJson\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Rhinox\FracturedJson\Enums\CommentPolicy;
use Rhinox\FracturedJson\Enums\EolStyle;
use Rhinox\FracturedJson\Enums\NumberListAlignment;
use Rhinox\FracturedJson\Enums\TableCommaPlacement;
use Rhinox\FracturedJson\Formatting\Formatter;
use Rhinox\FracturedJson\Formatting\FracturedJsonOptions;

/**
 * Language-independent conformance tests.
 * These tests use input/output/options files generated from the TypeScript implementation
 * to ensure PHP produces identical output.
 */
class ConformanceTest extends TestCase
{
    private const TEST_DATA_DIR = __DIR__ . '/../conformance/js-test-data';

    #[DataProvider('conformanceTestCasesProvider')]
    public function testConformance(string $hash): void
    {
        $inputFile = self::TEST_DATA_DIR . "/{$hash}.input";
        $optionsFile = self::TEST_DATA_DIR . "/{$hash}.options";
        $outputFile = self::TEST_DATA_DIR . "/{$hash}.output";

        $input = file_get_contents($inputFile);
        $expectedOutput = file_get_contents($outputFile);
        $optionsJson = file_get_contents($optionsFile);

        $this->assertNotFalse($input, "Failed to read input file: {$inputFile}");
        $this->assertNotFalse($expectedOutput, "Failed to read output file: {$outputFile}");
        $this->assertNotFalse($optionsJson, "Failed to read options file: {$optionsFile}");

        $options = $this->parseOptions($optionsJson);
        $formatter = new Formatter($options);

        $actualOutput = $formatter->reformat($input);

        $this->assertSame(
            $expectedOutput,
            $actualOutput,
            "Output mismatch for test case: {$hash}"
        );
    }

    public static function conformanceTestCasesProvider(): array
    {
        $inputFiles = glob(self::TEST_DATA_DIR . '/*.input');
        $result = [];

        foreach ($inputFiles as $inputFile) {
            $hash = basename($inputFile, '.input');
            $result[$hash] = [$hash];
        }

        return $result;
    }

    private function parseOptions(string $optionsJson): FracturedJsonOptions
    {
        $data = json_decode($optionsJson, true);
        $options = new FracturedJsonOptions();

        // Map TypeScript property names (PascalCase) to PHP property names (camelCase)
        // and handle enum conversions

        if (isset($data['JsonEolStyle'])) {
            $options->jsonEolStyle = EolStyle::from($data['JsonEolStyle']);
        }

        if (isset($data['MaxTotalLineLength'])) {
            $options->maxTotalLineLength = $this->toInt($data['MaxTotalLineLength']);
        }

        if (isset($data['MaxInlineComplexity'])) {
            $options->maxInlineComplexity = $this->toInt($data['MaxInlineComplexity']);
        }

        if (isset($data['MaxCompactArrayComplexity'])) {
            $options->maxCompactArrayComplexity = $this->toInt($data['MaxCompactArrayComplexity']);
        }

        if (isset($data['MaxTableRowComplexity'])) {
            $options->maxTableRowComplexity = $this->toInt($data['MaxTableRowComplexity']);
        }

        if (isset($data['MaxPropNamePadding'])) {
            $options->maxPropNamePadding = $this->toInt($data['MaxPropNamePadding']);
        }

        if (isset($data['ColonBeforePropNamePadding'])) {
            $options->colonBeforePropNamePadding = $data['ColonBeforePropNamePadding'];
        }

        if (isset($data['TableCommaPlacement'])) {
            $options->tableCommaPlacement = TableCommaPlacement::from($data['TableCommaPlacement']);
        }

        if (isset($data['MinCompactArrayRowItems'])) {
            $options->minCompactArrayRowItems = $this->toInt($data['MinCompactArrayRowItems']);
        }

        if (isset($data['AlwaysExpandDepth'])) {
            $options->alwaysExpandDepth = $this->toInt($data['AlwaysExpandDepth']);
        }

        if (isset($data['NestedBracketPadding'])) {
            $options->nestedBracketPadding = $data['NestedBracketPadding'];
        }

        if (isset($data['SimpleBracketPadding'])) {
            $options->simpleBracketPadding = $data['SimpleBracketPadding'];
        }

        if (isset($data['ColonPadding'])) {
            $options->colonPadding = $data['ColonPadding'];
        }

        if (isset($data['CommaPadding'])) {
            $options->commaPadding = $data['CommaPadding'];
        }

        if (isset($data['CommentPadding'])) {
            $options->commentPadding = $data['CommentPadding'];
        }

        if (isset($data['NumberListAlignment'])) {
            $options->numberListAlignment = NumberListAlignment::from($data['NumberListAlignment']);
        }

        if (isset($data['IndentSpaces'])) {
            $options->indentSpaces = $this->toInt($data['IndentSpaces']);
        }

        if (isset($data['UseTabToIndent'])) {
            $options->useTabToIndent = $data['UseTabToIndent'];
        }

        if (isset($data['PrefixString'])) {
            $options->prefixString = $data['PrefixString'];
        }

        if (isset($data['CommentPolicy'])) {
            $options->commentPolicy = CommentPolicy::from($data['CommentPolicy']);
        }

        if (isset($data['PreserveBlankLines'])) {
            $options->preserveBlankLines = $data['PreserveBlankLines'];
        }

        if (isset($data['AllowTrailingCommas'])) {
            $options->allowTrailingCommas = $data['AllowTrailingCommas'];
        }

        return $options;
    }

    /**
     * Convert a numeric value to int, handling large floats like 1.7976931348623157e+308
     * which represent "infinity" in the TypeScript tests.
     */
    private function toInt(int|float $value): int
    {
        if (is_float($value)) {
            if ($value >= PHP_INT_MAX) {
                return PHP_INT_MAX;
            }
            if ($value <= PHP_INT_MIN) {
                return PHP_INT_MIN;
            }
            return (int) $value;
        }
        return $value;
    }
}
