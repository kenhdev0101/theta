<?php

declare(strict_types=1);

namespace App\Robinhood\Rails\Gates;

use App\Robinhood\Rails\GateResult;
use App\Robinhood\Rails\OrderIntent;

/**
 * Pre-trade review consumed programmatically. The broker review response
 * must be present in context, and its alerts are parsed deterministically
 * into block/allow — no model interprets venue guidance.
 */
final class ReviewGate implements GateInterface
{
    private const BLOCKING_PATTERNS = [
        'buying power',
        'insufficient',
        'pattern day',
        'good faith',
        'free riding',
        'halt',
        'restricted',
        'not tradable',
        'unavailable',
    ];

    public function name(): string
    {
        return 'review';
    }

    public function evaluate(OrderIntent $intent, array $cfg, array $context): GateResult
    {
        $review = $context['review'] ?? null;
        if (! is_array($review)) {
            return GateResult::block($this->name(), 'review_missing');
        }

        if (($review['ok'] ?? null) === false || ($review['is_error'] ?? null) === true) {
            return GateResult::block($this->name(), 'review_rejected', ['review' => $review]);
        }

        $alerts = $review['alerts'] ?? [];
        foreach ((array) $alerts as $alert) {
            $severity = is_array($alert) ? strtolower((string) ($alert['severity'] ?? '')) : '';
            $text = strtolower(is_array($alert) ? (string) ($alert['message'] ?? json_encode($alert)) : (string) $alert);

            if (in_array($severity, ['error', 'blocker', 'critical'], true)) {
                return GateResult::block($this->name(), 'review_alert_blocking', ['alert' => $alert]);
            }

            foreach (self::BLOCKING_PATTERNS as $pattern) {
                if (str_contains($text, $pattern)) {
                    return GateResult::block($this->name(), 'review_alert_blocking', ['alert' => $alert]);
                }
            }
        }

        return GateResult::allow($this->name(), ['alerts_seen' => count((array) $alerts)]);
    }
}
