"""Gleiche Angebote über Händler hinweg zusammenführen."""

from __future__ import annotations

import re
from collections import defaultdict
from typing import Any

from brands import normalize_brand

NOISE = frozenset(
    {
        "xxl",
        "xl",
        "xxxl",
        "bio",
        "frisch",
        "frische",
        "haltbare",
        "premium",
        "aktion",
        "plus",
        "je",
        "packung",
        "beutel",
        "becher",
        "dose",
        "glas",
        "stuck",
        "versch",
        "sorten",
        "und",
        "mit",
        "aus",
        "der",
        "die",
        "das",
        "zum",
        "zur",
        "im",
        "in",
        "von",
        "fur",
        "fuer",
        "neu",
        "limitierte",
        "edition",
        "dunkle",
        "dunkel",
        "helle",
        "hell",
        "braun",
        "weisse",
        "weiss",
        "rote",
        "rot",
        "gruene",
        "grun",
        "gelb",
    }
)

UNIT_RE = re.compile(
    r"\d+(?:[.,]\d+)?\s*(?:kg|g|l|ml|liter|stk|st|er|x)\b",
    re.I,
)
SPLIT_RE = re.compile(r"[^a-z0-9]+")


def _stem(token: str) -> str:
    for suf in ("chen", "lein", "heiten", "ungen", "ern", "en", "er", "es", "e", "n", "s"):
        if token.endswith(suf) and len(token) - len(suf) >= 4:
            return token[: -len(suf)]
    return token


def _tokens(offer: dict[str, Any]) -> tuple[str, ...]:
    name = normalize_brand(str(offer.get("name") or ""))
    brand = normalize_brand(str(offer.get("brand") or ""))
    if brand and (name.startswith(brand + " ") or name == brand):
        name = name[len(brand) :].strip()
    name = UNIT_RE.sub(" ", name)
    raw = [t for t in SPLIT_RE.split(name) if t]
    tokens = [_stem(t) for t in raw if t not in NOISE and len(t) >= 3 and not t.isdigit()]
    return tuple(sorted(set(t for t in tokens if len(t) >= 3)))


def _unit_score(offer: dict[str, Any]) -> tuple[int, float]:
    """Niedriger ist besser. 0=€/kg, 1=€/l, 2=Stückpreis."""
    if offer.get("per_kg") is not None:
        return 0, float(offer["per_kg"])
    if offer.get("per_l") is not None:
        return 1, float(offer["per_l"])
    if offer.get("price") is not None:
        return 2, float(offer["price"])
    return 3, 1e12


def _jaccard(a: tuple[str, ...], b: tuple[str, ...]) -> float:
    sa, sb = set(a), set(b)
    if not sa or not sb:
        return 0.0
    return len(sa & sb) / len(sa | sb)


def _same_bucket(a: dict[str, Any], b: dict[str, Any]) -> bool:
    if a.get("category") != b.get("category"):
        return False
    ta, tb = a["_tokens"], b["_tokens"]
    if not ta or not tb:
        return False
    if ta == tb:
        return True
    if len(ta) == 1 and len(tb) == 1:
        return ta[0] == tb[0]
    score = _jaccard(ta, tb)
    if score >= 0.67:
        return True
    shared = set(ta) & set(tb)
    if len(shared) >= 2 and score >= 0.5:
        return True
    # Obst/Gemüse: ein gemeinsamer Stamm reicht
    if a.get("category") == "produce" and b.get("category") == "produce" and shared:
        return True
    return False


