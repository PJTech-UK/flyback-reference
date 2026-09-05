# Bridging the Classic catalogues into the HR dataset

Two PDFs from "Classic", a second aftermarket flyback maker:

| file | pages | content |
|---|---:|---|
| `37114144-Classic-2007TV.pdf` | 54 | OEM code → Classic FBT code |
| `106754014-Classic-Transf-Linhas-2012-Marca-Modelo-FBT.pdf` | 101 | brand + TV model → FBT code |

```bash
python3 extract/parse_classic_pdfs.py     # -> dataset/classic_oem.csv, classic_models.csv
php bin/build-db.php              # bridges them into equivalents as src='classic'
```

Classic appear to be defunct and their FBT parts are almost never offered for
sale, so the *stock* value is nil. The *linkage* value is not.

## Two independent bridges

**Via OEM codes.** 7,804 OEM part numbers appear in both catalogues. Where an
FBT part and an HR part are both claimed for the same OEM number, the two firms
reverse-engineered the same original. That links **1,025 of 1,071** FBT parts to
at least one HR part; **837** resolve to exactly one.

**Via TV models.** Independently, 6,558 brand+model strings appear both in
Classic's by-model index and in HR's `uses` table, linking 540 FBT parts.

## Why the inference is trusted

Where both bridges exist for the same FBT part (513 cases), they agree on at
least one HR part **96.3% of the time** (494). Two vendors, two entirely
different join keys, same answer. That is what justifies admitting transitive
links at all.

## What gets ingested, and on what terms

If FBT-X corresponds to exactly one HR part, then every *other* OEM code Classic
lists against FBT-X becomes a candidate equivalent of that HR part. This adds
**1,021 pairs**, tagged `src='classic'`.

Strict terms, because this is inference and not testimony:

- **Unambiguous only.** An FBT resolving to more than one HR part is dropped,
  not guessed at. 837 of 1,071 qualify.
- **Never laundered.** Rows are tagged and rendered in a distinct dashed orange,
  with a tooltip saying explicitly that HR Diemen did not make this claim.
- **Never overrides.** Existing book or catalogue pairs win; only genuinely new
  pairs are added.

Of 1,689 OEM codes Classic knows that HR does not, 1,509 reach an HR part this
way — so someone holding one of those numbers now gets an answer instead of
nothing.

## Compounding-error warning

An HR equivalence is already a sales claim rather than a measurement (see
`SUBSTITUTES.md`). A Classic-inferred link is a sales claim chained to another
sales claim, so its looseness compounds. Treat `src='classic'` chips as leads to
investigate, not as equivalents — and prefer `extract/find_substitutes.py`
against the extracted schematic data when the question is "will this actually
work", rather than "what else is this called".

## Notes on parsing

Both catalogues repeat their column group across the page and their left-hand
fields contain internal single spaces (`01 004 10`, `CTV 5197`), so whitespace
tokenising destroys them. The parser anchors on the `FBT\d+` codes and takes the
text preceding each, splitting fields on runs of two or more spaces.

`TUN*` tuner part numbers are filtered — they are not transformers. Neither of
these two PDFs actually contains any, but other Classic catalogues do.
