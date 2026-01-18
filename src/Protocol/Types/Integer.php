<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Protocol\Types;

final readonly class Integer implements RespType
{
    public function __construct(
        private int $value,
    ) {}

    public function getValue(): int
    {
        return $this->value;
    }

    public function getType(): string
    {
        return 'integer';
    }
}
