"""Lesen der offiziellen JSON-Feeds von ALDI Nord und Lidl."""

from __future__ import annotations

import json
import re
import ssl
import urllib.parse
import urllib.request
from dataclasses import asdict, dataclass
from datetime import date, datetime, timezone
from typing import Any

from brands import category_for, is_produce, matched_brand, normalize_brand
from match import attach_matches

USER_AGENT = (
    "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36"
)
LIDL_PLUS_UA = "LidlPlus/17.0.5 Android okhttp/4.12.0"
SSL = ssl.create_default_context()
KIEL_LAT, KIEL_LON = 54.3293, 10.1798  # used only for PLZ 24149
GEOCODE_UA = "EinkaufCheck/1.2.0 (Nextcloud; +https://software-by-design.de)"


def _in_germany(lat: float, lon: float) -> bool:
    return 47.2 <= lat <= 55.2 and 5.8 <= lon <= 15.15


def _normalize_de_coords(lat: float, lon: float) -> tuple[float, float] | None:
    """Accept DE bbox, or swapped lat/lon (some geocoders invert them)."""
    if _in_germany(lat, lon):
        return lat, lon
    if _in_germany(lon, lat):
        return lon, lat
    return None


def _geocode_de_plz(plz: str) -> tuple[float, float]:
    """Map a German PLZ to coordinates. Never silently fall back to Kiel."""
    if plz == "24149":
        return KIEL_LAT, KIEL_LON

    nom_url = "https://nominatim.openstreetmap.org/search?" + urllib.parse.urlencode(
        {
            "postalcode": plz,
            "country": "Germany",
            "countrycodes": "de",
            "format": "json",
            "limit": "1",
        }
    )
    try:
        data = _get_json(
            nom_url,
            {"Accept": "application/json", "User-Agent": GEOCODE_UA},
            timeout=10,
        )
        if isinstance(data, list) and data and isinstance(data[0], dict):
            coords = _normalize_de_coords(float(data[0]["lat"]), float(data[0]["lon"]))
            if coords is not None:
                return coords
    except (TypeError, ValueError, KeyError, OSError, RuntimeError):
        pass

    try:
        data = _get_json(
            f"https://api.zippopotam.us/de/{plz}",
            {"Accept": "application/json", "User-Agent": GEOCODE_UA},
            timeout=10,
        )
        if isinstance(data, dict):
            places = data.get("places") or []
            if places and isinstance(places[0], dict):
                coords = _normalize_de_coords(
                    float(places[0]["latitude"]),
                    float(places[0]["longitude"]),
                )
                if coords is not None:
                    return coords
    except (TypeError, ValueError, KeyError, OSError, RuntimeError):
        pass

    raise RuntimeError(f"Keine Koordinaten für PLZ {plz}")

PRICE_RE = re.compile(r"(\d+(?:[.,]\d+)?)")
KG_RE = re.compile(r"1\s*kg\s*=\s*(\d+(?:[.,]\d+)?)", re.I)
L_RE = re.compile(r"1\s*l(?:iter)?\s*=\s*(\d+(?:[.,]\d+)?)", re.I)
PACK_RE = re.compile(
    r"(\d+(?:[.,]\d+)?)\s*(kg|g|l|ml|liter)\b",
    re.I,
)


@dataclass
class Offer:
    store: str
    source: str
    brand: str
    name: str
    pack: str
    price: float | None
    old_price: float | None
    per_kg: float | None
    per_l: float | None
    unit_label: str
    valid_from: str
    valid_until: str
    category: str
    url: str
    image: str
    note: str

    def to_dict(self) -> dict[str, Any]:
        return asdict(self)


def _get(url: str, headers: dict[str, str], timeout: float = 30) -> bytes:
    req = urllib.request.Request(url, headers=headers)
    with urllib.request.urlopen(req, timeout=timeout, context=SSL) as resp:
        return resp.read()


def _get_json(url: str, headers: dict[str, str], timeout: float = 30) -> Any:
    return json.loads(_get(url, headers, timeout))


def _num(value: Any) -> float | None:
    if value is None or value == "":
        return None
    if isinstance(value, (int, float)):
        return float(value)
    text = str(value).strip().replace("€", "").replace(" ", "").replace(",", ".")
    match = PRICE_RE.search(text)
    return float(match.group(1)) if match else None


