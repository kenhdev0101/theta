<?php

declare(strict_types=1);

namespace App\Robinhood\Rails;

final class GateResult
{
    private function __construct(
        public readonly string $gate,
        public readonly bool $ok,
        public readonly ?string $reason,
        public readonly array $detail,
    ) {}

    public static function allow(string $gate, array $detail = []): self
    {
        return new self($gate, true, null, $detail);
    }

    public static function block(string $gate, string $reason, array $detail = []): self
    {
        return new self($gate, false, $reason, $detail);
    }

    public function toArray(): array
    {
        return [
            'gate' => $this->gate,
            'ok' => $this->ok,
            'reason' => $this->reason,
            'detail' => $this->detail,
        ];
    }
}
