# Inferred siblings

Some HR codes look like close relatives: two OEM codes from one maker's
numbering range that differ only in the final letter, say. That is a hint, not
evidence. Of 854 such pairs where both HR codes have data, only 8% agree on
type, B+/COL pins and anode voltage (within 5%), against 0.3% for random pairs.
The LG 6174Z-1017 range alone spans eight HR codes with two different
pin-outs. HR Diemen gave each one its own code and never
said they were related. Where one sibling has a schematic and the other has
nothing, the documented one is the best guide available, as long as it is
presented as an inference and nobody mistakes it for an equivalent.

So siblings are never generated automatically. Each one is a judgement with
outside evidence behind it (a fitment, a forum report, a measurement), curated
by hand in `dataset/siblings.csv`:

| column | meaning |
|---|---|
| `hr`, `sibling` | the two HR codes. Order does not matter: the build stores both directions |
| `note` | **mandatory.** How the inference was reached: which codes, which source, and what is still unverified. The build skips a row with no note and warns |
| `url` | supporting evidence, where there is any (forum post, datasheet). Optional |
| `source` | provenance tag, `community` by default |

Lines starting `#` are comments.

Each part's card shows the sibling with an amber rule, an `inferred` tag, the
note in full and a Source link. The static `/part/…` page shows them too.

## Not equivalents, not substitutes

- **Equivalents** are the manufacturer's own claim that two codes interchange.
- **Substitutes** are computed from extracted tap profiles (see `SUBSTITUTES.md`).
- **Siblings** are a person's reasoning about where two codes came from. A
  separate HR code usually means *something* differs, so the note should say
  what to check (pin-out, anode rating, focus/G2, fit).

## Adding one

1. Add a row to `dataset/siblings.csv` and write the note so a stranger could
   retrace the reasoning.
2. `php bin/build-db.php`. The summary line `inferred sibling pairs` should go
   up by one.
3. Check both parts' cards.

First entry: HR 46455 (LG 6174Z-1017D) ↔ HR 46177 (LG 6174Z-1017H, Apple CRT
iMac). The -D had no data, and the -H has a full schematic.
