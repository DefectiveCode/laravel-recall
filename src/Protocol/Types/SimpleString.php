<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Protocol\Types;

final readonly class SimpleString implements RespType
{
    public function __construct(
        private string $value,
    ) {}

    public function getValue(): string
    {
        return $this->value;
    }

    public function getType(): string
    {
        return 'simple_string';
    }
}