def _de_price(value: float | None) -> str:
    if value is None:
        return ""
    return f"{value:.2f}".replace(".", ",") + " €"


def _iso_from_unix(ts: Any) -> str:
    if not ts:
        return ""
    try:
        return datetime.fromtimestamp(int(ts), tz=timezone.utc).date().isoformat()
    except (TypeError, ValueError, OSError):
        return ""


def _iso_from_text(value: Any) -> str:
    if not value:
        return ""
    text = str(value)
    try:
        return datetime.fromisoformat(text.replace("Z", "+00:00")).date().isoformat()
    except ValueError:
        return text[:10]


def parse_unit_prices(*blobs: str | None) -> tuple[float | None, float | None]:
    text = " ".join(b for b in blobs if b)
    kg = KG_RE.search(text)
    liter = L_RE.search(text)
    return (
        _num(kg.group(1)) if kg else None,
        _num(liter.group(1)) if liter else None,
    )


def infer_unit_from_pack(price: float | None, pack: str) -> tuple[float | None, float | None]:
    if price is None or not pack:
        return None, None
    match = PACK_RE.search(pack.replace(",", "."))
    if not match:
        return None, None
    amount = float(match.group(1).replace(",", "."))
    unit = match.group(2).lower()
    if amount <= 0:
        return None, None
    if unit == "g":
        return round(price / (amount / 1000.0), 2), None
    if unit == "kg":
        return round(price / amount, 2), None
    if unit == "ml":
        return None, round(price / (amount / 1000.0), 2)
    if unit in {"l", "liter"}:
        return None, round(price / amount, 2)
    return None, None


def _unit_label(per_kg: float | None, per_l: float | None) -> str:
    parts = []
    if per_kg is not None:
        parts.append(f"{_de_price(per_kg)}/kg")
    if per_l is not None:
        parts.append(f"{_de_price(per_l)}/l")
    return " · ".join(parts)


def _first_base_price(entries: Any) -> tuple[float | None, str]:
    if not isinstance(entries, list) or not entries:
        return None, ""
    item = entries[0]
    if not isinstance(item, dict):
        return None, ""
    scale = str(item.get("basePriceScale") or "").lower()
    return _num(item.get("basePriceValue")), scale


# --- ALDI Nord -------------------------------------------------------------


def fetch_aldi(week: str = "current") -> list[Offer]:
    path = "/angebote.html" if week != "next" else "/angebote-vorschau.html"
    html = _get(
        f"https://www.aldi-nord.de{path}",
        {
            "User-Agent": USER_AGENT,
            "Accept": "text/html,application/xhtml+xml",
            "Accept-Language": "de-DE,de;q=0.9",
        },
    ).decode("utf-8", "replace")
    match = re.search(
        r'<script id="__NEXT_DATA__" type="application/json">(.*?)</script>',
        html,
    )
    if not match:
        raise RuntimeError("ALDI Nord: __NEXT_DATA__ nicht gefunden")
    page = json.loads(match.group(1))
    api = json.loads(page["props"]["pageProps"]["apiData"])
    products = api[0][1]["res"]["algoliaDataMap"]
    offers: list[Offer] = []
    for raw in products.values():
        brand = str(raw.get("brandName") or "").strip()
        name = str(raw.get("name") or "").strip()
        produce = is_produce(brand, name)
        label = matched_brand("ALDI Nord", brand, name)
        if not label and not produce:
            continue
        if not label:
            label = brand or "Frische"
        price_block = raw.get("currentPrice") or {}
        promo = (raw.get("promotionPrices") or [None])[0] or {}
        price = _num(price_block.get("priceValue") or promo.get("priceValue"))
        old = _num((price_block.get("strikePrice") or promo.get("strikePrice") or {}).get("strikePriceValue"))
        pack = str(raw.get("salesUnit") or raw.get("shortDescription") or "").strip()
        per_kg, scale = _first_base_price(price_block.get("basePrice") or promo.get("basePrice"))
        per_l = per_kg if scale in {"liter", "l"} else None
        if scale in {"liter", "l"}:
            per_kg = None
        if scale not in {"kg", "liter", "l"}:
            per_kg = None
        if per_kg is None and per_l is None:
            per_kg, per_l = infer_unit_from_pack(price, pack)
        valid_from = promo.get("validFromLocalDate") or _iso_from_unix(
            promo.get("validFrom") or price_block.get("validFrom")
        )
        valid_until = promo.get("validUntilLocalDate") or _iso_from_unix(
            promo.get("validUntil") or price_block.get("validUntil")
        )
        assets = raw.get("assets") or []
        image = ""
        if isinstance(assets, list) and assets:
            image = str(assets[0].get("url") or "")
        slug = raw.get("productSlug") or ""
        promo_label = (price_block.get("priceTagLabels") or {}).get("promoText1") or ""
        extra = f"{raw.get('shortDescription') or ''} {raw.get('longDescription') or ''}"
        offers.append(
            Offer(
                store="ALDI Nord",
                source="aldi-nord",
                brand=label,
                name=name,
                pack=pack,
                price=price,
                old_price=old,
                per_kg=per_kg,
                per_l=per_l,
                unit_label=_unit_label(per_kg, per_l),
                valid_from=str(valid_from or ""),
                valid_until=str(valid_until or ""),
                category=category_for(label, extra, produce=produce),
                url=f"https://www.aldi-nord.de/produkt/{slug}" if slug else "https://www.aldi-nord.de/angebote.html",
                image=image,
                note=str(promo_label),
            )
        )
    return offers


