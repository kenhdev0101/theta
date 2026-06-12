# equity-structure toolkit

Standalone JSON-emitting scanners for the options/equities portfolio track.
These scripts extract structures first, not trade ideas first: relative
performance, gap context, liquidity, earnings timing, trend geometry, breadth,
and a composite market-structure view.

Data source for the scanner CLIs is yfinance, so treat their outputs as
delayed research inputs, not live broker truth. Broker execution must still
go through Robinhood review tools.

Two modules are different in kind: `coordinates.py` and `transitions.py` are
**feed-agnostic pure functions** (numpy/pandas only, no yfinance, no network)
— the §3.1 coordinate gap-fills and the §3.5 transition engine from
`docs/intraday-structure-engine.md`. The hot engine imports these against the
real-time feed; the CLIs here are research harnesses around them.

## Setup

Docker-first: everything runs in the theta `greeks` container (the Python
math runtime). Run the test suite with:

```
docker compose -p theta run --rm --no-deps \
  --volume ./tools/equity-structure:/eqs greeks python -m pytest /eqs/tests -q
```

## Scripts

| Script | Purpose |
|---|---|
| `relative_strength.py` | Actual relative strength: return spread, RS line slope, acceleration, outperform/underperform labels. |
| `gap_classifier.py` | Gap size, location, volume participation, previous structure, and gap type. |
| `liquidity_scanner.py` | Dollar volume, ATR, estimated quote spread, and liquidity tier. |
| `earnings_regime.py` | Days until/since earnings from yfinance calendar when available. |
| `trend_geometry.py` | Acceptance shell, compression, coil, parabolic expansion primitives. |
| `breadth_engine.py` | Advancers, new highs, MA participation, and sector participation for a supplied universe. |
| `market_structure_scanner.py` | Composite primitive extraction and execution posture for a ticker slate. |
| `coordinates.py` | Feed-agnostic §3.1 coordinates: expansion velocity (Ċ), drive efficiency (Kaufman ER, E), acceleration (Ẍ), true time-of-day RVOL, volume-profile acceptance (A), anchored VWAP + ATR distance, halt detection. |
| `transitions.py` | §3.5 structural transition graph: legal entry/exit edges, gate overlay (vacuum/danger beat structure), persistence-k hysteresis with decisive-cross override, `expected_transition` pre-arm list, prototype-label mapping. |

## Defaults

Default pair slate:

- `AAPL:QQQ`
- `NVDA:SOXX`

Default breadth universe is a liquid large-cap/ETF starter set. Pass a
comma-separated universe for anything serious.

