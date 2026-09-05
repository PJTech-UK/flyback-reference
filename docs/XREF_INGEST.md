# The 2011 equivalence catalogue

`dataset/xref_pdf.csv`, parsed from an 852-page equivalence catalogue produced in
2011 (text layer intact, no OCR).

The 2003 reference database's coverage stops at 2003, so every post-2003 Chinese
part number — the `BSC24-*`, `BSC25-*`, `CF0801-*` and `FTK*` families still on
benches — was missing. The catalogue carries ~78k raw pairs including all of them.

| | before | after |
|---|---:|---:|
| equivalence pairs | 23,731 | **48,811** |
| part codes | 5,095 | **9,067** |
| distinct manufacturer codes | 21,965 | ~60,000 |

Of the catalogue's 44,225 unique pairs, 25,697 were new.

## Why the parse is trustworthy

The layout is rigid — every line is `OEM HR OEM HR`, a single-column `OEM HR`, or
a page number — so 39,153 of 39,168 content lines parse without ambiguity and
nothing was skipped as unrecognised.

The two sources agree where they overlap: 18,569 codes appear in both, and
**99.06%** map to the same part. The 174 that disagree are 2003-vs-2011
revisions; both are kept and tagged.

The distribution of unmatched codes is the other check. A broken matcher would
leave them spread evenly. Instead they cluster exactly where the 2003 data stops:

| band | pairs | not in the older data |
|---|---:|---|
| 0–8,999 | 28,016 | ~0.1% |
| 42,000–46,999 | 5,876 | 40% |
| 47,000–48,999 | 3,656 | **97–99.7%** |
| 80,000–82,999 | 7,540 | 43–88% |

## What was discarded

**Excel scientific notation (1,183 rows).** The catalogue was typeset from a
spreadsheet and long numeric codes were silently rounded: `901229452021` printed
as `9,01229E+11`. `4,82214E+11` stands in for 198 distinct real numbers, so
keeping it would make one search return 198 unrelated parts.

Reversing the mangling was tested and does not work. Of 134 mangled strings, 39
resolve uniquely, 91 are ambiguous, 4 have no candidate — and all 39 reproduce
pairs already held. A unique candidate only exists because the catalogue also
printed the unmangled spelling elsewhere. Do not retry it.

**Packaging dimensions from stub pages.** 1,197 scraped catalogue pages carry
only zeros. They do list a box size and weight, but there are 35 distinct
dimension tuples across all 1,197 (652 share one value) — a shipping class shared
by dozens of parts, not a measurement. What the stub does prove is that the part
was sold, recorded as `listed`.

## Three tiers

| tier | count | meaning | search |
|---|---:|---|---|
| have data | 4,829 | tester reading, drawing or fitment list | `data:full` |
| listed, no data | 436 | a catalogue page exists but carried nothing | `listed:yes data:none` |
| cross-reference only | 3,802 | appears in the 2011 catalogue and nowhere else | `src:xref` |