# --- Lidl Plus (Filiale) ---------------------------------------------------


def find_lidl_store(plz: str) -> dict[str, Any]:
    lat, lon = _geocode_de_plz(plz)
    query = urllib.parse.urlencode(
        {
            "input": plz,
            "language": "de",
            "latitude": lat,
            "longitude": lon,
        }
    )
    stores = _get_json(
        f"https://stores.lidlplus.com/api/v1/autocomplete/DE?{query}",
        {
            "Accept": "application/json",
            "Accept-Language": "de-DE",
            "User-Agent": LIDL_PLUS_UA,
            "X-Client-Version": "17.0.5",
            "X-Client-Platform": "android",
        },
    )
    if not stores:
        raise RuntimeError(f"Keine Lidl-Filiale für {plz}")
    exact = [s for s in stores if str(s.get("postalCode") or "") == plz]
    pool = exact or list(stores)
    return min(pool, key=lambda s: float(s.get("distance") or 9e9))


def fetch_lidl_plus(store_key: str) -> list[Offer]:
    data = _get_json(
        f"https://offers.lidlplus.com/app/api/v4/DE/{store_key}/offers",
        {
            "Accept": "application/json",
            "Accept-Language": "de-DE",
            "User-Agent": LIDL_PLUS_UA,
            "X-Client-Version": "17.0.5",
            "X-Client-Platform": "android",
        },
    )
    offers: list[Offer] = []
    for raw in data.get("offers") or []:
        brand = str(raw.get("brand") or "").strip()
        name = str(raw.get("title") or "").strip()
        produce = is_produce(brand, name)
        label = matched_brand("Lidl", brand, name)
        if not label and not produce:
            continue
        if not label:
            label = brand or "Frische"
        box = raw.get("priceBox") or {}
        price = _num(box.get("largePartNumeric") or box.get("largePartString"))
        old = _num(box.get("smallPartNumeric") or box.get("smallPartString"))
        pack = str(raw.get("packaging") or "").replace("\n", " · ").strip()
        ppu = str(raw.get("pricePerUnit") or "")
        per_kg, per_l = parse_unit_prices(ppu, pack)
        if per_kg is None and per_l is None:
            per_kg, per_l = infer_unit_from_pack(price, pack)
        extra = f"{raw.get('category') or ''} {pack}"
        offers.append(
            Offer(
                store="Lidl",
                source="lidl-plus",
                brand=label,
                name=name,
                pack=pack.split("·")[0].strip() if pack else "",
                price=price,
                old_price=old,
                per_kg=per_kg,
                per_l=per_l,
                unit_label=_unit_label(per_kg, per_l) or ppu,
                valid_from=_iso_from_text(raw.get("startValidityDate")),
                valid_until=_iso_from_text(raw.get("endValidityDate")),
                category=category_for(label, extra, produce=produce),
                url="",
                image=str(raw.get("imageUrl") or ""),
                note=str(box.get("discountMessage") or ""),
            )
        )
    return offers


