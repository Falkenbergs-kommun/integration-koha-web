"""Beskrivande texter om en bok ur Libris (KB:s nationella katalog).

Ersätter Google Search-grundningen där det går. Google debiterar per
sökfråga ($14/1 000 efter gratiskvoten), och den kostnaden syntes inte i
`enrichment_cost_usd`: 5–9 nov 2026 kostade berikningen 200–250 kr/dygn mot
~20 kr loggat, och projektets spendtak slog i. Libris är gratis och kräver
ingen nyckel.

Texterna kommer sällan från bokens egen post. De kommer från andra poster
för samma verk: MTM:s talböcker (som anger originalets ISBN i en anmärkning,
"Anpassad från: … ISBN 978…") och förlagstexter från Bokinfo (slutar på
"[Bokinfo]"). Därför två sökningar: först ISBN, sedan titel + författarens
efternamn med krav på att båda stämmer.

Test 2026-11-10: text för 21 av 40 böcker som Google grundat väl (16 av 20
svenska böcker) och för 15 av 40 ur omberikningskön.
"""

from __future__ import annotations

import json
import re
import time
import unicodedata

import requests

LIBRIS_URL = "https://libris.kb.se/find.jsonld"
# Generisk klient: inga uppgifter om kommunen eller användaren i anropen.
HEADERS = {"User-Agent": "bok-berikning/1.0 (python-requests)", "Accept": "application/ld+json"}
MAX_TEXTER = 4
MAX_LANGD = 1500
MIN_LANGD = 60
PAUS = 0.3  # sekunder mellan anrop — artigt mot ett fritt API

# Bokinfo-poster för läromedel innehåller ibland säljtext om klassuppsättningar
# ("Paketrabatt 20% Detta paket innehåller 90 exemplar av …") i stället för en
# beskrivning av boken.
SKRAP = re.compile(r"paketrabatt|detta paket inneh|klassuppsättning|\blicens(en)? (gäller|för)", re.I)


def _ord(s: str) -> set[str]:
    s = unicodedata.normalize("NFKD", s or "").lower()
    s = "".join(c for c in s if not unicodedata.combining(c))
    return set(re.sub(r"[^a-z0-9 ]", " ", s).split())


def _isbn(bok: dict) -> str | None:
    m = re.search(r"[0-9]{9,12}[0-9X]", bok.get("isbn_clean") or bok.get("isbn") or "")
    return m.group(0) if m else None


def _efternamn(bok: dict) -> str | None:
    namn = (bok.get("author") or "").split(",")[0].strip()
    return namn or None


def _huvudtitel(post: dict) -> str:
    for t in post.get("hasTitle") or []:
        if isinstance(t, dict) and t.get("mainTitle"):
            return str(t["mainTitle"])
    return ""


def _sammanfattningar(post: dict) -> list[str]:
    """`summary` kan ligga på instansen eller verket; `label` är sträng eller lista."""
    ut = []
    for nod in (post, post.get("instanceOf") or {}):
        for s in nod.get("summary") or []:
            if not isinstance(s, dict):
                continue
            label = s.get("label") or []
            for text in ([label] if isinstance(label, str) else label):
                if isinstance(text, str):
                    ut.append(text.strip())
    return ut


def _sok(q: str) -> list[dict]:
    r = requests.get(LIBRIS_URL, params={"q": q, "_limit": 20}, headers=HEADERS, timeout=20)
    r.raise_for_status()
    time.sleep(PAUS)
    return [p for p in r.json().get("items", []) if p.get("@type") != "Item"]


def hamta_texter(bok: dict) -> list[dict] | None:
    """Upp till MAX_TEXTER texter som [{"text", "uri", "title"}].

    Tom lista = Libris har inget om boken. None = Libris gick inte att nå;
    anroparen ska då inte dra slutsatsen att boken saknar text.
    """
    isbn, efternamn, titel = _isbn(bok), _efternamn(bok), (bok.get("title") or "").strip()
    texter: list[dict] = []
    sedda: set[str] = set()

    def ta(poster: list[dict], villkor) -> None:
        for post in poster:
            if not villkor(post):
                continue
            for text in _sammanfattningar(post):
                nyckel = text[:200]
                if len(text) < MIN_LANGD or SKRAP.search(text) or nyckel in sedda:
                    continue
                sedda.add(nyckel)
                kalla = "Bokinfo via Libris" if text.endswith("[Bokinfo]") else "Libris"
                texter.append({
                    "text": text.removesuffix("[Bokinfo]").strip()[:MAX_LANGD],
                    "uri": (post.get("@id") or "").removesuffix("#it"),
                    "title": kalla,
                })

    try:
        if isbn:
            ta(_sok(isbn), lambda p: isbn in json.dumps(p))
        # Titel + efternamn. Utan författare (antologier, tidningar, pussel)
        # blir titelsökningen för lös — "Halland" eller "Bamse" matchar allt.
        titelord, namnord = _ord(titel), _ord(efternamn or "")
        if titelord and namnord and len(texter) < MAX_TEXTER:

            def samma_verk(p: dict) -> bool:
                medverkande = json.dumps(
                    (p.get("instanceOf") or {}).get("contribution", []), ensure_ascii=False)
                return titelord <= _ord(_huvudtitel(p)) and namnord <= _ord(medverkande)

            ta(_sok(f"{titel} {efternamn}"), samma_verk)
    except (requests.RequestException, ValueError):
        return None
    return texter[:MAX_TEXTER]
