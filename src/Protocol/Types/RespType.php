<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Protocol\Types;

interface RespType
{
    public function getValue(): mixed;

    public function getType(): string;
}
