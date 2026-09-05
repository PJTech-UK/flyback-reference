# OEM part numbers: one part, many spellings

The sources print the same manufacturer part number several ways. HR 7506
carried six chips for three parts:

```
AT 2075-30102   AT 2075/30102   AT 2079-30101
AT 2079-30102   AT 2079/30101   AT 2079/30102
```

Sony is the worst of it — HR 46106 listed `1-453-543-11` *and* a flat
`145354311`, twenty-two times over. With the bracketed Toshiba codes
(`UX 2600(A2)` vs `UX2600A2`) on the same card, it claimed **68** equivalents
where it holds **35**.

## Normalisation: strip everything that is not a letter or a digit

`norm()` in `bin/build-db.php` and `hr_norm()` in
`src/util.php` must stay identical — one builds the columns the other
compares against. Both now do:

```php
strtoupper(preg_replace('/[^\p{L}\p{N}]+/u', '', $s))
```

Previously only `[\s\-_./]` was dropped, which left brackets, `*`, `+`, `,`,
`:`, `#` and OCR debris (`BH┤600189A`) as significant characters. They are not:
`TBC 000*033`, `AZ 2101+13` and `AT90/7,7-23/750` are using `*`, `+` and a
decimal comma exactly as other makers use a dash. The superseded static `webapp/index.html`
carries the same rule, so it does not disagree with the app if anyone runs it.

Widening it merged 141 OEM keys and 73 TV-model strings, every one a spelling of
the same thing, and **no HR codes at all** — 9,067 before and after, so no part
was conflated with another.

Search has always run through this, on `hr.code_blob` for free text and
`equivalents.oem_norm` for `oem:`, so all of these return HR 7506:

```
AT2075        AT207530102     AT 2075/30102
AT-2075-30102 at2075.30102    AT_2075_30102
```

What normalisation does **not** do is collapse suffixes: `AT 2075/20`,
`AT 2075/25` and `AT 2075/26` are three different parts on three different HR
codes, and searching `AT2075` correctly returns all three. Keep any new lookup
on `hr_norm()` and punctuation stays irrelevant without that costing precision.

## The display: one chip per part

`equivalents` keeps **every literal spelling** — throwing source data away to tidy a
card would be a poor trade, and the raw rows are what a re-parse gets checked
against. Instead each `(hr, oem_norm)` group elects one row:

| column | meaning |
|---|---|
| `canon` | `1` on the one row per group that gets rendered |
| `alts` | on that row, JSON list of the other spellings |

The card draws one chip per part, marked `±`, with the other spellings in its
tooltip. A search matching *any* spelling highlights the canonical chip, because
the highlight is decided on `oem_norm`, not on the literal.

1,715 duplicate spellings across 1,664 groups fold away, on 866 HR codes.

## Which spelling wins

In order, until one decides it:

1. **Most separators.** Punctuation encodes the manufacturer's own field
   boundaries, so `1-453-543-11` beats `145354311`. The flat form is derivable
   from the punctuated one; the reverse is guesswork.
2. **Commonest spelling** across the whole dataset.
3. **House style** — the summed dataset frequency of the separator characters
   used. Where two forms differ only in *which* separator (`AT 2075-30102` vs
   `AT 2075/30102`) neither is more correct, since OEMs printed both, so the tie
   goes to the character this data uses more: space (20,983), dash (15,516),
   dot (7,615), slash (3,000).
4. Longest, then alphabetical, so a rebuild is deterministic.

Where the sources place separators *differently* rather than merely using a
different character — 228 groups, e.g. `00 H 07012400` vs `00 H-0-701-2400`, or
`627.006` vs `6270.06` — one of them is a typesetting error and there is no way
to tell which. The rule still picks one, and the tooltip shows the other. That is
the honest state of knowledge; picking one and hiding the rest would not be.

## Counts changed

`n_equiv`, the `n_pairs` headline and the page footer now count distinct parts
rather than rows: **48,811 pairs** where it used to say 50,666. `n_pair_rows`
keeps the row figure. Nothing was deleted — the build says so:

```
pairs:        48,811  (50,526 rows incl. spelling variants)
```

The 140-row drop between 50,666 and 50,526 is the catalogue ingest: it skips a
pair the 2003 database already has, keyed on the normalised code, so widening the
normaliser correctly suppressed 140 bracketed re-spellings of pairs we held.

## Knock-on

`sourcing.js` ranks OEM codes into a combined marketplace query. It now sees
deduplicated codes, so its six-code budget is spent on six *different* parts
instead of two parts spelled three ways.

## If the rule changes again

Rebuilding is the whole migration — `php bin/build-db.php` recomputes
`oem_norm`, `canon`, `alts`, `code_blob` and `use_blob` from the sources in
about 1.5 seconds. There is no state to migrate.
