#!/usr/bin/env python3
"""Injects data/seed.json into web/app.html and writes the outputs.

  dist/artifact.html                      fragment for hosted preview
  web/index.html                          full page for any web host
  android/app/src/main/assets/www/index.html   page bundled in the APK
  backend/data/seed.json                  starting reports for the database
"""
import json
import pathlib
import shutil

ROOT = pathlib.Path(__file__).parent
seed = json.loads((ROOT / "data/seed.json").read_text(encoding="utf-8"))
# "<" is escaped so report text can never close the script tag.
payload = json.dumps(seed, ensure_ascii=False, separators=(",", ":")).replace("<", "\\u003c")
page = (ROOT / "web/app.html").read_text(encoding="utf-8").replace("/*SEED*/", payload)
assert "/*SEED*/" not in page

full = (
    '<!doctype html>\n<html lang="en"><head><meta charset="utf-8">'
    '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
    '<meta name="theme-color" content="#0a56b0">\n'
    + page.replace("<style>", "</head><body>\n<style>", 1)
    + "\n</body></html>\n"
)
# Keep the <title>, meta, links and first stylesheet valid inside the document.
full = full.replace("</head><body>\n<style>", "<style>", 1).replace("</style>\n", "</style>\n</head><body>\n", 1)

(ROOT / "dist").mkdir(exist_ok=True)
(ROOT / "dist/artifact.html").write_text(page, encoding="utf-8")
(ROOT / "web/index.html").write_text(full, encoding="utf-8")
assets = ROOT / "android/app/src/main/assets/www"
assets.mkdir(parents=True, exist_ok=True)
(assets / "index.html").write_text(full, encoding="utf-8")
(ROOT / "backend/data").mkdir(parents=True, exist_ok=True)
shutil.copy(ROOT / "data/seed.json", ROOT / "backend/data/seed.json")
print("built", len(seed["items"]), "reports,", len(seed["tips"]), "guides")
