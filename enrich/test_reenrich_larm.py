#!/usr/bin/env python3
"""Tester för omberikningens larmregel, felparkering och tomma Gemini-svar.

Inga nätverksanrop. Kör:
    uv run python -m unittest test_reenrich_larm -v
"""

import unittest
from datetime import date, timedelta
from types import SimpleNamespace

import reenrich_ungrounded as ru
from enrich_from_directus import svarstext


class Larmkod(unittest.TestCase):
    def test_en_enstaka_bok_larmar_inte_nar_kon_ar_nastan_tom(self):
        # 2026-11-20: 1 Gemini-fel + 1 utan Libris-text = 50 % fel, röd check.
        self.assertEqual(ru.larmkod(behandlade=2, fel=1, kvot=0), 0)
        self.assertEqual(ru.larmkod(behandlade=1, fel=1, kvot=0), 0)
        self.assertEqual(ru.larmkod(behandlade=2, fel=2, kvot=0), 0)

    def test_systematiska_fel_larmar(self):
        self.assertEqual(ru.larmkod(behandlade=3, fel=3, kvot=0), 1)
        self.assertEqual(ru.larmkod(behandlade=10, fel=3, kvot=0), 1)

    def test_manga_fel_men_lag_andel_larmar_inte(self):
        self.assertEqual(ru.larmkod(behandlade=400, fel=5, kvot=0), 0)

    def test_kvotfel_larmar_alltid(self):
        # Körningen avbryts vid första kvotfelet, så fel blir aldrig fler än 1.
        self.assertEqual(ru.larmkod(behandlade=1, fel=1, kvot=1), 1)

    def test_inget_behandlat(self):
        self.assertEqual(ru.larmkod(behandlade=0, fel=0, kvot=0), 0)


class Parkering(unittest.TestCase):
    def test_parkerad_bok_hoppas_over_tills_omprovstiden_gatt(self):
        i_dag = date.today()
        fel = {
            "58621": i_dag.isoformat(),
            "1": (i_dag - timedelta(days=ru.OMPROVA_DAGAR + 1)).isoformat(),
        }
        self.assertTrue(ru.nyligen_missad(fel, 58621))
        self.assertFalse(ru.nyligen_missad(fel, 1))
        self.assertFalse(ru.nyligen_missad(fel, 2))


class Svarstext(unittest.TestCase):
    def test_text_returneras(self):
        self.assertEqual(svarstext(SimpleNamespace(text='{"a": 1}')), '{"a": 1}')

    def test_tomt_svar_ger_fel_med_orsak(self):
        svar = SimpleNamespace(
            text=None,
            candidates=[SimpleNamespace(finish_reason="SAFETY")],
            prompt_feedback=None,
        )
        with self.assertRaisesRegex(RuntimeError, "finish_reason=SAFETY"):
            svarstext(svar)

    def test_tomt_svar_utan_kandidater_visar_block_reason(self):
        svar = SimpleNamespace(
            text=None,
            candidates=None,
            prompt_feedback=SimpleNamespace(block_reason="PROHIBITED_CONTENT"),
        )
        with self.assertRaisesRegex(RuntimeError, "block_reason=PROHIBITED_CONTENT"):
            svarstext(svar)


if __name__ == "__main__":
    unittest.main()
