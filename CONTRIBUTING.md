# Contributing

Corrections are welcome. Part of this data was read by machine from
twenty-year-old scans and contains errors.

## Response times

This archive is maintained by one person outside working hours. Issues and pull
requests may take weeks or months to be looked at. No inference should be drawn
from a slow response.

Two practical consequences:

1. **Make every issue self-contained.** Assume no follow-up questions will be
   asked. An issue that can be actioned in a single pass, months later, by
   someone with no recollection of it is far more likely to be resolved than one
   that requires a discussion first.
2. **A pull request is more likely to be merged than an issue is to be
   actioned.** If you can make the change yourself, please do.

If an item has been open for a long time and still matters to you, a single
reminder on the thread is fine.

## What is most useful

In rough order:

1. **Bench measurements.** Inductance, DC resistance, and measured rail voltages
   in a known chassis. None of these values were published by the original
   sources, and they are what determine whether a substitute will work. State
   the part, the pins, and the measurement method.
2. **Corrections to extracted figures**, with the evidence — a photo of the part,
   a clearer scan, a page from a service manual.
3. **Codes that are missing entirely**, with a source.
4. **Substitution results**, positive or negative, with any modifications
   required. Negative results are equally useful and less often reported.

## Where to change things

Do **not** edit `database/database.sqlite` — it is a build artefact, is not
committed, and is regenerated from source on every build.

| you want to fix | edit |
|---|---|
| a cross-reference pair | `dataset/equivalents.csv` |
| a part's descriptors / tester data | `dataset/hr.json` |
| the TV/monitor fitment list | `dataset/hr_to_uses.json` |
| pin functions | `dataset/datapin_pins.csv` |
| tripler resistor values | `dataset/hrt_resistors_overrides.json` |
| anything in the interface | `` |
| a set with **no** HR equivalent | `dataset/orphan_parts.csv` |
| a fitment the maker never listed | `dataset/community_uses.csv` |

Run `php bin/build-db.php` and check the result in the application
before opening a pull request. There is no test suite: the build reporting its
expected record counts, and the page rendering correctly, is the check.

Where a value is machine-extracted, add an override rather than editing the
extracted file. An override survives a re-extraction; a direct edit does not.

## Sets with no HR part

Anything older than the HR Diemen range — roughly pre-1980 — will never have an
HR equivalent, and until now there was nowhere to record it: every table keyed on
an HR code. `dataset/orphan_parts.csv` holds those:

```csv
part,part_make,fabname,model,source,note
1132-016,THORN,PYE,697,"Trader sheet 1247",
```

`part` is the transformer's own number, `part_make` whoever made it, `fabname`
and `model` the set. `source` is required — say where it came from, because
everything in that file is contributed and is displayed as such. The search
finds these and lists them separately, under "Identified, but no HR replacement
recorded": naming the original part is still the answer to the question somebody
asked, and it is what they then go looking for.

## Mining old magazines

`extract/mine_magazines.py` reads a local collection of scanned magazines
(*Television*, *Practical Television* and the like) and reports places where a
part number sits near a make and model, in text that is about line output
transformers.

```bash
python3 extract/mine_magazines.py --pdfs ~/television-magazines
python3 extract/mine_magazines.py --pdfs ~/television-magazines --status
```

It writes candidates to `extract/magazine_candidates.csv` and **nothing else**.
Each row carries the file, page, the surrounding text, whether the code is
already known, and how far apart the make and the part were — sort by that last
column and the confident pairings are at the top. Magazine OCR is noisy and a
part number that looks plausible and is wrong is worse than none, so read them,
keep what is real, and paste it into `orphan_parts.csv` or `community_uses.csv`
yourself.

Neither the extracted text nor the candidate file is committed.

**Do not point it at worldradiohistory.com.** Their robots.txt blocks ClaudeBot
and the other AI crawlers by name, sets `ai-train=no`, and expressly reserves
rights under Article 4 of the EU copyright directive; the site returns 403 to
declared bots. Use your own copies.

## Style

Match the existing code and documentation. One convention is worth stating
explicitly: every record states its source and its reliability, and inferred
data is never presented as a manufacturer claim. Data of a different quality
should be tagged accordingly rather than merged into existing records.

## What will not be accepted

- Removing source attribution, or presenting inferred links as manufacturer
  claims.
- Bulk-imported data of unknown provenance.
- Anything that implies this project is affiliated with, or speaks for, any
  manufacturer. See `DATA.md`.