def attach_matches(offers: list[dict[str, Any]]) -> list[dict[str, Any]]:
    """Setzt match_* Felder. Nur Gruppen mit mindestens zwei Händlern zählen als Vergleich."""
    for offer in offers:
        offer["_tokens"] = _tokens(offer)
        offer["match_id"] = ""
        offer["match_stores"] = 1
        offer["is_cheapest"] = False
        offer["compare"] = []

    n = len(offers)
    parent = list(range(n))

    def find(i: int) -> int:
        while parent[i] != i:
            parent[i] = parent[parent[i]]
            i = parent[i]
        return i

    def union(i: int, j: int) -> None:
        ri, rj = find(i), find(j)
        if ri != rj:
            parent[rj] = ri

    for i in range(n):
        for j in range(i + 1, n):
            if offers[i].get("store") == offers[j].get("store"):
                continue
            if _same_bucket(offers[i], offers[j]):
                union(i, j)

    groups: dict[int, list[int]] = defaultdict(list)
    for i in range(n):
        groups[find(i)].append(i)

    match_n = 0
    for members in groups.values():
        stores = {offers[i]["store"] for i in members}
        if len(stores) < 2:
            continue
        match_n += 1
        match_id = f"m{match_n}"
        ranked = sorted(members, key=lambda i: _unit_score(offers[i]))
        best = ranked[0]
        snapshot = []
        for i in ranked:
            o = offers[i]
            snapshot.append(
                {
                    "store": o.get("store"),
                    "brand": o.get("brand"),
                    "name": o.get("name"),
                    "pack": o.get("pack"),
                    "price": o.get("price"),
                    "per_kg": o.get("per_kg"),
                    "per_l": o.get("per_l"),
                    "cheapest": i == best,
                }
            )
        for i in members:
            offers[i]["match_id"] = match_id
            offers[i]["match_stores"] = len(stores)
            offers[i]["is_cheapest"] = i == best
            offers[i]["compare"] = snapshot

    for offer in offers:
        offer.pop("_tokens", None)
    return offers


def query_tokens(query: str, brand: str = "") -> tuple[str, ...]:
    return _tokens({"name": query, "brand": brand})


def watch_matches(watch: dict[str, Any], offer: dict[str, Any]) -> bool:
    store = str(watch.get("store") or "").strip()
    if store and store not in {"all", "*"} and store != offer.get("store"):
        return False
    max_price = watch.get("max_price")
    if max_price not in (None, ""):
        if offer.get("price") is None or float(offer["price"]) > float(max_price) + 1e-9:
            return False
    max_kg = watch.get("max_per_kg")
    if max_kg not in (None, ""):
        if offer.get("per_kg") is None or float(offer["per_kg"]) > float(max_kg) + 1e-9:
            return False
    qn = normalize_brand(str(watch.get("query") or ""))
    hay = normalize_brand(f"{offer.get('brand') or ''} {offer.get('name') or ''}")
    if qn and qn in hay:
        return True
    qt = query_tokens(str(watch.get("query") or ""), str(watch.get("brand") or ""))
    ot = _tokens(offer)
    if not qt or not ot:
        return False
    if set(qt) <= set(ot):
        return True
    return bool(set(qt) & set(ot)) and _jaccard(qt, ot) >= 0.5


def watch_hits(watches: list[dict[str, Any]], offers: list[dict[str, Any]]) -> list[dict[str, Any]]:
    """Treffer: Vorratsartikel im Angebot und unter der Preisobergrenze."""
    hits: list[dict[str, Any]] = []
    for watch in watches:
        if watch.get("enabled") is False:
            continue
        for offer in offers:
            if not watch_matches(watch, offer):
                continue
            hits.append(
                {
                    "watch_id": watch.get("id"),
                    "query": watch.get("query"),
                    "max_price": watch.get("max_price"),
                    "max_per_kg": watch.get("max_per_kg"),
                    "offer": {
                        "store": offer.get("store"),
                        "brand": offer.get("brand"),
                        "name": offer.get("name"),
                        "pack": offer.get("pack"),
                        "price": offer.get("price"),
                        "per_kg": offer.get("per_kg"),
                        "per_l": offer.get("per_l"),
                        "url": offer.get("url"),
                    },
                }
            )
    return hits