# --- Lidl Aktionsprospekt (leaflets.schwarz) -------------------------------


def _aktionsprospekte() -> list[dict[str, Any]]:
    overview = _get_json(
        "https://endpoints.leaflets.schwarz/v4/overview?client_locale=lidl/de-DE",
        {"User-Agent": USER_AGENT, "Accept": "application/json"},
    )
    seen: set[tuple[str, str]] = set()
    out: list[dict[str, Any]] = []
    for cat in overview.get("categories") or []:
        for sub in cat.get("subcategories") or []:
            if sub.get("name") != "Unsere Aktionsprospekte":
                continue
            for flyer in sub.get("flyers") or []:
                if flyer.get("name") != "Aktionsprospekt":
                    continue
                regions = flyer.get("regions") or []
                if not any(r.get("type") == "national" for r in regions):
                    continue
                start = str(flyer.get("offerStartDate") or flyer.get("startDate") or "")
                end = str(flyer.get("offerEndDate") or flyer.get("endDate") or "")
                key = (start, end)
                if not start or key in seen:
                    continue
                seen.add(key)
                out.append({**flyer, "_start": start, "_end": end})
    out.sort(key=lambda f: f["_start"])
    return out


def _current_national_flyer(week: str = "current") -> dict[str, Any]:
    """Aktionsprospekt dieser bzw. nächsten Woche.

    Am Sonntag vor Angebotsstart (Mo–Sa) gilt der nächste Prospekt als current.
    """
    today = date.today().isoformat()
    candidates = _aktionsprospekte()
    if not candidates:
        raise RuntimeError("Lidl: kein nationaler Aktionsprospekt")
    live = [f for f in candidates if f["_start"] <= today <= f["_end"]]
    upcoming = [f for f in candidates if f["_start"] > today]
    if live:
        current = live[-1]
    elif upcoming:
        current = upcoming[0]
    else:
        current = candidates[-1]
    if week != "next":
        return current
    later = [f for f in candidates if f["_start"] > current["_start"]]
    return later[0] if later else current


def fetch_lidl_flyer(week: str = "current") -> tuple[dict[str, Any], list[Offer]]:
    meta = _current_national_flyer(week)
    flyer_json = meta.get("flyerJson")
    if not flyer_json:
        raise RuntimeError("Lidl: flyerJson fehlt")
    payload = _get_json(flyer_json, {"User-Agent": USER_AGENT, "Accept": "application/json"})
    products = (payload.get("flyer") or {}).get("products") or {}
    offers: list[Offer] = []
    for raw in products.values():
        brand = str(raw.get("brand") or "").strip()
        name = str(raw.get("title") or "").strip()
        produce = is_produce(brand, name)
        label = matched_brand("Lidl", brand, name)
        if not label and not produce:
            continue
        if not label:
            label = brand or "Frische"
        price = _num(raw.get("price"))
        desc = str(raw.get("description") or "")
        pack = ""
        pack_match = PACK_RE.search(re.sub(r"<[^>]+>", " ", desc))
        if pack_match:
            pack = f"{pack_match.group(1)} {pack_match.group(2)}"
        per_kg, per_l = parse_unit_prices(desc, str(raw.get("currencyText") or ""))
        if per_kg is None and per_l is None:
            per_kg, per_l = infer_unit_from_pack(price, pack or desc)
        path = f"{raw.get('categoryPrimary') or ''} {raw.get('wonCategoryPrimary') or ''} {desc}"
        url = str(raw.get("canonicalUrl") or raw.get("url") or "")
        if url.startswith("/"):
            url = "https://www.lidl.de" + url
        offers.append(
            Offer(
                store="Lidl",
                source="lidl-flyer",
                brand=label,
                name=name,
                pack=pack,
                price=price,
                old_price=None,
                per_kg=per_kg,
                per_l=per_l,
                unit_label=_unit_label(per_kg, per_l),
                valid_from=str(meta.get("offerStartDate") or ""),
                valid_until=str(meta.get("offerEndDate") or ""),
                category=category_for(label, path, produce=produce),
                url=url,
                image=str(raw.get("image") or ""),
                note="",
            )
        )
    return meta, offers


