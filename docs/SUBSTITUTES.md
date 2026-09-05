# Finding next-best flyback substitutes — method and limits

Two tools sit on top of the cross-reference:

```bash
python3 extract/analyse_xref_family.py BSC24-01N40      # is this OEM family coherent?
python3 extract/analyse_xref_family.py --hr "HR 80016"  # who claims this HR part?
python3 extract/find_substitutes.py "HR 80016"          # what else might physically do?
python3 extract/find_substitutes.py "HR 80016" --mode ratio   # ...at a different scale
```

The web app carries the shape-matched shortlist on every card (see **In the
app** below); the CLI is for exploring the tolerances, which the app fixes.

Both exist because a flat equivalence claim is not evidence. HR Diemen's
catalogue is a **sales mapping** — "we will sell you this part for that
application" — not an interchangeability proof, and it was compiled by a
company reverse-engineering other people's transformers, sometimes to
complaints of being out of spec.

## What we can compare, and what we can't

Published, therefore comparable:

| field | meaning |
|---|---|
| `pinB` | the `+` column: B+ / Vcc feed pin |
| `pinC` | `COL`: the HOT collector pin |
| `pinsD` | `⊥A / ⊥1 / ⊥2 / ⊥3`: the separate return/ground pins |
| Vpp per pin | peak-to-peak pulse amplitude on HR's own STVDST tester |
| roles | H.V., FOCUS, G2, Vcc — the HV stack |
| `mat_kv` | MAT: maximum rated anode voltage |
| `family`, box class, weight | physical format |

**Never published, and decisive:** inductance per winding, DC resistance (DCR),
inter-winding stray capacitance, saturation behaviour. A flyback drops into a
tuned circuit; two parts with an identical published Vpp profile can still
behave differently because the primary inductance sets the retrace time. So
everything here produces a *shortlist*, never a verdict.

Closing that gap requires community-contributed bench measurements. Until those
exist, this tool narrows the field to check rather than identifying a
replacement.

## Two traps when reading Vpp

**Vpp is not a DC rail.** An OEM datasheet lists rectified outputs (+15 V,
200 V, B+ 110 V). HR lists peak-to-peak pulse amplitudes. For a sinusoid,
rectifying gives roughly Vpp/2 — but a flyback secondary is a narrow retrace
pulse sitting near baseline for most of the line period, so peak-rectifying it
recovers close to the *full* Vpp, less a diode drop. Neither conversion turns
145 Vpp into a 15 V rail.

**Vpp is tester-referenced.** The figures come off HR's STVDST rig under its
own excitation, not from a live chassis. They are consistent *within* HR's
dataset, so HR-to-HR comparison is sound; converting them to in-service rail
voltages needs the tester's drive conditions, which are not documented in
anything we hold. Compare like with like.

## Classification

`find_substitutes.py` sorts candidates the way a repairer thinks:

| class | meaning |
|---|---|
| `drop-in` | same B+/COL pins, every tap present at the same pin within tolerance, adequately rated |
| `superset` | all of the target's taps plus extras you can leave unused |
| `rewire` | the same tap voltages on *different* pins — viable if you'll move wires and it fits |
| `short` | missing taps, listed individually; a low-voltage rail can often be derived elsewhere, a missing focus/G2 stack cannot |
| `mismatch` | taps line up but voltages differ materially |
| `under-rated` | MAT below the target's — **never** promoted above this class regardless of how well the windings match |

`drop-in` is deliberately rare: about 0.015% of part pairs. If everything comes
back `rewire` or `short`, that is the honest answer, not a broken tool.

`--tol` sets the fractional tolerance on Vpp matching (default 0.10).

## Shape matching: what actually works

Matching raw voltages finds almost nothing, and that is not a bug in the data —
two flybacks are rarely identical. What they can be is **the same design at a
different scale**. Every winding is ratiometric to the primary, so normalising
each part's taps to its own largest tap makes two parts comparable even when no
single voltage matches.

That, plus the line-rate class, is what turns the shortlist from empty into
useful. Measured over the 2,503 parts with two or more extracted taps:

| filter, applied in this order | ordered pairs | targets served |
|---|---:|---:|
| same tester class (ST 15.6 kHz TV / SM 32 kHz+ monitor) | 5,449,172 | 2,503 (100%) |
| + tap profile within 12% | 221,502 | 2,483 (99.2%) |
| + implied B+ rescale within ×0.80–×1.25 | 122,592 | 2,450 (97.9%) |
| + same deflection angle / scan band | 61,514 | **2,373 (94.8%)** |
| + anode rating not below the target's | 33,249 | 2,211 (88.3%) |
| + same B+ and collector pins (no rewiring) | 4,168 | 1,154 (46.1%) |
| + and missing none of the target's taps | 508 | **357** |

So: 94.8% of parts with extracted data get at least one candidate; 1,154 get one
that needs no rewiring and is adequately rated; **357 have what is effectively a
near-drop-in** that no cross-reference would ever have named, because not one of
their voltages matches exactly.

The class filter comes first deliberately. A 15.6 kHz TV flyback is not a
candidate for a multiscan monitor at any voltage, so filtering on voltage first
just wastes the tolerance budget on parts that were never viable.

**The scale caveat is the important one.** EHT scales with B+. A candidate
needing B+ ×0.80 to bring its auxiliary rails into line also drops the anode
voltage by a fifth, and the CRT decides whether that is survivable. ×0.80–×1.25
is already generous; widen it only deliberately.

## In the app

The shortlist is precomputed into a `substitutes` table by `build-db.php` (about
1.7s of the build) and shown on every card that has one, ranked no-rewiring
first, then adequately-rated, then closeness of fit. Each candidate carries how
it differs — `same pins` / `rewire`, `MAT 20 kV` when the anode rating is short,
`2 taps short`, `+2 spare`, and the B+ rescale.

`subs:"HR 80016"` is a search factor, so the candidates can be listed as full
cards and combined with anything else: `subs:"HR 80016" AND img:yes`.

This is also the answer to "what is the Schematic data panel *for*". On its own
it is a readout nobody can act on. It is the input to this: the tap profile it
displays is exactly what the shape match runs on, which is why the panel sits
directly above the shortlist it feeds.

## Fan-in

Both tools report **fan-in**: how many distinct OEM codes claim a given HR part.
HR 80016 has 65. That is not proof of a bad match, but a part sold as the
equivalent of 65 different OEM numbers is a coarser claim than one sold for two,
and it is where you should expect "near enough to sell" rather than
"electrically identical". Treat high fan-in as a prompt to check the schematic
yourself.

## Data-quality caveats

- **Coverage.** Only parts with an extracted schematic can be matched. Running
  `extract/bulk_extract.py` after adding schematics is what populates this;
  it now also reads `data/schematics-from-wayback/` and site-sourced records,
  which had been silently skipped for ~1,470 parts.
- **Polarity is not reliable per-image.** Globally the split is healthy
  (~4.4k positive / 3.7k negative), but HR 80016 extracted as all-negative when
  its GIF plainly shows ∩ on three taps. Spot-check polarity against the drawing
  before relying on it.
- **Duplicate pins.** Some parts record two taps against one pin — sometimes a
  genuinely tapped winding, sometimes mis-association by the OCR. Voltage
  matching keeps both; pin-level matching uses the first.
- **Pin labels are zero-padded inconsistently** between the 2003 database (`02`) and the
  site records (`2`). Compare via `find_substitutes.pin()`, never as raw strings.

## Before fitting a candidate

The classification above is derived entirely from published figures. It does not
establish that a part will work in a given circuit, and it says nothing about the
condition of a specific physical part.
