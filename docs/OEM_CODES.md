# Part-number normalisation

The sources spell the same part number several ways. HR 7506 carries
`AT 2075-30102` and `AT 2075/30102`; HR 46106 carries Sony's `1-453-543-11` and a
flat `145354311`, and Toshiba's `UX 2600(A2)` and `UX2600A2`.

## Matching

Everything is matched on letters and digits only:

```php
strtoupper(preg_replace('/[^\p{L}\p{N}]+/u', '', $s))
```

`norm()` in `bin/build-db.php` and `hr_norm()` in `src/util.php` must stay
identical — one builds the columns the other compares against. Unicode-aware, so
accented letters in model names survive; brackets, `*`, `+`, comma, colon and OCR
debris do not.

This does not collapse suffixes: `AT 2075/20`, `AT 2075/25` and `AT 2075/26` are
three different parts on three different HR codes.

## Storage

`equivalents` keeps every literal spelling. Each `(hr, oem_norm)` group elects
one row to display:

| column | meaning |
|---|---|
| `canon` | 1 on the row rendered |
| `alts` | on that row, JSON list of the other spellings |

1,715 duplicate spellings across 1,664 groups, on 866 parts.

The canonical spelling is picked by: most separators, then commonest spelling in
the dataset, then the separator character the dataset favours, then longest, then
alphabetical. Punctuation encodes the manufacturer's field boundaries, so
`1-453-543-11` beats `145354311` — the flat form is derivable from the punctuated
one and not the reverse.

228 groups place separators differently rather than merely using a different
character (`00 H 07012400` vs `00 H-0-701-2400`, `627.006` vs `6270.06`). One is
a typesetting error and there is no way to tell which; both are kept.

Counts are of distinct parts, not spellings: 48,811 pairs, from 50,526 rows.
