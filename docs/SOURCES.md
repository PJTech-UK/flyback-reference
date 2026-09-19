# Sources, and which edition said what

## Two editions of The Book

| file | size | what it is |
|---|---|---|
| `diemen.v12` | 30.6 MB | the 2003 edition this project started from |
| `diemen-updated.v12` | 42.0 MB | a later edition, from an update package applied to an installed copy |

### Dating the later edition

**Its timestamp is not its date.** The file records
`creation-date: Fri Mar 22 03:30:25 2024`, but a V12 database is rebuilt when
the updater runs and stamped with that moment. This copy was assembled in 2024
from an update package obtained second-hand; the package itself is undated and
was already old. The 2003 file is honestly dated — `creation-date: Tue Feb 18
10:49:26 2003` — because it is the original press.

What actually dates the content:

- It carries an **LCD backlight inverter range** (`HRT I04L…`, 28 codes, 11,557
  references) that is entirely absent from the 2003 file. LCD television repair
  is a market from roughly 2005.
- Those inverters are listed against panels from **LG-Philips LCD** and
  **CMO-Chi Mei Optronics**. LG.Philips LCD was renamed LG Display in 2008;
  Chi Mei Optoelectronics was folded into Innolux in 2010. Both names are
  current in the data.
- A set is listed as `TOSHIBA 2100 TDT` — Spain's digital terrestrial rollout,
  from 2005.

So: **later than 2003, and most likely around 2007 — no later than 2008.** The
code prefixes `I04L`, `I16L`, `I20L` look like years but are not; they are CCFL
lamp counts, which is why they run 02, 03, 04, 06, 08 … 28.

The build tags this edition `updated` rather than with a year, because a guess
in a data column gets read as a fact.

Both are V12 Database Engine files and both are read by the same extractors.
Both are V12 Database Engine files and the same extractors read both.
`HR_SOURCE` selects which, defaulting to the 2003 file so nothing changes by
accident:

```bash
HR_SOURCE=diemen-updated.v12 python3 extract/extract_tables.py
```

What the newer edition adds:

| table | 2003 | later edition | |
|---|---|---|---|
| manufacturer names | 1,338 | 1,632 | +294 |
| part rows (type, family, kV, pins, box) | 3,331 | 4,190 | +859 |
| fitment rows | 118,122 | 146,492 | +28,370 |
| remarks | 1,031 | 1,131 | +100 |
| accessories | 516 | 511 | −5 |
| HR codes referenced | 3,805 | 4,643 | +838 |

The later edition names every code this project had recovered by hand — `PHX`
PHOENIX, `MEG` MEGATRON, `ERM` EUROMAN, `BRE` BRANDT, `CHH` CHANGHONG — which
is a useful check on the method that recovered them, and it names 290 more.

## Nothing is replaced

Each source is loaded and tagged, never overwritten by whichever ran last:

| src | source |
|---|---|
| `NULL` | 2003 database |
| `updated` | the later database |
| `xref` | the 2011 catalogue PDF |
| `classic` | inferred via a Classic FBT correspondence |
| `community` | contributed |

Equivalents are written one CSV per edition — `equivalents.csv`,
`equivalents-updated.csv` — and the build loads both. Writing one file that each
edition overwrote in turn cost 1,294 pairs the first time it was tried, and a column renamed from `oem` to `equivale` caused the entire later table to be
dropped in silence, so the loader now fails loudly on an unexpected header.

## The 2012 website is still needed

The defunct hrdiemen.com scrape overlaps the later Book heavily, as you would expect — but not completely:

| | triples (make, model, part) |
|---|---|
| website | 18,345 |
| corroborated by the later Book | 12,384 — **67.5%** |
| **website only** | **5,961** |

Two thirds of the scrape is now confirmed by the manufacturer's own later edition, which is a strong independent check on the scrape. The remaining third
exists nowhere else we hold. Dropping the scrape would lose it.

## Not in the repository

`diemen.v12`, `diemen-updated.v12` and `!INSTALLED_WINDOWS_FILES/` are licensed
source material and are gitignored. Only the extracted cross-reference is
published. Note that a leading `!` in `.gitignore` means *negate*, so that
directory is listed escaped as `\!INSTALLED_WINDOWS_FILES/`; without the
backslash the rule un-ignores it.

The update package ships per-table files — `FABRICAN.CDN`, `HR.CDN`,
`UTILITZA.CDN`, `EQUIVALE.CDN`, `OBSEHR.CDN`, `ACCEHR.CDN` — but they are
encrypted, so the extractors read the assembled `.v12` instead.
