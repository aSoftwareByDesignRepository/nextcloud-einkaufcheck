"""Eigenmarken-Listen für ALDI Nord und Lidl (DE)."""

from __future__ import annotations

import re
import unicodedata

ALDI_NORD: frozenset[str] = frozenset(
    {
        "activ energy",
        "adventuridge",
        "adventurdige",
        "all seasons",
        "almare",
        "ambiano",
        "asia green garden",
        "back family",
        "barissimo",
        "bbq",
        "casalux",
        "choceur",
        "crane",
        "crofton",
        "cucina",
        "culinea",
        "currystar",
        "daylicious",
        "expertiz",
        "ferrex",
        "finest bakery",
        "gardenline",
        "golden bridge",
        "golden seafood",
        "gourmet",
        "gourmet finest cuisine",
        "gut bio",
        "gut drei eichen",
        "guldendorf",
        "guldenhof",
        "güldenhof",
        "home creation",
        "jack's farm",
        "kids world",
        "kokett",
        "l&d",
        "lacura",
        "lacura spa",
        "landbeck",
        "landfreude",
        "lily & dan",
        "livergy",
        "maginon",
        "mein bestes",
        "meine metzgerei",
        "milsani",
        "moser roth",
        "mucci",
        "my best",
        "novitesse",
        "novitesse premium",
        "pizz'ah",
        "power force",
        "priano",
        "river",
        "romanzini",
        "skandinavic's",
        "speisezeit",
        "sun snacks",
        "tandil",
        "trader joe's",
        "up2fashion",
        "workzone",
    }
)

LIDL: frozenset[str] = frozenset(
    {
        "1001 delights",
        "alberto",
        "alesto",
        "alpenfest",
        "belbake",
        "chef select",
        "cien",
        "combino",
        "coshida",
        "crelando",
        "crivit",
        "crownfield",
        "deluxe",
        "duc de coeur",
        "dulano",
        "esmara",
        "esmara men",
        "floralys",
        "formil",
        "freeway",
        "freshona",
        "grillmeister",
        "italiamo",
        "kania",
        "livarno",
        "livarno home",
        "livarno lux",
        "lupilu",
        "mcennedy",
        "mc ennedy",
        "meradiso",
        "metzgerfrisch",
        "milbona",
        "mister choc",
        "nautica",
        "nostja",
        "ocean sea",
        "park side",
        "parkside",
        "parkside performance",
        "pilos",
        "silvercrest",
        "snack day",
        "solevita",
        "sondey",
        "w5",
        "backshop",
    }
)

NONFOOD_HINTS: frozenset[str] = frozenset(
    {
        "ambiano",
        "casalux",
        "cien",
        "coshida",
        "crane",
        "crelando",
        "crivit",
        "crofton",
        "esmara",
        "esmara men",
        "expertiz",
        "ferrex",
        "floralys",
        "formil",
        "gardenline",
        "home creation",
        "lacura",
        "lacura spa",
        "livarno",
        "livarno home",
        "livarno lux",
        "livergy",
        "lupilu",
        "maginon",
        "mcennedy",
        "mc ennedy",
        "meradiso",
        "novitesse",
        "parkside",
        "parkside performance",
        "power force",
        "silvercrest",
        "tandil",
        "up2fashion",
        "w5",
        "workzone",
        "activ energy",
        "adventuridge",
        "lily & dan",
        "l&d",
        "kids world",
        "kokett",
        "crelando",
    }
)

NONFOOD_PATH_RE = re.compile(
    r"baumarkt|werkstatt|mode|fashion|wohnen|garten|elektro|haushalt|"
    r"textil|schlafzimmer|badezimmer|büro|schreibwaren|nonfood",
    re.I,
)

