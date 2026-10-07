<?php

declare(strict_types=1);

namespace App\Robinhood\Convex;

use App\Robinhood\Thesis\ThesisSpecLeg;
use DateTimeImmutable;
use Illuminate\Support\Carbon;
use RuntimeException;

/** Verifies the immutable entry evidence against the emitted feature matrix. */
final class ConvexMatrixAdmission
{
    public static function currentBlocker(array $source, string $ticker, int $dte, string $matrixPath): ?string
    {
        $hash = (string) ($source['row_sha256'] ?? '');
        if (! preg_match('/^[a-f0-9]{64}$/', $hash)) {
            return 'matrix_admit_invalid_before_place';
        }
        $current = (new ConvexMatrixSnapshot($matrixPath))->latest()[ConvexMatrixSnapshot::key($ticker, $dte)] ?? null;
        if ($current === null || $current['hash'] !== $hash || ($current['row']['verdict'] ?? '') !== 'ADMIT'
            || ($current['row']['failed_signals'] ?? '') !== '' || ($current['row']['pending_signals'] ?? '') !== '') {
            return 'matrix_admit_superseded_before_place';
        }

        return null;
    }

    /** @param array<string,string> $row */
    public static function contractBlocker(array $row, array $contract, array $quote): ?string
    {
        $dte = (int) Carbon::now('America/New_York')->startOfDay()
            ->diffInDays(Carbon::parse((string) ($contract['expiration_date'] ?? ''), 'America/New_York')->startOfDay(), false);
        if ($dte !== (int) ($row['dte'] ?? -1)) {
            return 'matrix_contract_tenor_mismatch';
        }
        if (! is_numeric($quote['delta'] ?? null) || ! is_numeric($contract['strike_price'] ?? null)
            || abs(abs((float) $quote['delta']) - abs((float) $row['far_delta'])) > 0.075
            || abs((float) $contract['strike_price'] - (float) $row['far_strike']) > (float) $row['spot'] * 0.02) {
            return 'matrix_contract_wing_mismatch';
        }

        return null;
    }

    /** @return array<string,string> */
    public static function verify(array $source, ThesisSpecLeg $leg, string $matrixPath): array
    {
        if ($leg->optionStructure() !== ThesisSpecLeg::STRUCTURE_LONG_CALL) {
            throw new RuntimeException('matrix far call evidence cannot admit a put probe');
        }
        $hash = (string) ($source['row_sha256'] ?? '');
        if (! preg_match('/^[a-f0-9]{64}$/', $hash) || ! is_file($matrixPath)) {
            throw new RuntimeException('matrix ADMIT evidence missing or unavailable');
        }
        $handle = fopen($matrixPath, 'r');
        if ($handle === false) {
            throw new RuntimeException('matrix ADMIT evidence unreadable');
        }
        try {
            if (! flock($handle, LOCK_SH)) {
                throw new RuntimeException('matrix ADMIT evidence lock unavailable');
            }
            $columns = fgetcsv($handle);
            if (! is_array($columns)) {
                throw new RuntimeException('matrix ADMIT evidence has no header');
            }
            while (($cells = fgetcsv($handle)) !== false) {
                if (count($cells) !== count($columns)) {
                    continue;
                }
                $row = array_combine($columns, $cells);
                if (! is_array($row) || hash('sha256', json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) !== $hash) {
                    continue;
                }
                $timestamp = (string) ($row['snapshot_ts'] ?? '');
                if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})$/', $timestamp) !== 1) {
                    throw new RuntimeException('matrix ADMIT snapshot timestamp must be absolute with timezone');
                }
                try {
                    $parsed = new DateTimeImmutable($timestamp);
                    $errors = DateTimeImmutable::getLastErrors();
                    if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                        throw new RuntimeException('matrix ADMIT snapshot timestamp is invalid');
                    }
                    $snapshot = Carbon::instance($parsed);
                } catch (\Throwable) {
                    throw new RuntimeException('matrix ADMIT snapshot timestamp is invalid');
                }
                $age = $snapshot->diffInSeconds(now(), false);
                if (($row['verdict'] ?? '') !== 'ADMIT' || ($row['failed_signals'] ?? '') !== ''
                    || strtoupper((string) ($row['ticker'] ?? '')) !== $leg->symbol()
                    || ! is_numeric($row['dte'] ?? null)
                    || (int) $row['dte'] < (int) $leg->dteMin() || (int) $row['dte'] > (int) $leg->dteMax()
                    || ! is_numeric($row['far_delta'] ?? null)
                    || ! is_numeric($row['far_strike'] ?? null)
                    || ! is_numeric($row['spot'] ?? null)
                    || abs(abs((float) $row['far_delta']) - (float) $leg->targetDelta()) > 0.05
                    || $age < 0 || $age > 900) {
                    throw new RuntimeException('matrix row is not a fresh matching entry ADMIT');
                }

                return $row;
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        throw new RuntimeException('matrix ADMIT row hash not found');
    }
}
