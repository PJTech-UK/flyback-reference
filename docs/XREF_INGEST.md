# Ingesting the 2011 HR Diemen equivalence catalogue

Source PDF: `264522733-Catalogo-de-Equivalencias-de-Flybacks-HR.pdf`
(852 pages, produced 2011, text layer intact — no OCR needed).

Why bother: the 2003 database gives us 23,731 equivalence pairs but its coverage
effectively stops in 2003. Every post-2003 Chinese part number — the `BSC24-*`,
`BSC25-*`, `CF0801-*`, `FTK*` families that are on the bench today — was
missing. The catalogue carries ~78k raw pairs including all of those.

## Pipeline

```bash
python3 extract/parse_xref_pdf.py             # PDF  -> dataset/xref_pdf.csv
python3 extract/extract_catalogue_presence.py # HTML -> dataset/catalogue_presence.json
php bin/build-db.php                  # both -> database/database.sqlite
```

`parse_xref_pdf.py` also writes `dataset/xref_pdf_report.json` with the full
parse and validation statistics.

## Results

| | before | after |
|---|---:|---:|
| equivalence pairs | 23,731 | **49,553** |
| HR codes | 5,095 | **9,067** |
| distinct OEM codes | 21,965 | ~60,000 |

Of the catalogue's 44,225 unique pairs, 25,822 were new to us.

## Why the parse is trustworthy

The page layout is rigid — every line is `OEM HR OEM HR`, a single-column
`OEM HR`, or a page number — so 39,153 of 39,168 content lines parse without
ambiguity and **nothing was skipped as unrecognised**.

More importantly, the two sources agree where they overlap:

- 18,569 OEM codes appear in both the 2003 database and the catalogue.
- **99.06 %** of them map to the same HR code.
- The 174 that disagree are 2003-vs-2011 revisions, not parse errors. Both
  mappings are kept and tagged, because a repairer wants to see both leads.

The distribution of unmatched HR codes is the other check. If the HR-code
matcher were broken, unmatched codes would be spread evenly. Instead:

| HR band | pairs | not in our data |
|---|---:|---:|
| 0–8,999 (the 2003 book) | 28,016 | ~0.1 % |
| 42,000–46,999 | 5,876 | 40 % |
| 47,000–48,999 | 3,656 | **97–99.7 %** |
| 80,000–82,999 | 7,540 | 43–88 % |

They cluster precisely where the 2003 book stops, which is what a correct
matcher looks like.

Matching is on a normalised key: separators stripped, uppercased, leading zeros
dropped from the digit run — so the 2003 database's `HR 2280 T12 S` and the catalogue's
`HR2280T12S` collide deliberately, as do `HR 0064` and `HR64`.

The same normalisation applies to OEM part numbers, and decides which spelling
of a code gets shown — see `OEM_CODES.md`.

## What was deliberately discarded

**Excel scientific notation (1,183 rows).** The catalogue was typeset out of a
spreadsheet, and long all-numeric part codes were silently rounded on the way
through: `901229452021` was printed as `9,01229E+11`. These are worse than
useless — `4,82214E+11` stands in for 198 distinct real part numbers, so
keeping it would make one search return 198 unrelated flybacks.

**Reversing the mangling was tested and does not work.** A 6-significant-figure
rendering defines a range the true value sat in, so candidates can be searched
for. Of 134 mangled strings: 39 resolve to exactly one real code, 91 are
ambiguous, 4 have no candidate. But all 39 unique recoveries reproduce pairs we
*already hold* — zero new information. That is not bad luck: a unique candidate
only exists because the catalogue also printed the unmangled spelling
elsewhere. Where it didn't, there is nothing to match against. Don't retry this.

**Packaging-class dimensions from stub pages.** See below.

## Three tiers of knowledge

The database now distinguishes what we *know* from what we can merely *name*:

| tier | count | meaning | search |
|---|---:|---|---|
| have data | 4,829 | tester reading / schematic / fitment list | `data:full` |
| listed, no data | 436 | HR Diemen published a catalogue page, but it carried nothing | `listed:yes data:none` |
| cross-reference only | 3,802 | appears in the 2011 catalogue and nowhere else | `src:xref` |

New search factors: `data:none|full`, `listed:yes|no`, `src:book|site|xref`.

The middle tier comes from `extract_catalogue_presence.py`, which re-reads the
1,902 pages scraped from hrdiemen.com in May 2026. 1,197 of those rendered but
carried only zeros — `parse_scraped_html.py` correctly refuses to merge them.
They do carry a box size and weight, but there are only **35 distinct
dimension tuples across all 1,197 pages** (652 share one value), so that is a
shipping-box class shared by dozens of parts, not a measurement of any part.
Merging it would dress up packaging data as part data. What the stub page does
prove is that HR Diemen sold the thing — recorded as `hr.listed`.

## The sourcing panel

`public/sourcing.js` builds pre-composed searches per part. The useful
trick is the combined query: every distinctive code for the part OR'd together,
so a part unobtainable under its own number surfaces under an equivalent.

Codes are ranked before inclusion — mixed alphanumerics with punctuation
(`BSC24-01N40G1`) are near-unique, while all-numeric codes (`030715192`) drown
in noise and are demoted. Six codes maximum; longer OR-queries return nothing.

It also searches the component's trade name in eight languages, because the
English word is often the worst one to use: Russian **ТДКС** and Chinese
**高压包** are the standard trade terms and surface stock that never appears in
English results, and triplers are **Kaskade** / **triplicador** / **умножитель**
depending on where the seller is.

## HR-Diemen is gone

`hrdiemen.com` now redirects (2026 captures are 301; 2021 captures are 200).
The May 2026 scrape got 1,902 of 5,504 sitemap URLs before it died — the rest
returned HTTP 500 from their own server.

`extract/fetch_wayback_pages.py` will attempt those from the Internet Archive.
It is deliberately slow: one request at a time, 6 s minimum spacing,
exponential backoff, and it abandons the run after 6 consecutive failures.
Progress is cached, so run it in sessions.

**Caveat: its live-fetch path is untested.** archive.org returned 503 to every
attempt during development (a known intermittent condition — ordinary
interactive browsing was failing too), so only `--status` has been exercised
against real conditions. Try `--limit 5` first and check the output before
committing to a long run.

```bash
python3 extract/fetch_wayback_pages.py --status
python3 extract/fetch_wayback_pages.py --limit 5
```
