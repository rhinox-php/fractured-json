# FracturedJson for PHP

A JSON formatter that produces human-readable output with intelligent line breaks and table-like alignment. It strikes a balance between compact and expanded formats, making large JSON documents much easier to read.

This is a PHP port of [FracturedJsonJs](https://github.com/j-brooke/FracturedJson), maintaining full compatibility with the original TypeScript implementation.

## Features

- **Smart formatting**: Automatically chooses between inline, compact multiline, table, and expanded formats based on content
- **Table alignment**: Arrays of similar objects are formatted as aligned tables
- **Number alignment**: Numbers in columns can be aligned by decimal point
- **Comment preservation**: Supports JSONC (JSON with comments)
- **Configurable**: Extensive options to customize output format
- **CLI tool**: Command-line interface for formatting files or piped input

## Requirements

- PHP 8.4 or higher
- mbstring extension

## Installation

```bash
composer require rhinox/fractured-json
```

## Quick Start

### Library Usage

```php
use Rhinox\FracturedJson\Formatting\Formatter;

$formatter = new Formatter();
$json = '{"name":"Alice","scores":[95,87,92],"active":true}';

echo $formatter->reformat($json);
```

Output:
```json
{ "name": "Alice", "scores": [95, 87, 92], "active": true }
```

### CLI Usage

```bash
# Format a file
vendor/bin/fractured-json data.json

# Format from stdin
echo '{"a":1,"b":2}' | vendor/bin/fractured-json

# Format in place
vendor/bin/fractured-json -i config.json
```

## Formatting Examples

FracturedJson automatically selects the best format based on the data structure and configured options.

### Inline Format

Simple, short content stays on one line:

```json
{ "name": "Alice", "age": 30, "active": true }
```

### Table Format

Arrays of similar objects are aligned as tables:

```json
[
    { "name": "Alice",   "age": 30, "city": "New York" },
    { "name": "Bob",     "age": 25, "city": "Boston"   },
    { "name": "Charlie", "age": 35, "city": "Chicago"  }
]
```

### Compact Multiline Format

Long arrays of simple values are wrapped across multiple lines:

```json
{
    "primes": [
          2,   3,   5,   7,  11,  13,  17,  19,  23,  29,  31,  37,  41,  43,  47,
         53,  59,  61,  67,  71,  73,  79,  83,  89,  97, 101, 103, 107, 109, 113
    ]
}
```

### Expanded Format

Complex nested structures are fully expanded:

```json
{
    "users": [
        {
            "name": "Alice",
            "address": {
                "street": "123 Main St",
                "city": "New York"
            }
        }
    ]
}
```

## Configuration

### Using Options

```php
use Rhinox\FracturedJson\Formatting\Formatter;
use Rhinox\FracturedJson\Formatting\FracturedJsonOptions;
use Rhinox\FracturedJson\Enums\CommentPolicy;

$options = new FracturedJsonOptions();
$options->maxTotalLineLength = 80;
$options->indentSpaces = 2;
$options->commentPolicy = CommentPolicy::Preserve;

$formatter = new Formatter($options);
echo $formatter->reformat($json);
```

### Available Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `jsonEolStyle` | EolStyle | `Lf` | Line ending style (`Lf` or `Crlf`) |
| `maxTotalLineLength` | int | `120` | Maximum line length before wrapping |
| `maxInlineComplexity` | int | `2` | Max nesting depth for inline formatting |
| `maxCompactArrayComplexity` | int | `2` | Max nesting for compact multiline arrays |
| `maxTableRowComplexity` | int | `2` | Max nesting for table row formatting |
| `indentSpaces` | int | `4` | Spaces per indentation level |
| `useTabToIndent` | bool | `false` | Use tabs instead of spaces |
| `nestedBracketPadding` | bool | `true` | Spaces inside brackets for nested content |
| `simpleBracketPadding` | bool | `false` | Spaces inside brackets for simple content |
| `colonPadding` | bool | `true` | Space after colons |
| `commaPadding` | bool | `true` | Space after commas |
| `commentPolicy` | CommentPolicy | `TreatAsError` | How to handle comments |
| `preserveBlankLines` | bool | `false` | Keep blank lines from input |
| `allowTrailingCommas` | bool | `false` | Allow trailing commas in input |
| `numberListAlignment` | NumberListAlignment | `Decimal` | Number alignment in columns |
| `alwaysExpandDepth` | int | `-1` | Depth at which to always expand |

### Comment Policy

```php
use Rhinox\FracturedJson\Enums\CommentPolicy;

// Throw exception if comments found (default)
$options->commentPolicy = CommentPolicy::TreatAsError;

// Remove comments from output
$options->commentPolicy = CommentPolicy::Remove;

// Preserve comments in output
$options->commentPolicy = CommentPolicy::Preserve;
```

### Number Alignment

```php
use Rhinox\FracturedJson\Enums\NumberListAlignment;

// Left align numbers
$options->numberListAlignment = NumberListAlignment::Left;

// Right align numbers
$options->numberListAlignment = NumberListAlignment::Right;

// Align on decimal point (default)
$options->numberListAlignment = NumberListAlignment::Decimal;

// Normalize precision and align
$options->numberListAlignment = NumberListAlignment::Normalize;
```

## API Reference

### Formatter Class

#### `reformat(string $jsonText, int $startingDepth = 0): string`

Reads JSON text and returns a formatted string.

```php
$formatter = new Formatter();
$output = $formatter->reformat('{"a":1,"b":2}');
```

#### `serialize(mixed $element, int $startingDepth = 0, int $recursionLimit = 100): ?string`

Serializes a PHP value to formatted JSON.

```php
$formatter = new Formatter();
$output = $formatter->serialize(['name' => 'Alice', 'age' => 30]);
```

#### `minify(string $jsonText): string`

Returns minified JSON with all unnecessary whitespace removed.

```php
$formatter = new Formatter();
$output = $formatter->minify('{ "a": 1, "b": 2 }');
// Output: {"a":1,"b":2}
```

### Custom String Length Function

For special alignment needs (e.g., East Asian character width), you can provide a custom string length function:

```php
$formatter = new Formatter();
$formatter->stringLengthFunc = fn(string $s) => mb_strwidth($s, 'UTF-8');
```

## CLI Reference

```
Usage:
  fractured-json [options] [files...]
  cat file.json | fractured-json [options]

Options:
  -h, --help                 Show help message
  -v, --version              Show version
  -i, --in-place             Modify files in place
  -m, --minify               Minify instead of format
  -c, --comments             Preserve comments (JSONC)

Formatting Options:
  --indent <n>               Spaces per indent level (default: 4)
  --tabs                     Use tabs for indentation
  --max-line-length <n>      Maximum line length (default: 120)
  --max-inline-complexity <n> Max nesting for inline arrays/objects
  --expand-depth <n>         Depth at which to always expand
  --crlf                     Use CRLF line endings
  --no-bracket-padding       No spaces inside brackets
  --simple-bracket-padding   Add spaces inside simple brackets
  --number-align <mode>      left, right, decimal, or normalize
  --preserve-blank-lines     Keep blank lines from input
  --trailing-commas          Allow trailing commas in input
```

### CLI Examples

```bash
# Format and print to stdout
vendor/bin/fractured-json data.json

# Format multiple files in place
vendor/bin/fractured-json -i *.json

# Format with 2-space indents and 80 char lines
vendor/bin/fractured-json --indent 2 --max-line-length 80 data.json

# Format JSONC file preserving comments
vendor/bin/fractured-json -c config.jsonc

# Minify
vendor/bin/fractured-json -m large-file.json

# Pipe from another command
curl -s https://api.example.com/data | vendor/bin/fractured-json
```

## Error Handling

The formatter throws `FracturedJsonException` for invalid input:

```php
use Rhinox\FracturedJson\Formatting\Formatter;
use Rhinox\FracturedJson\Exceptions\FracturedJsonException;

$formatter = new Formatter();

try {
    $output = $formatter->reformat('invalid json');
} catch (FracturedJsonException $e) {
    echo "Error: " . $e->getMessage();
}
```

## License

MIT License. See [LICENSE](LICENSE) for details.

## Credits

This is a PHP port of [FracturedJson](https://github.com/j-brooke/FracturedJson) by J-Brooke.
