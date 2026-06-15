<?php

declare(strict_types=1);

namespace App\Cockpit;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Pushes a monitor verdict to an operator channel via an outbound webhook.
 * Channel-agnostic: Slack/Discord/ntfy use their native body shapes; "raw"
 * posts the full structured report. The webhook URL is the only secret and
 * lives in config (HARNESS_MONITOR_WEBHOOK_URL), never in code.
 */
final class MonitorNotifier
{
    private const ICON = [
        MonitorEvaluator::CRITICAL => '🔴',
        MonitorEvaluator::WARN => '🟡',
        MonitorEvaluator::NOMINAL => '🟢',
    ];

    /**
     * @param  array<string,mixed>  $report  MonitorEvaluator::evaluate() output
     * @param  array<string,mixed>  $cfg     harness.monitor config block
     * @return array{sent:bool, reason:string}
     */
    public function send(array $report, array $cfg): array
    {
        $url = (string) ($cfg['webhook_url'] ?? '');
        if ($url === '') {
            return ['sent' => false, 'reason' => 'no webhook_url configured'];
        }

        $format = (string) ($cfg['webhook_format'] ?? 'slack');
        $text = $this->text($report);

        try {
            $response = match ($format) {
                'discord' => Http::asJson()->post($url, ['content' => $text]),
                'ntfy' => Http::withHeaders([
                    'Title' => 'theta monitor — '.($report['severity'] ?? 'nominal'),
                    'Priority' => ($report['severity'] ?? '') === MonitorEvaluator::CRITICAL ? 'urgent' : 'default',
                ])->withBody($text, 'text/plain')->post($url),
                'raw' => Http::asJson()->post($url, ['text' => $text, 'report' => $report]),
                default => Http::asJson()->post($url, ['text' => $text]), // slack
            };
        } catch (Throwable $e) {
            return ['sent' => false, 'reason' => 'webhook error: '.$e->getMessage()];
        }

        return $response->successful()
            ? ['sent' => true, 'reason' => 'ok']
            : ['sent' => false, 'reason' => 'webhook HTTP '.$response->status()];
    }

    /** @param array<string,mixed> $report */
    public function text(array $report): string
    {
        $severity = (string) ($report['severity'] ?? MonitorEvaluator::NOMINAL);
        $icon = self::ICON[$severity] ?? '';

        $lines = [];
        $lines[] = trim($icon.' *theta monitor — '.$severity.'*  ('.($report['summary'] ?? '').')');

        foreach (($report['alerts'] ?? []) as $a) {
            $lines[] = '• '.($a['message'] ?? '');
        }

        if (! empty($report['alerts']) && ! empty($report['lines'])) {
            $lines[] = '—';
        }

        foreach (($report['lines'] ?? []) as $line) {
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }
}
