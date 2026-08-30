#!/usr/bin/env python3
"""CLI for Nextcloud EinkaufCheck — JSON auf stdout."""

from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from sources import fetch_all  # noqa: E402

PLZ_RE = re.compile(r"^\d{5}$")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--plz", default="24149")
    parser.add_argument("--week", choices=("current", "next"), default="current")
    args = parser.parse_args()
    if not PLZ_RE.fullmatch(args.plz):
        json.dump({"error": "invalid_plz", "offers": [], "errors": ["invalid_plz"]}, sys.stdout)
        sys.stdout.write("\n")
        return 2
    data = fetch_all(plz=args.plz, week=args.week)
    json.dump(data, sys.stdout, ensure_ascii=False)
    sys.stdout.write("\n")
    return 0 if not data.get("errors") else 1


if __name__ == "__main__":
    raise SystemExit(main())