def _dedupe(offers: list[Offer]) -> list[Offer]:
    seen: dict[tuple[str, str], Offer] = {}
    rank = {"lidl-plus": 0, "aldi-nord": 0, "lidl-flyer": 1}
    for offer in offers:
        key = (offer.store, normalize_brand(offer.name)[:80])
        prev = seen.get(key)
        if prev is None:
            seen[key] = offer
            continue
        better = rank.get(offer.source, 9) < rank.get(prev.source, 9)
        more_unit = (offer.per_kg or offer.per_l) and not (prev.per_kg or prev.per_l)
        if better or more_unit:
            seen[key] = offer
    return list(seen.values())


def fetch_all(plz: str = "24149", week: str = "current") -> dict[str, Any]:
    errors: list[str] = []
    offers: list[Offer] = []
    store_info: dict[str, Any] = {}

    try:
        offers.extend(fetch_aldi(week))
    except Exception as exc:
        errors.append(f"ALDI Nord: {exc}")

    try:
        store_info = find_lidl_store(plz)
        offers.extend(fetch_lidl_plus(store_info["storeKey"]))
    except Exception as exc:
        errors.append(f"Lidl Plus: {exc}")

    flyer_meta: dict[str, Any] = {}
    try:
        flyer_meta, flyer_offers = fetch_lidl_flyer(week)
        offers.extend(flyer_offers)
    except Exception as exc:
        errors.append(f"Lidl Prospekt: {exc}")

    unique = _dedupe(offers)
    unique.sort(key=lambda o: (o.per_kg is None, o.per_kg or 0, o.store, o.brand, o.name))
    payload = [o.to_dict() for o in unique]
    attach_matches(payload)
    compared = sum(1 for o in payload if o.get("match_stores", 1) > 1)
    groups = len({o["match_id"] for o in payload if o.get("match_id")})
    return {
        "fetched_at": datetime.now().astimezone().isoformat(timespec="seconds"),
        "plz": plz,
        "week": week,
        "lidl_store": {
            "key": store_info.get("storeKey"),
            "name": store_info.get("name"),
            "address": store_info.get("address"),
            "postal_code": store_info.get("postalCode"),
            "city": store_info.get("locality"),
        },
        "lidl_flyer": {
            "title": flyer_meta.get("title"),
            "from": flyer_meta.get("offerStartDate"),
            "until": flyer_meta.get("offerEndDate"),
        },
        "counts": {
            "total": len(payload),
            "aldi": sum(1 for o in payload if o["store"] == "ALDI Nord"),
            "lidl": sum(1 for o in payload if o["store"] == "Lidl"),
            "food": sum(1 for o in payload if o["category"] == "food"),
            "produce": sum(1 for o in payload if o["category"] == "produce"),
            "with_per_kg": sum(1 for o in payload if o["per_kg"] is not None),
            "with_per_l": sum(1 for o in payload if o["per_l"] is not None),
            "compared": compared,
            "compare_groups": groups,
        },
        "stores_status": {
            "aldi_nord": "ok",
            "lidl": "ok",
            "penny": "blocked_auth",
            "rewe": "blocked_mtls",
            "kaufland": "blocked_auth",
            "netto": "blocked_auth",
        },
        "notes": [
            "ALDI Nord: komplette Wochenangebote inkl. offiziellem Grundpreis (€/kg, €/l).",
            "Lidl Plus: Filial-Specials mit Kilopreis — nicht der volle Lebensmittel-Prospekt.",
            "Lidl Prospekt-Katalog: strukturierte Artikel mit Produktseite, meist Non-Food.",
            "Obst/Gemüse: lose Frische ohne Marke (z. B. Bananen, Trauben) ist als Kategorie „produce“ enthalten.",
            "Vergleich: gleiche Ware in mehreren Läden wird gruppiert; günstigster Preis bevorzugt über €/kg.",
            "REWE/Kaufland/Netto/Penny: öffentliche JSON-APIs brauchen App-Zertifikate/Login — noch nicht angebunden.",
        ],
        "errors": errors,
        "offers": payload,
    }
