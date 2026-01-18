<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Protocol\Types;

final readonly class RespArray implements RespType
{
    /**
     * @param  array<RespType>  $elements
     */
    public function __construct(
        private array $elements,
    ) {}

    /**
     * @return array<RespType>
     */
    public function getValue(): array
    {
        return $this->elements;
    }

    /**
     * @return array<RespType>
     */
    public function getElements(): array
    {
        return $this->elements;
    }

    public function getType(): string
    {
        return 'array';
    }

    public function count(): int
    {
        return count($this->elements);
    }

    public function get(int $index): ?RespType
    {
        return $this->elements[$index] ?? null;
    }
}
