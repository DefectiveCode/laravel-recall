<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Protocol\Types;

final readonly class Error implements RespType
{
    private string $prefix;

    private string $message;

    public function __construct(string $value)
    {
        $parts = explode(' ', $value, 2);
        $this->prefix = $parts[0];
        $this->message = $parts[1] ?? '';
    }

    public function getValue(): string
    {
        return $this->prefix.($this->message !== '' ? ' '.$this->message : '');
    }

    public function getType(): string
    {
        return 'error';
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function getMessage(): string
    {
        return $this->message;
    }
}
