#!/usr/bin/env python3
"""
Omberikning av berikningar som saknar eller har tveksamma källor — mot Libris.

Bakgrund: fram till 2026-11-03 fick Gemini bara titel, författare och ISBN.
För titlar utan webbträffar gissade modellen utifrån titelorden (biblio 69716,
en målares självbiografi, blev en bok om textilkonst). Den nya prompten
(`bygg_prompt()` i enrich_from_directus.py) bär katalogdatan som fakta.

Urval: obehandlade rader med 0 källor (modellens gissning), sedan exakt 1
källa (tveksamt — ofta Wikipedia/blogg om en annan person).

Sedan 2026-11-10 används BARA Libris (libris.py), aldrig Google Search.
Google debiterar per sökfråga och kostade 200–250 kr/dygn för den här kön
(se modell.py). Med Google grundades ~88 % av raderna; Libris har text för
~40 %. Därför:
  - Libris-text finns  → ny beskrivning ur den, raden PATCH:as (behandlad).
  - Ingen Libris-text  → raden lämnas ORÖRD. Ingen beskrivning töms, eftersom
    Google troligen hade hittat något; biblio_id skrivs i MISSFIL så att
    körningen går vidare i kön, och provas igen efter OMPROVA_DAGAR.
  - Libris onåbart     → räknas som fel, inget skrivs.
Vad som ska hända med raderna utan Libris-text är ett öppet beslut.

Rader med `date_updated` satt hoppas över. Pipelinen skapar rader med POST
(date_updated = null), så ett satt värde betyder manuell rättelse eller att
raden redan behandlats av det här skriptet.

--limit räknar Libris-uppslag (gratis); Gemini anropas bara vid träff.

Usage:
    uv run reenrich_ungrounded.py --limit 1000
    uv run reenrich_ungrounded.py --dry-run --limit 5
"""

import argparse
import json
import os
import sys
import time
from datetime import date, timedelta
from pathlib import Path

import requests
from dotenv import load_dotenv
from google import genai

import libris
from enrich_from_directus import KATALOGFALT, DirectusClient, beskrivningsfalt, enrich_book
from modell import DEFAULT_MODEL

# Rader där Libris saknade text: {biblio_id: "ÅÅÅÅ-MM-DD"}. Ligger bredvid
# skriptet på servern (gitignorerad). Försvinner filen provas raderna om.
MISSFIL = Path(__file__).parent / ".libris_missar.json"
OMPROVA_DAGAR = 90


def las_missar() -> dict[str, str]:
    try:
        return json.loads(MISSFIL.read_text())
    except (OSError, ValueError):
        return {}


def nyligen_missad(missar: dict[str, str], biblio_id: int) -> bool:
    datum = missar.get(str(biblio_id))
    if not datum:
        return False
    try:
        return date.fromisoformat(datum) > date.today() - timedelta(days=OMPROVA_DAGAR)
    except ValueError:
        return False


def antal_kallor(varde) -> int:
    """grounding_sources ligger som JSON-text i Directus (ibland redan lista)."""
    if not varde:
        return 0
    if isinstance(varde, str):
        try:
            varde = json.loads(varde)
        except ValueError:
            return 0
    return len(varde) if isinstance(varde, list) else 0


def hamta_kandidater(d: DirectusClient) -> list[dict]:
    """Alla obehandlade rader med 0 eller 1 källa, 0 källor först."""
    r = requests.get(
        f"{d.api_url}/items/kft_koha_enriched",
        headers=d.headers,
        params={
            "fields": "id,biblio_id,grounding_sources,enrichment_cost_usd",
            "filter[date_updated][_null]": "true",
            "sort": "-biblio_id",
            "limit": -1,
        },
        timeout=120,
    )
    r.raise_for_status()
    rader = []
    for rad in r.json().get("data", []):
        n = antal_kallor(rad.get("grounding_sources"))
        if n <= 1 and rad.get("biblio_id"):
            rad["kallor"] = n
            rader.append(rad)
    rader.sort(key=lambda x: x["kallor"])  # stabil: -biblio_id inom varje grupp
    return rader


def hamta_katalogdata(d: DirectusClient, biblio_ids: list[int]) -> dict[int, dict]:
    ut: dict[int, dict] = {}
    for i in range(0, len(biblio_ids), 100):
        del_ids = biblio_ids[i:i + 100]
        r = requests.get(
            f"{d.api_url}/items/kft_koha_biblios",
            headers=d.headers,
            params={
                "fields": KATALOGFALT,
                "filter[biblio_id][_in]": ",".join(map(str, del_ids)),
                "filter[status][_eq]": "active",
                "limit": -1,
            },
            timeout=60,
        )
        r.raise_for_status()
        for b in r.json().get("data", []):
            ut.setdefault(b["biblio_id"], b)
    return ut


