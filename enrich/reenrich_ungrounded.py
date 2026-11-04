#!/usr/bin/env python3
"""
Omberikning av berikningar som saknar eller har tveksamma källor.

Bakgrund: fram till 2026-11-03 fick Gemini bara titel, författare och ISBN.
För titlar utan webbträffar gissade modellen utifrån titelorden (biblio 69716,
en målares självbiografi, blev en bok om textilkonst). Den nya prompten
(`bygg_prompt()` i enrich_from_directus.py) bär katalogdatan som fakta.

Urval, i prioritetsordning:
  1. Inga källor (grounding_sources tom) — svaret är modellens gissning.
     Får det nya svaret källor ersätts texten; annars töms beskrivningen.
  2. Exakt en källa — tveksamt (ofta Wikipedia/blogg/YouTube om en annan
     person). Ersätts bara om det nya svaret har minst en källa; annars
     behålls den befintliga texten.

Rader med `date_updated` satt hoppas över. Pipelinen skapar rader med POST
(date_updated = null), så ett satt värde betyder manuell rättelse eller att
raden redan behandlats av det här skriptet — varje behandlad rad PATCH:as
(minst kostnaden), så den väljs aldrig igen och körningen tar slut av sig själv.

Körs dagligen från sync_cron.sh med --limit för att hålla sig under Googles
grundningstak (1 500 anrop/dygn för Gemini 3, delat med nattens berikning).

Usage:
    uv run reenrich_ungrounded.py --limit 400
    uv run reenrich_ungrounded.py --dry-run --limit 5
"""

import argparse
import json
import os
import sys
import time
from pathlib import Path

import requests
from dotenv import load_dotenv
from google import genai

from enrich_from_directus import KATALOGFALT, DirectusClient, beskrivningsfalt, enrich_book
from modell import DEFAULT_MODEL, ar_grundad


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
    p.add_argument("--limit", type=int, default=400)
    p.add_argument("--model", default=DEFAULT_MODEL)
    p.add_argument("--delay", type=float, default=1.0)
    p.add_argument("--dry-run", action="store_true")
    args = p.parse_args()

    load_dotenv(Path(__file__).parent.parent / ".env")
    d = DirectusClient(os.environ["DIRECTUS_API_URL"], os.environ["DIRECTUS_API_TOKEN"])
    g = genai.Client(api_key=os.environ["GEMINI_API_KEY"])

    kandidater = hamta_kandidater(d)
    kvar0 = sum(1 for k in kandidater if k["kallor"] == 0)
    print(f"Kvar att omberika: {len(kandidater)} ({kvar0} utan källor, "
          f"{len(kandidater) - kvar0} med en källa)")
    urval = kandidater[:args.limit]
    if not urval:
        print("Inget kvar att omberika.")
        return 0

    katalog = hamta_katalogdata(d, [k["biblio_id"] for k in urval])

    ersatta = behallna = rensade = fel = kvot = markerade = 0
    kostnad_tot = 0.0
    for i, rad in enumerate(urval, 1):
        bok = katalog.get(rad["biblio_id"])
        if not bok:
            # Inaktiv i katalogen — markera som behandlad så den inte väljs igen.
            if not args.dry_run and patcha(d, rad["id"], {"enrichment_cost_usd": rad.get("enrichment_cost_usd") or 0}):
                markerade += 1
            continue

        print(f"[{i}/{len(urval)}] biblio_id {rad['biblio_id']} ({rad['kallor']} källa/or): {bok.get('title')}")
        enr, gr, cost, kvotfel = enrich_book(g, args.model, bok)
        kostnad_tot += cost
        if not enr:
            fel += 1
            kvot += kvotfel
            if kvotfel:
                print("      ⚠  Gemini-kvoten slut — avbryter, resten tas nästa körning")
                break
            continue

        grundad = ar_grundad(gr)
        total_kostnad = (rad.get("enrichment_cost_usd") or 0) + cost

        if rad["kallor"] == 0 or grundad:
            data = {
                **beskrivningsfalt(enr, gr),
                "grounding_search_queries": gr.get("search_queries", []),
                "grounding_sources": gr.get("sources", []),
                "enrichment_cost_usd": total_kostnad,
            }
            if grundad:
                utfall = f"ersatt: {enr.abstract_enriched[:90]}..."
                ersatta += 1
            else:
                utfall = "rensad (inga källor, beskrivningen borttagen)"
                rensade += 1
        else:
            # Hade en källa, nya svaret har ingen — behåll befintlig text.
            data = {"enrichment_cost_usd": total_kostnad}
            utfall = "behållen (nytt svar saknade källor)"
            behallna += 1

        print(f"      {utfall}")
        if not args.dry_run:
            if patcha(d, rad["id"], data):
                markerade += 1
            else:
                fel += 1

        if i < len(urval) and args.delay > 0:
            time.sleep(args.delay)

    print("=" * 60)
    print(f"Omberikade (ersatta): {ersatta}")
    print(f"Behållna (nytt svar utan källor): {behallna}")
    print(f"Rensade (inga källor, beskrivningen borttagen): {rensade}")
    print(f"Errors: {fel}" + (f" (varav kvot/429: {kvot})" if kvot else ""))
    print(f"Total cost: ${kostnad_tot:.4f} USD (exkl. Google Search-avgift)")
    print(f"Kvar efter körningen: {len(kandidater) - markerade}")

    # Fel på en enstaka bok ska inte larma; systematiska fel ska det.
    behandlade = ersatta + behallna + rensade + fel
    return 1 if behandlade and fel / behandlade > 0.2 else 0


if __name__ == "__main__":
    sys.exit(main())
