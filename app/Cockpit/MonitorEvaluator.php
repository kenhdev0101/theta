<?php

declare(strict_types=1);

namespace App\Cockpit;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Turns a cockpit payload into a monitoring verdict: a severity, a one-line
 * summary, a list of alerts (things needing attention), and a digest of the
 * current state. Pure — payload in, verdict out — so the anomaly rules are
 * unit-testable without a broker or a schedule.
 *
 * Market-aware: "zero activity" only fires while the session is open, so the
 * pre-market grant-safety run never false-alarms.
 */
final class MonitorEvaluator
{
    public const NOMINAL = 'nominal';

    public const WARN = 'warn';

    public const CRITICAL = 'critical';

    /**
     * @param  array<string,mixed>  $payload  CockpitData::payload()
     * @param  array<string,mixed>  $opts     harness.monitor config block
     * @return array{severity:string, ok:bool, summary:string, alerts:list<array{severity:string,message:string}>, lines:list<string>}
     */
    public static function evaluate(array $payload, array $opts = []): array
    {
        $sb = $payload['status_bar'] ?? [];
        $alerts = [];

        $grantWarnHours = (int) ($opts['grant_warn_hours'] ?? 24);
        $staleSeconds = (int) ($opts['snapshot_stale_seconds'] ?? 180);

        // ── OAuth grant ─────────────────────────────────────────────────
        $oauth = $sb['oauth'] ?? [];
        $oauthLabel = (string) ($oauth['label'] ?? '');
        if (str_starts_with($oauthLabel, 'Expired') || $oauthLabel === 'No grant') {
            $alerts[] = self::alert(self::CRITICAL, "OAuth grant: {$oauthLabel} — broker is unreachable");
        } else {
            $hoursLeft = self::hoursUntil($oauth['expires_at'] ?? null);
            if ($hoursLeft !== null && $hoursLeft <= $grantWarnHours) {
                $sev = $hoursLeft <= 2 ? self::CRITICAL : self::WARN;
                $alerts[] = self::alert($sev, sprintf('OAuth grant expires in ~%dh — re-mint before it lapses', (int) round($hoursLeft)));
            } elseif ($oauthLabel === 'Env token') {
                $alerts[] = self::alert(self::WARN, 'OAuth using env bootstrap fallback (no stored grant)');
            }
        }

        // ── Snapshot health ─────────────────────────────────────────────
        $snapErrors = (array) ($payload['snapshot_errors'] ?? []);
        if ($snapErrors !== []) {
            $alerts[] = self::alert(self::WARN, 'Broker snapshot read failures: '.implode(', ', array_keys($snapErrors)));
        }
        $snap = $sb['snapshot'] ?? [];
        $snapAge = $snap['age'] ?? null;
        if (is_numeric($snapAge) && (int) $snapAge > $staleSeconds) {
            $alerts[] = self::alert(self::WARN, "Broker snapshot stale ({$snapAge}s old) — snapshot job may be stalled");
        }

        // ── Kill switch ─────────────────────────────────────────────────
        if ((string) ($sb['kill_switch']['value'] ?? '') === 'engaged') {
            $alerts[] = self::alert(self::WARN, 'Kill switch ENGAGED — placement is blocked');
        }

        // ── Reconciliation conflicts ────────────────────────────────────
        $conflicts = (int) ($payload['reconciliation']['conflicts'] ?? 0);
        if ($conflicts > 0) {
            $alerts[] = self::alert(self::CRITICAL, "{$conflicts} label conflict(s) — a row claims filled but broker truth disagrees");
        }

        // ── MCP call errors ─────────────────────────────────────────────
        $health = (array) ($payload['mcp_health'] ?? []);
        $errors = (int) ($health['errors'] ?? 0);
        $total = (int) ($health['total'] ?? 0);
        if ($errors > 5 && $total > 0 && ($errors / $total) >= 0.25) {
            $alerts[] = self::alert(self::WARN, "Broker MCP errors elevated: {$errors}/{$total} calls failed today");
        }

        // ── Market-aware activity ───────────────────────────────────────
        $marketOpen = (string) ($sb['market']['label'] ?? '') === 'Open';
        $score = (array) ($payload['scorecard'] ?? []);
        $activity = array_sum(array_map('intval', $score));
        if ($marketOpen && $activity === 0) {
            $alerts[] = self::alert(self::WARN, 'Market open but zero producer activity today — the loop may not be running');
        }

        $severity = self::NOMINAL;
        foreach ($alerts as $a) {
            if ($a['severity'] === self::CRITICAL) {
                $severity = self::CRITICAL;
                break;
            }
            $severity = self::WARN;
        }

        return [
            'severity' => $severity,
            'ok' => $alerts === [],
            'summary' => $alerts === [] ? 'all nominal' : count($alerts).' item(s) need attention',
            'alerts' => $alerts,
            'lines' => self::digest($payload),
        ];
    }

    /** @param array<string,mixed> $payload @return list<string> */
    private static function digest(array $payload): array
    {
        $sb = $payload['status_bar'] ?? [];
        $score = (array) ($payload['scorecard'] ?? []);
        $pnl = (array) ($payload['account']['pnl'] ?? []);
        $open = (array) ($payload['account']['open_positions'] ?? []);

        $scoreStr = sprintf(
            'cand %d · reviewed %d · placed %d · filled %d · blocked %d · rejected %d',
            $score['candidates'] ?? 0, $score['reviewed'] ?? 0, $score['placed'] ?? 0,
            $score['filled'] ?? 0, $score['blocked'] ?? 0, $score['rejected'] ?? 0,
        );

        $why = $payload['why_not_trading'] ?? [];
        $topBlocks = implode(', ', array_map(
            fn ($w) => ($w['reason']['label'] ?? '?').' ×'.($w['count'] ?? 0),
            array_slice($why, 0, 3),
        ));

        return array_values(array_filter([
            'Market: '.($sb['market']['label'] ?? '?').'  |  Mode: '.($sb['mode']['label'] ?? '?').'  |  Kill: '.($sb['kill_switch']['label'] ?? '?'),
            'OAuth: '.($sb['oauth']['label'] ?? '?').'  |  Snapshot: '.($sb['snapshot']['label'] ?? '?').'  |  Credits: '.($sb['broker_credits']['label'] ?? '?'),
            'Scorecard: '.$scoreStr,
            'Positions: '.($open['option'] ?? 0).' opt / '.($open['equity'] ?? 0).' eq  |  Working orders: '.count($payload['open_orders'] ?? []),
            'P&L today: realized '.self::money($pnl['realized_today'] ?? null).' · unrealized '.self::money($pnl['unrealized_open'] ?? null),
            $topBlocks !== '' ? 'Top blocks: '.$topBlocks : null,
        ]));
    }

    private static function money(mixed $v): string
    {
        if ($v === null) {
            return 'n/a';
        }

        return ($v < 0 ? '-$' : '$').number_format(abs((float) $v), 2);
    }

    private static function hoursUntil(?string $iso): ?float
    {
        if (! is_string($iso) || $iso === '') {
            return null;
        }
        try {
            return now()->diffInSeconds(Carbon::parse($iso), false) / 3600;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{severity:string,message:string} */
    private static function alert(string $severity, string $message): array
    {
        return ['severity' => $severity, 'message' => $message];
    }
}
