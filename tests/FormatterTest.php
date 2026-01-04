<?php

declare(strict_types=1);

namespace Rhinox\FracturedJson\Tests;

use PHPUnit\Framework\TestCase;
use Rhinox\FracturedJson\Enums\CommentPolicy;
use Rhinox\FracturedJson\Formatting\Formatter;
use Rhinox\FracturedJson\Formatting\FracturedJsonOptions;

class FormatterTest extends TestCase
{
    public function testBasicReformat(): void
    {
        $formatter = new Formatter();
        $input = '{"a":1,"b":2}';
        $output = $formatter->reformat($input);

        $this->assertStringContainsString('"a"', $output);
        $this->assertStringContainsString('"b"', $output);
        $this->assertStringContainsString('1', $output);
        $this->assertStringContainsString('2', $output);
    }

    public function testArrayReformat(): void
    {
        $formatter = new Formatter();
        $input = '[1,2,3,4,5]';
        $output = $formatter->reformat($input);

        // Should be valid JSON
        $decoded = json_decode($output);
        $this->assertIsArray($decoded);
        $this->assertEquals([1, 2, 3, 4, 5], $decoded);
    }

    public function testNestedReformat(): void
    {
        $formatter = new Formatter();
        $input = '{"a":{"b":{"c":1}}}';
        $output = $formatter->reformat($input);

        $decoded = json_decode($output);
        $this->assertIsObject($decoded);
        $this->assertEquals(1, $decoded->a->b->c);
    }

    public function testSerialize(): void
    {
        $formatter = new Formatter();
        $data = ['name' => 'Alice', 'scores' => [95, 87, 92]];
        $output = $formatter->serialize($data);

        $this->assertNotNull($output);
        $decoded = json_decode($output, true);
        $this->assertEquals($data, $decoded);
    }

    public function testMinify(): void
    {
        $formatter = new Formatter();
        $input = '{
            "a": 1,
            "b": 2
        }';
        $output = $formatter->minify($input);

        $this->assertStringNotContainsString("\n", $output);
        $this->assertStringNotContainsString(' ', $output);
        $decoded = json_decode($output);
        $this->assertEquals(1, $decoded->a);
        $this->assertEquals(2, $decoded->b);
    }

    public function testWithOptions(): void
    {
        $options = new FracturedJsonOptions();
        $options->maxTotalLineLength = 40;
        $options->maxInlineComplexity = 1;

        $formatter = new Formatter($options);
        $input = '{"a":1,"b":2}';
        $output = $formatter->reformat($input);

        $decoded = json_decode($output);
        $this->assertEquals(1, $decoded->a);
        $this->assertEquals(2, $decoded->b);
    }

    public function testTableFormatting(): void
    {
        $formatter = new Formatter();
        $input = '[
            {"name": "Alice", "age": 30},
            {"name": "Bob", "age": 25}
        ]';
        $output = $formatter->reformat($input);

        $decoded = json_decode($output);
        $this->assertCount(2, $decoded);
        $this->assertEquals('Alice', $decoded[0]->name);
        $this->assertEquals('Bob', $decoded[1]->name);
    }

    public function testCommentsPreserved(): void
    {
        $options = new FracturedJsonOptions();
        $options->commentPolicy = CommentPolicy::Preserve;

        $formatter = new Formatter($options);
        $input = '{"a": 1 /* comment */}';
        $output = $formatter->reformat($input);

        $this->assertStringContainsString('/* comment */', $output);
    }

    /**
     * @dataProvider standardJsonFilesProvider
     */
    public function testStandardJsonFiles(string $filePath): void
    {
        $input = file_get_contents($filePath);
        $this->assertNotFalse($input);

        $formatter = new Formatter();
        $output = $formatter->reformat($input);

        // Output should be valid JSON (use json_last_error since json_decode returns null for "null" literal)
        json_decode($output);
        $this->assertSame(JSON_ERROR_NONE, json_last_error(), "Failed to parse reformatted JSON from: {$filePath}");

        // Reformatting again should give the same result (idempotent)
        $output2 = $formatter->reformat($output);
        $this->assertEquals($output, $output2, "Reformat is not idempotent for: {$filePath}");
    }

    public static function standardJsonFilesProvider(): array
    {
        $files = glob(__DIR__ . '/../conformance/inputs/StandardJsonFiles/*.json');
        $result = [];
        foreach ($files as $file) {
            $result[basename($file)] = [$file];
        }
        return $result;
    }

    /**
     * @dataProvider filesWithCommentsProvider
     */
    public function testFilesWithComments(string $filePath): void
    {
        $input = file_get_contents($filePath);
        $this->assertNotFalse($input);

        $options = new FracturedJsonOptions();
        $options->commentPolicy = CommentPolicy::Preserve;

        $formatter = new Formatter($options);
        $output = $formatter->reformat($input);

        // Reformatting again should give the same result (idempotent)
        $output2 = $formatter->reformat($output);
        $this->assertEquals($output, $output2, "Reformat is not idempotent for: {$filePath}");
    }

    public static function filesWithCommentsProvider(): array
    {
        $files = glob(__DIR__ . '/../conformance/inputs/FilesWithComments/*.jsonc');
        $result = [];
        foreach ($files as $file) {
            $result[basename($file)] = [$file];
        }
        return $result;
    }
}
