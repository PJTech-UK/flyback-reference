# Flyback, LOPT & Tripler Cross-Reference Database

Cross-reference and technical data for HR Diemen line-output transformers
(flybacks / LOPTs), triplers and related EHT components for CRT televisions and
monitors, supplemented with cross-reference data from other historic parts
catalogues and from documents circulated by the repair community.

The catalogues carrying this information are out of print or offline, and the
same transformer is commonly listed under several different part numbers.

Roughly 9,000 transformer parts, 48,000 manufacturer part numbers and 98,000 TV
and monitor models.

## The data

`dataset/` is plain CSV and JSON and is the point of this repository. It is meant
to be read by other things.

| file | contents |
|---|---|
| `equivalents.csv` | OEM part number → HR code, with source tag |
| `hr.json` | part descriptors: tester type, family, anode rating, pins, weight |
| `hr_to_uses.json` | TV and monitor models each part was fitted to |
| `hr_to_schematic.json` | schematic image per part |
| `schematic_extracts.json` | windings, tap voltages, polarities and roles, read from the drawings |
| `datapin_pins.csv` | functional pin labels (B+, ABL, heater, AFC …) |
| `hrt_resistors.json` | tripler bleeder resistor values |
| `xref_pdf.csv` | the 2011 equivalence catalogue, parsed |
| `classic_oem.csv` | a second manufacturer's catalogue |
| `catalogue_presence.json` | which codes the manufacturer listed but published no data for |

`data/` holds the schematic drawings and the family, packaging and pin-out
images referenced from those files.

Every record carries a source tag. A published cross-reference is a statement
that a manufacturer sold one part for another's application; it is not a
measurement, and it is not presented as one. See `DATA.md` for provenance and
rights, and `docs/` for how each source was parsed and where it is weak.

## The application

Searches by part number, by the set a part was fitted to, or by electrical
characteristics, combined with `AND`, `OR`, `NOT` and parentheses. Where a part
is unobtainable it lists others of a similar design, with the differences.

```bash
php bin/build-db.php
php -S localhost:8000 -t public public/index.php
```

PHP 8.1+ with `pdo_sqlite`. No database server, no dependencies. `composer
install` runs the same build via a `post-install-cmd` hook, so a host's standard
deploy script builds the site without a custom one.

`database/database.sqlite` is a build artefact, regenerated from `dataset/` in
about three seconds, and is not committed.

### Hosting

Point the document root at `public/`. `public/data` is a symlink to `data/`, so
the images are served directly by the web server.

The application is read-only: the SQLite connection is opened `PRAGMA
query_only`, nothing writes to disk, and there is no login, upload or admin path.
Errors are logged rather than returned; set `APP_DEBUG=1` to see them in the
response.

### API

| endpoint | returns |
|---|---|
| `GET /api/catalog` | search factors and dataset counts |
| `GET /api/search?q=&page=&sort=&onlyImgs=&uses=` | `{total, page, pages, results[]}` |
| `GET /api/hr/{code}` | one hydrated record |

`q` takes the full boolean grammar. Part numbers match with punctuation ignored.
Each result carries `subs`, the substitute shortlist.

## Documentation

| file | covers |
|---|---|
| `DATA.md` | provenance, attribution and rights |
| `docs/SUBSTITUTES.md` | how substitutes are matched, and the limits |
| `docs/SEARCH.md` | the query grammar |
| `docs/OEM_CODES.md` | part-number normalisation |
| `docs/XREF_INGEST.md` | the 2011 equivalence catalogue |
| `docs/CLASSIC_BRIDGE.md` | the second manufacturer's catalogue |
| `docs/DATAPIN.md` | functional pin-outs |
| `docs/MANUAL_HUNT.md` | service-manual leads |

## Limitations

Inductance, DC resistance and inter-winding capacitance determine whether a
flyback will work in a given circuit. None of those values are in this dataset.
Substitute suggestions are search results, not recommendations.

Part of the data was read by machine from scanned drawings and contains
recognition errors.

## Contributing

Corrections are welcome, particularly bench measurements. See `CONTRIBUTING.md`.

## Licence

Code: MIT (`LICENSE`). The reference data did not originate with this project and
is not licensed here; `DATA.md` sets out the provenance and how to request
removal of specific material.

**Not affiliated with, endorsed by, or connected to Efiter S.L. or HR Diemen.**
