"""Modellval, sökinstruktion och priser — delas av alla berikningsskript.

Ett ställe, så att skripten inte kan glida isär. De tidigare prislistorna
låg i fyra kopior och bar kvar sedan länge stängda modeller, med "antagna"
priser som underskattade kostnaden för preview-modellen ~7 gånger.

Priser och modellstatus verifierade 2026-09-28 mot
https://ai.google.dev/gemini-api/docs/pricing och
https://ai.google.dev/gemini-api/docs/deprecations
"""

from __future__ import annotations

import sys
from datetime import date

# Senaste stabila Flash. Den tidigare preview-modellen (Gemini 3 Flash
# Preview) är utfasad; Googles ersättare är gemini-3.6-flash, och 3.8-flash
# är nyare och kostar lika mycket.
DEFAULT_MODEL = "gemini-3.8-flash"

# De stabila modellerna avgör själva om de ska använda Google Search och
# avstår ofta — i test 2026-09-28 sökte gemini-3.8-flash för 1 av 3 böcker.
# Gemini API kan inte tvinga fram sökverktyget, så instruktionen ökar bara
# chansen; svaret kontrolleras efteråt (se `ar_grundad`).
SOK_INSTRUKTION = (
    "Du MÅSTE använda Google Search för att slå upp boken innan du svarar. "
    "Svara aldrig enbart ur minnet; grunda sammanfattningen i sökträffarna."
)

# USD per miljon tokens: (gäller från, in, ut). Utdata inkluderar
# thinking-tokens. Kostnad för själva Google Search-anropen ingår inte.
PRISER: dict[str, list[tuple[date, float, float]]] = {
    "gemini-3.8-flash": [(date(2000, 1, 1), 0.75, 3.75), (date(2027, 1, 1), 1.50, 7.50)],
    "gemini-3.6-flash": [(date(2000, 1, 1), 0.75, 3.75), (date(2027, 1, 1), 1.50, 7.50)],
    "gemini-3.5-flash-lite": [(date(2000, 1, 1), 0.30, 2.50)],
}


def pris(model: str, idag: date | None = None) -> tuple[float, float] | None:
    """(in, ut) per miljon tokens för dagens datum, eller None om okänd modell."""
    idag = idag or date.today()
    steg = [p for p in PRISER.get(model, []) if p[0] <= idag]
    return (steg[-1][1], steg[-1][2]) if steg else None


def kostnad(response, model: str, idag: date | None = None) -> float:
    """Kostnad i USD för ett svar. Okänd modell ger en varning, inte ett
    påhittat pris — en gissning var det som gjorde den gamla summan fel."""
    usage = getattr(response, "usage_metadata", None)
    if not usage:
        return 0.0
    p = pris(model, idag)
    if p is None:
        print(f"  ⚠  Okänt pris för {model} — kostnaden räknas som 0", file=sys.stderr)
        return 0.0
    in_tok = getattr(usage, "prompt_token_count", 0) or 0
    ut_tok = ((getattr(usage, "candidates_token_count", 0) or 0)
              + (getattr(usage, "thoughts_token_count", 0) or 0))
    return in_tok / 1_000_000 * p[0] + ut_tok / 1_000_000 * p[1]


def ar_grundad(grounding: dict) -> bool:
    """Svaret bygger på minst en webbkälla."""
    return bool(grounding.get("sources"))
