# The search grammar, and why it will not guess

## Boolean logic is already there

`src/QueryParser.php` is a recursive-descent parser over:

```
expr    := orExpr
orExpr  := andExpr ( 'OR' andExpr )*
andExpr := notExpr ( ['AND'] notExpr )*     -- adjacency = implicit AND
notExpr := 'NOT' notExpr | primary
primary := '(' expr ')' | TERM
```

So all of these work today, and always have:

```
CM8833 make:philips                       both, implicitly ANDed
CM8833 AND make:philips                   the same thing, spelled out
make:philips OR make:commodore            either
CM8833 NOT make:philips                   the first without the second
(make:philips OR make:commodore) CM8833   grouped
type:SM AND (vpp>=200 OR role:focus)      any factor, not just text
```

The Help panel documents it and the query builder exposes an AND/OR dropdown
per row, plus a "group" button that brackets two rows. The parser is
deliberately forgiving — an unmatched `)` or a trailing `AND` is ignored rather
than throwing, so a half-typed query never errors.

**One wrinkle worth knowing.** Adjacent *free-text* words merge into a single
phrase rather than being ANDed: `philips cm8833` matches the contiguous string
`PHILIPSCM8833`, which is why it finds the Philips CM 8833 and not every part
that mentions Philips somewhere and CM8833 somewhere else. That is deliberate,
so `sony kv-1234` behaves the way anyone typing it expects. If you want the
loose reading, say it: `philips AND cm8833`. Factor filters never merge.

## Misspellings: offered, never applied

`make:phillips` finds nothing, so the search offers a rewrite you click. It does
**not** quietly match PHILIPS. Given the vocabulary here, silent fuzzy matching
would be worse than the typo.

That is measured, not assumed. Against the real 1,472 distinct set-manufacturer
names:

| method | names dragged into a collision |
|---|---:|
| `soundex()` | **891** of 1,472 |
| `metaphone()` | 529 |

Soundex key `A200` is AAS, ACE, AOC, AKAI, ASA, AGA, AIKO…; `N200` is NEC,
NIKKEI, NOKKAI, NAIKO. Metaphone is better and still puts NEC with NIKKEI, and
DEC with TEAC and TOKAI. Either one turns `make:nec` into a lucky dip.

Edit distance fails the same way, but only at short lengths — which is exactly
where most brand names live:

| length of the shorter name | pairs within one edit |
|---:|---:|
| 3 | **1,027** — GEC/DEC/NEC/GVC/KEC are all mutually adjacent |
| 4 | 16 |
| 5 | 23 — INTEL/INTER, FALCO/FALCON |
| 6 | 25 — COMPAQ/COMPAL, VICTOR/VICTORY |
| 7+ | 15 |

A three-letter brand name has nowhere to hide: one edit reaches a different real
manufacturer, so there is no way to tell a typo from a correct search. Hence the
rules in `src/Suggest.php`:

- **Nothing below 5 characters.** `GEC` is never read as `DEC`.
- One edit up to 7 characters, two from 8 — `toshiaba` → TOSHIBA, `grundik` →
  GRUNDIG, `sonny` → SONY, `phillips` → PHILIPS.
- Ties go to the brand fitted to more parts, so a typo for a major make is not
  answered with an obscure one-part manufacturer.
- Only on a **zero-result** search, and only as a suggestion. Result counts stay
  exact; nobody has to wonder whether 509 matches includes some guesses.
- Every correctable word is fixed in **one** rewrite. `phillips OR sonny` offers
  `PHILIPS OR SONY`, not two suggestions that each still contain the other typo.

The vocabulary is brand names only. Part numbers are not fuzzed at all: one
character in `BSC24-01N40G1` is a different transformer, and `soundex()` on an
alphanumeric code is meaningless.

## Punctuation

Handled separately and completely — every lookup normalises to letters and
digits, so `AT2075/30102`, `AT-2075-30102` and `at 2075 30102` are one search.
See `OEM_CODES.md`.
