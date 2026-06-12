<?php

declare(strict_types=1);

namespace App\Robinhood\Options;

final class OptionGateResult
{
    private function __construct(
        public readonly string $gate,
        public readonly bool $ok,
        public readonly ?string $category,
        public readonly ?string $reason,
        public readonly array $detail,
    ) {}

    public static function allow(string $gate, array $detail = []): self
    {
        return new self($gate, true, null, null, $detail);
    }

    public static function block(string $gate, string $category, string $reason, array $detail = []): self
    {
        return new self($gate, false, $category, $reason, $detail);
    }

    public function toArray(): array
    {
        return [
            'gate' => $this->gate,
            'ok' => $this->ok,
            'category' => $this->category,
            'reason' => $this->reason,
            'detail' => $this->detail,
        ];
    }
}
