<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Protocol\Types;

final readonly class Push implements RespType
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
        return 'push';
    }

    public function count(): int
    {
        return count($this->elements);
    }

    public function get(int $index): ?RespType
    {
        return $this->elements[$index] ?? null;
    }

    public function getKind(): ?string
    {
        $first = $this->elements[0] ?? null;

        if ($first instanceof SimpleString || $first instanceof BulkString) {
            return $first->getValue();
        }

        return null;
    }
}
