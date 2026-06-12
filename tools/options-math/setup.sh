#!/usr/bin/env bash
# One-time setup: build a venv at tools/options-math/.venv with the toolkit deps.
# Re-run after editing requirements.txt.

set -euo pipefail

cd "$(dirname "$0")"

if [ ! -d ".venv" ]; then
  python3 -m venv .venv
fi

./.venv/bin/pip install --quiet --upgrade pip
./.venv/bin/pip install --quiet -r requirements.txt

echo "options-math venv ready at $(pwd)/.venv"
echo "scripts callable via: $(pwd)/.venv/bin/python <script>.py [args]"
