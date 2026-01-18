<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Protocol\Types;

final readonly class RespNull implements RespType
{
    public function getValue(): null
    {
        return null;
    }

    public function getType(): string
    {
        return 'null';
    }
}
