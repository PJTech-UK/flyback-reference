# Functional pin-outs and alternative diagrams

```bash
python3 extract/parse_datapin_pdfs.py       # add --no-images to skip rendering
php bin/build-db.php
```

Two community "Data Pin Flyback" compilations fill the biggest hole in the HR
dataset. HR Diemen published a pin map (B+, collector, the ⊥ returns), pulse
amplitudes, and the HV-stack roles — but never a functional label for the
auxiliary pins. ABL, the heater, AFC and boost-up simply are not in their data.
These documents give exactly that, keyed by OEM part number, which the
cross-reference resolves to HR codes.

| source | content |
|---|---|
| `227319312-Data-Pin-Flyback.pdf` | 10pp, text only. 215 codes. |
| `316122862-Data-Pin-Flyback.pdf` | 35pp. 67 entries with a pin table, equivalents, and two drawings each. |

Result: **1,486 pin-function rows across 105 HR parts**, 92 new equivalents, and
**48 alternative diagrams** — 15 of which go to HR codes that had no drawing at
all, including HR 48663.

## Why the diagrams are worth having

Each illustrated entry carries a *bottom-view footprint* ("Tampak Bawah") beside
a *connection schematic* ("Skema Koneksi"). HR's own drawings show the windings
but never the physical pin layout, so the two are complementary rather than
duplicates. Crops are located by `pdftotext -bbox-layout`: the drawing sits
between the "Tampak Bawah" banner and the "No. Pin" table beneath it.

## Provenance

The images carry `beteve.com` / `tecnicosaurios` watermarks. Both sites are
defunct, and the material appears to have been forum-shared rather than
originated there. Watermarks are left intact and the source is recorded per
row so the origin travels with the data.

## Conflicts are shown, not resolved

The two compilations sometimes describe the same part differently. Both are
stored, tagged with the OEM code the pin-out was stated for, and rendered side
by side with a warning. Picking one would be inventing confidence.

For HR 48663 they agree on 6 of 10 pins — B+ on 4, GND on 7, heater on 8, ABL on
9, collector on 2 (written HOUT in one, COL in the other) — and disagree on pin
10 (180 V vs AFC). That is a useful state of knowledge; an averaged single
answer would not be.

## The Vpp-to-DC conversion

The rail voltages let HR's pulse figures be calibrated against real rectified
rails for parts present in both datasets, matched pin-by-pin:

> **DC rail ≈ HR Vpp ÷ 9** (median 9.12, range roughly 8–11)

57 comparable cases, 37 internally consistent to within 15%. Tested against
random pairing within each part (2,000 permutations): null mean 9.3, observed
37, **p < 0.0001** — so it is not an artefact of both sources using standard
voltages.

Limits worth respecting:

- **Low-voltage rails only** (5–60 V). It does *not* hold for the 180–200 V
  rail, which is derived differently.
- One consistent case sits at 2.1 rather than ~9; treat ±10% as the honest band
  and expect outliers.
- This is almost certainly the STVDST tester's drive level rather than physics.
  It does not matter why — it is a conversion, not a theory.

## A parsing trap, recorded so it is not repeated

Document A switches notation partway through without warning: early entries use
implicit ordering (`c _ 180v _ b+ _ gnd _ …`), later ones explicit numbering
(`1=HOUT 2=B+ 3=GRND …`, sometimes dot-separated, sometimes missing the `=` as
in `7GRND`).

Handling only the first form does not merely drop the second — the explicit
lines get mistaken for part codes, and the real codes then attach to the *next*
implicit pin-out further down the page. Silent, and wrong for everything after
the switch. The first version of this parser did exactly that, and the error was
only caught because two sources disagreed about a part in an implausible way.
It is also why the Vpp/DC figure above was recomputed from corrected data.