def patcha(d: DirectusClient, rad_id: int, data: dict) -> bool:
    try:
        r = requests.patch(f"{d.api_url}/items/kft_koha_enriched/{rad_id}",
                           headers=d.headers, json=data, timeout=30)
        r.raise_for_status()
        return True
    except Exception as e:
        print(f"      ✗ PATCH misslyckades för rad {rad_id}: {e}", file=sys.stderr)
        return False


def main() -> int:
    p = argparse.ArgumentParser(description="Omberika berikningar utan eller med en källa.")
    p.add_argument("--limit", type=int, default=1000)
    p.add_argument("--model", default=DEFAULT_MODEL)
    p.add_argument("--delay", type=float, default=1.0)
    p.add_argument("--dry-run", action="store_true")
    args = p.parse_args()

    load_dotenv(Path(__file__).parent.parent / ".env")
    d = DirectusClient(os.environ["DIRECTUS_API_URL"], os.environ["DIRECTUS_API_TOKEN"])
    g = genai.Client(api_key=os.environ["GEMINI_API_KEY"])

    kandidater = hamta_kandidater(d)
    missar = las_missar()
    kvar0 = sum(1 for k in kandidater if k["kallor"] == 0)
    vantande = [k for k in kandidater if not nyligen_missad(missar, k["biblio_id"])]
    print(f"Kvar att omberika: {len(kandidater)} ({kvar0} utan källor, "
          f"{len(kandidater) - kvar0} med en källa), varav {len(kandidater) - len(vantande)} "
          f"utan Libris-text senaste {OMPROVA_DAGAR} dagarna")
    urval = vantande[:args.limit]
    if not urval:
        print("Inget kvar att omberika.")
        return 0

    katalog = hamta_katalogdata(d, [k["biblio_id"] for k in urval])

    ersatta = utan_text = fel = kvot = markerade = 0
    kostnad_tot = 0.0
    try:
        for i, rad in enumerate(urval, 1):
            bok = katalog.get(rad["biblio_id"])
            if not bok:
                # Inaktiv i katalogen — markera som behandlad så den inte väljs igen.
                if not args.dry_run and patcha(d, rad["id"], {"enrichment_cost_usd": rad.get("enrichment_cost_usd") or 0}):
                    markerade += 1
                continue

            kalltexter = libris.hamta_texter(bok)
            if kalltexter is None:
                print(f"[{i}/{len(urval)}] biblio_id {rad['biblio_id']}: Libris onåbart")
                fel += 1
                continue
            if not kalltexter:
                utan_text += 1
                missar[str(rad["biblio_id"])] = date.today().isoformat()
                continue

            print(f"[{i}/{len(urval)}] biblio_id {rad['biblio_id']} ({rad['kallor']} källa/or, "
                  f"{len(kalltexter)} Libris-text/er): {bok.get('title')}")
            enr, gr, cost, kvotfel = enrich_book(g, args.model, bok, kalltexter=kalltexter)
            kostnad_tot += cost
            if not enr:
                fel += 1
                kvot += kvotfel
                if kvotfel:
                    print("      ⚠  Gemini-kvoten slut — avbryter, resten tas nästa körning")
                    break
                continue

            data = {
                **beskrivningsfalt(enr, gr),
                "grounding_search_queries": [],
                "grounding_sources": gr["sources"],
                "enrichment_cost_usd": (rad.get("enrichment_cost_usd") or 0) + cost,
            }
            print(f"      ersatt: {enr.abstract_enriched[:90]}...")
            ersatta += 1
            if not args.dry_run:
                if patcha(d, rad["id"], data):
                    markerade += 1
                else:
                    fel += 1

            if args.delay > 0:
                time.sleep(args.delay)
    finally:
        # Sparas även vid avbrott, så att uppslagen inte görs om i onödan.
        if not args.dry_run:
            MISSFIL.write_text(json.dumps(missar, indent=0, sort_keys=True))

    print("=" * 60)
    print(f"Omberikade ur Libris (ersatta): {ersatta}")
    print(f"Utan Libris-text (orörda, provas igen om {OMPROVA_DAGAR} dagar): {utan_text}")
    print(f"Errors: {fel}" + (f" (varav kvot/429: {kvot})" if kvot else ""))
    print(f"Total cost: ${kostnad_tot:.4f} USD (ingen sökavgift — Libris)")
    print(f"Kvar efter körningen: {len(vantande) - markerade - utan_text} att slå upp, "
          f"{len(kandidater) - markerade} totalt")

    # Fel på en enstaka bok ska inte larma; systematiska fel ska det.
    behandlade = ersatta + utan_text + fel
    return 1 if behandlade and fel / behandlade > 0.2 else 0


if __name__ == "__main__":
    sys.exit(main())