# Lose Ware / Frische ohne Eigenmarken-Label
PRODUCE_NAME_RE = re.compile(
    r"(?:"
    r"apfel|aepfel|äpfel|banane|birne|traube|tafeltraube|orange|zitrone|"
    r"limette|kiwi|mango|ananas|melone|wassermelone|honigmelone|"
    r"erdbeer|himbeer|heidelbeer|brombeer|kirsche|pflaume|pfirsich|nektarine|"
    r"tomate|cherrytomat|datteltomat|gurke|snackgurke|paprika|zucchini|"
    r"aubergine|kartoffel|speisekartoffel|zwiebel|schalotte|knoblauch|"
    r"möhre|moehre|karotte|snackmöhre|snackmoehre|rettich|radies|"
    r"salat|romana|eisberg|rucola|spinat|mangold|kohl|wirsing|rotkohl|"
    r"weisskohl|weißkohl|blumenkohl|brokkoli|romanesco|lauch|porree|"
    r"sellerie|fenchel|spargel|bohne|erbse|mais|kürbis|kuerbis|"
    r"champignon|pilz|avocado|ingwer|kraut|\bobst\b|\bgemuese\b|\bgemüse\b"
    r")",
    re.I,
)

PRODUCE_BLOCK_RE = re.compile(
    r"chips|sauce|soße|joghurt|milch|kaffee|wasch|gel\b|pulver|waffel|"
    r"salat\s*dressing|kartoffelsalat|obstgarten|küchenhelfer|kuechenhelfer|"
    r"fruchtmix|frischei|frische\s*land|frische\s*ei|frische-fass",
    re.I,
)


def normalize_brand(value: str | None) -> str:
    text = unicodedata.normalize("NFKC", value or "")
    text = text.replace("®", "").replace("™", "").replace("©", "")
    text = re.sub(r"[’`´]", "'", text)
    text = re.sub(r"\s+", " ", text).strip().casefold()
    text = text.replace("ä", "a").replace("ö", "o").replace("ü", "u").replace("ß", "ss")
    return text


def _allowlist_for(store: str) -> frozenset[str]:
    if store == "ALDI Nord":
        return ALDI_NORD
    if store == "Lidl":
        return LIDL
    return ALDI_NORD | LIDL


def _matches_allowed(hay: str, allowed: str) -> bool:
    return hay == allowed or hay.startswith(allowed + " ") or allowed.startswith(hay + " ")


def matched_brand(store: str, brand: str | None, name: str | None = None) -> str:
    """Gibt den erkannten Eigenmarken-Namen zurück oder leer."""
    allow = sorted(_allowlist_for(store), key=len, reverse=True)
    brand_n = normalize_brand(brand)
    if brand_n:
        for allowed in allow:
            if _matches_allowed(brand_n, allowed):
                raw = (brand or "").replace("®", "").replace("™", "").strip()
                return raw or allowed.title()
        return ""
    name_n = normalize_brand(name)
    if not name_n:
        return ""
    for allowed in allow:
        if name_n == allowed or name_n.startswith(allowed + " "):
            return allowed.title()
    return ""


def is_private_label(store: str, brand: str | None, name: str | None = None) -> bool:
    return bool(matched_brand(store, brand, name))


def is_produce(brand: str | None, name: str | None) -> bool:
    """Lose Obst/Gemüse-Ware (oft ohne Marke) und All-Seasons-/Bio-Frische."""
    brand_n = normalize_brand(brand)
    name_n = normalize_brand(name)
    if not name_n:
        return False
    if PRODUCE_BLOCK_RE.search(name_n):
        return False
    if brand_n and brand_n not in {"", "bio", "all seasons", "gut bio"}:
        # Markenartikel (z. B. Cotton Candy Trauben) nicht als Frische behandeln
        if not is_private_label("ALDI Nord", brand, name) and not is_private_label("Lidl", brand, name):
            return False
    return bool(PRODUCE_NAME_RE.search(name_n))


def category_for(brand: str | None, extra: str = "", *, produce: bool = False) -> str:
    if produce:
        return "produce"
    b = normalize_brand(brand)
    for hint in NONFOOD_HINTS:
        if b == hint or b.startswith(hint + " "):
            return "nonfood"
    hay = f"{b} {extra}"
    if NONFOOD_PATH_RE.search(hay):
        return "nonfood"
    return "food"
