<?php

declare(strict_types=1);

namespace App\Robinhood\Thesis;

use App\Models\ThesisIntent;

/**
 * A thesis spec — the firm's durable-position intent, encoded as JSON.
 *
 * Today a thesis is a relational `thesis_intents` row authored one CLI flag at
 * a time (options only). This value object is the asset-class-agnostic JSON
 * front end: one declarative document that precommits what to hold, how to
 * size it, how to enter, and — mandatory — how the thesis is invalidated, for
 * one or more legs that may mix equity and option. The equity legs are the
 * live-now expression (fractional/notional sizing scales to any balance,
 * commission-free); the option legs reuse the existing single-leg machinery.
 *
 * Parsing is deliberately lenient and never throws on a missing or malformed
 * field — coherence is {@see ThesisSpecValidator}'s job, which reports every
 * problem at once as named blockers (the {@see ThesisIntent}
 * activation-blocker idiom). Nothing here touches the broker or the database.
 */
final class ThesisSpec
{
    /** @var list<string> Spec schema versions this build understands. */
    public const SUPPORTED_SPEC_VERSIONS = ['1'];

    /**
     * @param  list<ThesisSpecLeg>  $legs
     * @param  array<string,mixed>  $raw
     */
    public function __construct(
        private readonly string $specVersion,
        private readonly string $thesisId,
        private readonly ?string $owner,
        private readonly ?string $agentId,
        private readonly string $thesis,
        private readonly ?string $catalyst,
        private readonly array $legs,
        private readonly array $raw,
    ) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $legs = [];
        foreach ((array) ($data['legs'] ?? []) as $leg) {
            if (is_array($leg)) {
                $legs[] = ThesisSpecLeg::fromArray($leg);
            }
        }

        $trimOrNull = static function (mixed $value): ?string {
            $value = trim((string) $value);

            return $value !== '' ? $value : null;
        };

        return new self(
            specVersion: trim((string) ($data['spec_version'] ?? '')),
            thesisId: trim((string) ($data['thesis_id'] ?? '')),
            owner: $trimOrNull($data['owner'] ?? ''),
            agentId: $trimOrNull($data['agent_id'] ?? ''),
            thesis: trim((string) ($data['thesis'] ?? '')),
            catalyst: $trimOrNull($data['catalyst'] ?? ''),
            legs: $legs,
            raw: $data,
        );
    }

    /**
     * Decode a JSON document into a spec. Throws only on JSON that is not even
     * parseable (an authoring typo the operator must see verbatim); a
     * well-formed-but-incoherent spec parses cleanly and is caught by the
     * validator with named blockers.
     *
     * @throws \JsonException
     */
    public static function fromJson(string $json): self
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new \JsonException('thesis spec must be a JSON object');
        }

        /** @var array<string,mixed> $decoded */
        return self::fromArray($decoded);
    }

    public function specVersion(): string
    {
        return $this->specVersion;
    }

    public function thesisId(): string
    {
        return $this->thesisId;
    }

    public function owner(): ?string
    {
        return $this->owner;
    }

    public function agentId(): ?string
    {
        return $this->agentId;
    }

    public function thesis(): string
    {
        return $this->thesis;
    }

    public function catalyst(): ?string
    {
        return $this->catalyst;
    }

    /** @return list<ThesisSpecLeg> */
    public function legs(): array
    {
        return $this->legs;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
