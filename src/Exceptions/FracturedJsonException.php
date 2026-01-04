<?php

declare(strict_types=1);

namespace Rhinox\FracturedJson\Exceptions;

use Rhinox\FracturedJson\Scanning\InputPosition;

class FracturedJsonException extends \Exception
{
    public function __construct(
        string $message = '',
        public readonly ?InputPosition $inputPosition = null,
        ?\Throwable $previous = null,
    ) {
        $msgWithPos = $inputPosition !== null
            ? sprintf('%s at idx=%d, row=%d, col=%d', $message, $inputPosition->index, $inputPosition->row, $inputPosition->column)
            : $message;

        parent::__construct($msgWithPos, 0, $previous);
    }
}
