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

Run `php bin/build-db.php` and check the result in the application
before opening a pull request. There is no test suite: the build reporting its
expected record counts, and the page rendering correctly, is the check.

Where a value is machine-extracted, add an override rather than editing the
extracted file. An override survives a re-extraction; a direct edit does not.

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
