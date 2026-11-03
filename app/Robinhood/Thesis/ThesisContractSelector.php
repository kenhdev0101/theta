<?php

declare(strict_types=1);

namespace App\Robinhood\Thesis;

use App\Robinhood\Mcp\PortfolioReader;
use App\Robinhood\Mcp\RobinhoodMcpClient;
use Illuminate\Support\Carbon;

/**
 * Deterministic expiration + strike selection for a standing thesis.
 *
 * The thesis precommits a DTE band and a direction while the operator is
 * calm; this class turns that into one concrete expiration and one concrete
 * strike against the live chain, minutes before the open. Nothing here is
 * model-chosen, and nothing here is derived overnight — that is the playbook
 * lesson this whole lane exists to encode.
 *
 * The resulting (expiration, strike) pair is handed to
 * `OptionContractResolver::resolveExact()`, which owns the option-id and
 * quote truth. This class never synthesizes an option id.
 */
final class ThesisContractSelector
{
    /**
     * Bound on instrument pagination while walking one expiration's strike
     * ladder. Broad enough for a full listed ladder; the read is stamped with
     * `truncated` so a ladder that outruns it is visible, not silent.
     */
    public const MAX_CHAIN_PAGES = 8;

    public function __construct(
        private readonly RobinhoodMcpClient $client,
    ) {}

    /**
     * Pick the expiration inside [dteMin, dteMax] closest to the middle of the
     * band. Ties resolve to the earlier expiration (less time premium paid for
     * the same thesis horizon).
     *
     * @return array{expiration: string|null, dte: int|null, considered: list<array{expiration: string, dte: int}>, reason: string|null}
     */
    public function chooseExpiration(string $underlyingSymbol, int $dteMin, int $dteMax, ?Carbon $today = null): array
    {
        $today = ($today ?? Carbon::now())->copy()->startOfDay();
        $chains = PortfolioReader::listFrom($this->client->getOptionChains($underlyingSymbol), ['chains']);

        $considered = [];
        foreach ($chains as $chain) {
            if (! is_array($chain)) {
                continue;
            }
            foreach ((array) ($chain['expiration_dates'] ?? []) as $date) {
                if (! is_string($date) || $date === '') {
                    continue;
                }

                try {
                    $expiration = Carbon::parse($date)->startOfDay();
                } catch (\Throwable) {
                    continue;
                }

                $dte = (int) $today->diffInDays($expiration, false);
                if ($dte < $dteMin || $dte > $dteMax) {
                    continue;
                }

                $considered[$expiration->toDateString()] = [
                    'expiration' => $expiration->toDateString(),
                    'dte' => $dte,
                ];
            }
        }

        $considered = array_values($considered);
        usort($considered, static fn (array $a, array $b): int => $a['dte'] <=> $b['dte']);

        if ($considered === []) {
            return [
                'expiration' => null,
                'dte' => null,
                'considered' => [],
                'reason' => 'no_expiration_in_dte_band',
            ];
        }

        $target = ($dteMin + $dteMax) / 2;
        $best = $considered[0];
        foreach ($considered as $candidate) {
            $bestDistance = abs($best['dte'] - $target);
            $distance = abs($candidate['dte'] - $target);
            // Strict improvement only — the list is DTE-ascending, so a tie
            // keeps the earlier expiration.
            if ($distance < $bestDistance) {
                $best = $candidate;
            }
        }

        return [
            'expiration' => $best['expiration'],
            'dte' => $best['dte'],
            'considered' => $considered,
            'reason' => null,
        ];
    }

    /**
     * Pick the listed strike nearest to live spot. Ties resolve to the lower
     * strike (cheaper for a call, further out-of-the-money for a put — the
     * conservative side of an exact tie in both cases).
     *
     * @return array{strike: float|null, strikes_seen: int, pages_read: int, truncated: bool, reason: string|null}
     */
    public function chooseStrike(string $underlyingSymbol, string $expiration, string $optionType, float $spot): array
    {
        $strikes = [];
        $cursor = null;
        $pages = 0;
        $truncated = false;

        for ($page = 0; $page < self::MAX_CHAIN_PAGES; $page++) {
            $payload = $this->client->getOptionInstruments(array_filter([
                'chain_symbol' => strtoupper($underlyingSymbol),
                'expiration_dates' => $expiration,
                'type' => $optionType,
                'state' => 'active',
                'tradability' => 'tradable',
                'cursor' => $cursor,
            ], static fn ($value): bool => $value !== null));
            $pages++;

            foreach (PortfolioReader::listFrom($payload, ['instruments']) as $instrument) {
                if (! is_array($instrument) || ! is_numeric($instrument['strike_price'] ?? null)) {
                    continue;
                }
                $strike = (float) $instrument['strike_price'];
                if ($strike > 0.0) {
                    $strikes[number_format($strike, 4, '.', '')] = $strike;
                }
            }

            $next = data_get($payload, 'data.next') ?? data_get($payload, 'next');
            $cursor = is_string($next) ? $this->cursorFromNext($next) : null;
            if ($cursor === null) {
                break;
            }
            if ($page === self::MAX_CHAIN_PAGES - 1) {
                $truncated = true;
            }
        }

        $strikes = array_values($strikes);
        if ($strikes === []) {
            return [
                'strike' => null,
                'strikes_seen' => 0,
                'pages_read' => $pages,
                'truncated' => $truncated,
                'reason' => 'no_listed_strikes',
            ];
        }

        sort($strikes);
        $best = $strikes[0];
        foreach ($strikes as $strike) {
            // Strict improvement only — the ladder is ascending, so an exact
            // distance tie keeps the lower strike.
            if (abs($strike - $spot) < abs($best - $spot)) {
                $best = $strike;
            }
        }

        return [
            'strike' => $best,
            'strikes_seen' => count($strikes),
            'pages_read' => $pages,
            'truncated' => $truncated,
            'reason' => null,
        ];
    }

    private function cursorFromNext(string $next): ?string
    {
        $query = parse_url($next, PHP_URL_QUERY);
        if (! is_string($query)) {
            return null;
        }

        parse_str($query, $params);
        $cursor = $params['cursor'] ?? null;

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }
}
