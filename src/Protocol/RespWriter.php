<?php

declare(strict_types=1);

namespace DefectiveCode\Recall\Protocol;

class RespWriter
{
    /**
     * @param  array<string|int>  $args
     */
    public function command(string $command, array $args = []): string
    {
        $parts = array_merge([$command], $args);
        $count = count($parts);

        $output = "*{$count}\r\n";

        foreach ($parts as $part) {
            $part = (string) $part;
            $length = strlen($part);
            $output .= "\${$length}\r\n{$part}\r\n";
        }

        return $output;
    }

    public function auth(string $password, ?string $username = null): string
    {
        if ($username !== null) {
            return $this->command('AUTH', [$username, $password]);
        }

        return $this->command('AUTH', [$password]);
    }

    public function select(int $database): string
    {
        return $this->command('SELECT', [$database]);
    }

    public function clientId(): string
    {
        return $this->command('CLIENT', ['ID']);
    }

    public function subscribe(string ...$channels): string
    {
        return $this->command('SUBSCRIBE', $channels);
    }
}
