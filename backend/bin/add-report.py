#!/usr/bin/env python3
"""Adds one hand-entered report to data/seed.json. Run by the Add a report workflow."""
import datetime
import hashlib
import json
import os
import pathlib
import re
import sys

MANILA = datetime.timezone(datetime.timedelta(hours=8))
TOWNS = {"Alburquerque", "Alicia", "Anda", "Antequera", "Baclayon", "Balilihan", "Batuan", "Bien Unido", "Bilar", "Buenavista", "Calape", "Candijay", "Carmen", "Catigbian", "Clarin", "Corella", "Cortes", "Dagohoy", "Danao", "Dauis", "Dimiao", "Duero", "Garcia Hernandez", "Getafe", "Guindulman", "Inabanga", "Jagna", "Lila", "Loay", "Loboc", "Loon", "Mabini", "Maribojoc", "Panglao", "Pilar", "Pres. Carlos P. Garcia", "Sagbayan", "San Isidro", "San Miguel", "Sevilla", "Sierra Bullones", "Sikatuna", "Tagbilaran City", "Talibon", "Trinidad", "Tubigon", "Ubay", "Valencia"}


def env(name):
    return os.environ.get(name, "").strip()


url = env("POST_URL")
if not re.fullmatch(r"https://\S+", url):
    sys.exit("The post link must start with https://")
title, summary = env("TITLE"), env("SUMMARY")
if not title or not summary:
    sys.exit("Headline and summary are required.")
named = [t.strip() for t in env("TOWNS").split(",") if t.strip()]
unknown = [t for t in named if t not in TOWNS]
if unknown:
    sys.exit("Not a Bohol town name: " + ", ".join(unknown))
image = env("IMAGE_URL")
if image and not re.fullmatch(r"https://\S+", image):
    sys.exit("The image link must start with https://")

path = pathlib.Path(__file__).resolve().parents[2] / "data" / "seed.json"
data = json.loads(path.read_text(encoding="utf-8"))
if any(i["source"]["url"].rstrip("/") == url.rstrip("/") for i in data["items"]):
    sys.exit("That post has already been added.")
alert = None if env("ALERT") == "none" else env("ALERT")
item = {
    "id": "manual-" + hashlib.sha256(url.encode()).hexdigest()[:16],
    "title": title,
    "date": datetime.datetime.now(MANILA).strftime("%Y-%m-%d"),
    "category": env("CATEGORY") or "services",
    "trust": "official" if env("OFFICIAL") == "true" else "news",
    "alert": alert,
    "scope": "municipal" if named else "province",
    "municipalities": named,
    "summary": summary,
    "facts": [],
    "source": {"name": env("SOURCE_NAME") or "Facebook", "url": url},
    "notify": True,
}
if image:
    item["image"] = image
if env("GOOD_NEWS") == "true":
    item["tone"] = "good"
data["items"].insert(0, item)
path.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
print("Added", item["id"])
