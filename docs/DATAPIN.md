# Functional pin-outs

`dataset/datapin_pins.csv` and `dataset/datapin_diagrams.csv`, from two
community-compiled "Data Pin Flyback" documents.

They fill the largest hole in the manufacturer's own data. HR published a pin map
(B+, collector, the returns), pulse amplitudes and the HV-stack roles, but never a
functional label for the auxiliary pins — ABL, heater, AFC and boost-up are simply
not in it. These documents give exactly that, keyed by manufacturer part number,
which the cross-reference resolves to HR codes.

| source | content |
|---|---|
| 10pp, text only | 215 codes |
| 35pp | 67 entries with a pin table, equivalents and two drawings each |

**1,486 pin-function rows across 105 parts**, 92 new equivalents, and 48
alternative diagrams — 15 for parts that had no drawing at all.

## Why the diagrams are worth having

Each illustrated entry carries a bottom-view footprint beside a connection
schematic. The manufacturer's own drawings show the windings but never the
physical pin layout, so the two are complementary rather than duplicates.

## Provenance

The images carry watermarks from two sites that are themselves now defunct. The
material appears to have been forum-shared rather than originated there.
Watermarks are left intact and the source is recorded per row.

## Conflicts are shown, not resolved

The two documents sometimes describe the same part differently. Both are stored,
tagged with the part number the pin-out was stated for, and rendered side by side
with a warning. For HR 48663 they agree on 6 of 10 pins and disagree on pin 10
(180 V vs AFC). That is a useful state of knowledge; an averaged single answer
would not be.

## Vpp-to-DC conversion

The rail voltages let the manufacturer's pulse figures be calibrated against real
rectified rails for parts present in both datasets, matched pin by pin:

> **DC rail ≈ Vpp ÷ 9** (median 9.12, range roughly 8–11)

57 comparable cases, 37 internally consistent to within 15%. Tested against random
pairing within each part (2,000 permutations): null mean 9.3, observed 37,
p < 0.0001.

Limits: **low-voltage rails only** (5–60 V) — it does not hold for the 180–200 V
rail, which is derived differently. One consistent case sits at 2.1 rather than
~9, so treat ±10% as the honest band. This is almost certainly the tester's drive
level rather than physics; it is a conversion, not a theory.

## A parsing trap

The first document switches notation partway through without warning: early
entries use implicit ordering (`c _ 180v _ b+ _ gnd`), later ones explicit
numbering (`1=HOUT 2=B+`, sometimes dot-separated, sometimes missing the `=` as in
`7GRND`). Handling only the first form does not merely drop the second — the
explicit lines get mistaken for part codes, and the real codes then attach to the
next implicit pin-out further down the page. Silent, and wrong for everything
after the switch.
