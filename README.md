# Flyback, LOPT & Tripler Cross-Reference Database

Cross-reference and technical data for CRT line-output transformers (flybacks /
LOPTs), triplers and related EHT components, with a search application over it.

The manufacturers' catalogues carrying this information are out of print or
offline, and the same transformer is commonly listed under several different part
numbers, which makes identifying one from a number stamped on the part difficult.
This repository holds the collected data and the code to search it.

Roughly 9,000 transformer parts, 48,000 manufacturer part numbers and 98,000 TV
and monitor models. The application reports the current figures from the
database it is running against.

## What it does

- **Search by any part number.** Manufacturer codes, HR codes, or the TV/monitor
  model a part was fitted to. Punctuation is ignored, so `AT2075/30102`,
  `AT-2075-30102` and `at 2075 30102` are the same query.
- **Search by characteristics.** Tester type, deflection angle, anode rating,
  winding count, tap voltages, focus-voltage range and output roles, combined
  with `AND`, `OR`, `NOT` and parentheses.
- **List candidate substitutes.** Parts whose tap profile has the same
  proportions at a comparable scale, in the same line-rate class and deflection
  angle. Each is listed with its pin compatibility, anode rating, missing or
  spare taps, and the implied change in supply voltage.
- **Record provenance.** Every claim carries its source. A published
  cross-reference is a statement that a manufacturer sold one part for another's
  application, not a measurement, and it is not presented as one.

## Limitations

Inductance, DC resistance and inter-winding capacitance determine whether a
flyback will work in a given circuit. None of those values were published by any
of the sources used here, and none are held in this dataset. Substitute
suggestions are search results, not recommendations.

Part of the data was read by machine from scanned drawings and is subject to
recognition errors. Figures derived that way are marked as such in the
interface.

## Running it

Needs PHP 8.1+ with SQLite. Nothing else — no database server, no build step, no
package manager.

```bash
php bin/build-db.php                                  # build the SQLite file
php -S localhost:8000 -t public public/index.php
# open http://localhost:8000
```

`composer install` does the same build, via a `post-install-cmd` hook — there are
no dependencies, the `composer.json` exists so that a host's standard deploy
script builds the database without needing a custom one.

`-t public` is required: it lets the built-in server serve the static
assets directly, while the router serves images from the project's `data/` tree.

The database is a build artefact and is not committed; rebuilding takes about
three seconds and is idempotent. `docs/DEPLOYMENT.md` covers deployment behind a
real web server.

## What is in here

```
dataset/    the data itself — CSV and JSON, the source of truth
data/       schematic drawings, family and packaging images
    the application: build-db.php, a small PHP API, and the front end
docs/       how each part of the dataset was assembled, and what its limits are
```

`dataset/` is plain CSV and JSON and is meant to be read by other things.
Nothing in here is generated at runtime except `database.sqlite`, which is built
from `dataset/` in about three seconds.

**The extraction tooling is not published.** It read a manufacturer's Windows
reference product, a website that no longer exists, and a set of PDFs that are
not ours to redistribute. Those scripts have done their job; keeping them here
would suggest a pipeline other people can run, and they cannot. What survives
the process — the data — is the part with any use left in it. `docs/` records
what each source was and how it was parsed, including the validation figures, so
the result can be judged without the code that produced it.

## Documentation

| file | what it covers |
|---|---|
| `DATA.md` | **Provenance, attribution and rights. Read this first.** |
| `docs/SEARCH.md` | The query grammar, and why the search will not guess at misspellings |
| `docs/OEM_CODES.md` | Part-number normalisation and canonical spellings |
| `docs/SUBSTITUTES.md` | How substitutes are shortlisted, and the limits of doing so |
| `docs/XREF_INGEST.md` | The 2011 equivalence catalogue, and why its parse is trustworthy |
| `docs/CLASSIC_BRIDGE.md` | A second manufacturer's catalogues, and the terms they were admitted on |
| `docs/DATAPIN.md` | Functional pin-outs from community compilations |
| `docs/MANUAL_HUNT.md` | Service-manual leads for parts with no technical data |
| `docs/ANALYTICS.md` | Counting visitors from server logs, without cookies or scripts |
| `docs/DEPLOYMENT.md` | Hosting, branches, releases, and the API |
| `docs/DEPLOYMENT.md` | The application and its API |

## Contributing

Corrections are welcome, particularly bench measurements. See `CONTRIBUTING.md`,
which also sets out how quickly issues and pull requests are likely to be
handled.

## Releases

`VERSION` holds a semver number, shown in the page footer. The `release` branch
is what a server should deploy; `master` is where changes land first. See
`docs/DEPLOYMENT.md`.

## Licence

Code: MIT (`LICENSE`). The reference data did not originate with this project and
is not licensed here; `DATA.md` sets out the provenance, the attribution, and how
to request removal of specific material.

**Not affiliated with, endorsed by, or connected to Efiter S.L. or HR Diemen.**
