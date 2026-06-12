# equity-structure toolkit

Standalone JSON-emitting scanners for the options/equities portfolio track.
These scripts extract structures first, not trade ideas first: relative
performance, gap context, liquidity, earnings timing, trend geometry, breadth,
and a composite market-structure view.

Data source is yfinance, so treat outputs as delayed research inputs, not live
broker truth. Broker execution must still go through Robinhood review tools.

## Setup

This toolkit reuses the options-math dependency set:

```
./tools/options-math/setup.sh
```

Invoke with:

```
./tools/options-math/.venv/bin/python tools/equity-structure/relative_strength.py --pairs AAPL:QQQ,NVDA:SOXX
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

## Defaults

Default pair slate:

- `AAPL:QQQ`
- `NVDA:SOXX`

Default breadth universe is a liquid large-cap/ETF starter set. Pass a
comma-separated universe for anything serious.

