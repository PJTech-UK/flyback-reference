# Query grammar

```
expr    := orExpr
orExpr  := andExpr ( 'OR' andExpr )*
andExpr := notExpr ( ['AND'] notExpr )*     -- adjacency = implicit AND
notExpr := 'NOT' notExpr | primary
primary := '(' expr ')' | TERM
```

```
CM8833 make:philips                       implicit AND
make:philips OR make:commodore
CM8833 NOT make:philips
(make:philips OR make:commodore) CM8833
type:SM AND (vpp>=200 OR role:focus)
```

Unmatched parentheses and trailing operators are ignored rather than erroring.

Adjacent **free-text** words merge into one phrase rather than being ANDed:
`philips cm8833` matches the contiguous string `PHILIPSCM8833`. Use
`philips AND cm8833` for the loose reading. Factor filters never merge.

## Fields

| token | searches |
|---|---|
| bare text | part codes (HR and manufacturer) plus set make and model |
| `oem:` | manufacturer part codes only |
| `make:` `model:` | the set the part was fitted to |
| `type:` | tester class — ST (15.6 kHz TV), SM (32 kHz+ monitor), CH, TR |
| `family:` `role:` `pol:` `img:` | family code, output role, polarity, has-image |
| `vpp:` `wnd:` `mat:` `uf:` | tap voltage, winding count, anode rating, focus voltage |
| `subs:` | substitute candidates for a given part |
| `data:` `listed:` `src:` | completeness and provenance |

Bare text covers part codes and set models only — not notes, roles, family codes
or accessory lists. Set makes and models are included only when the "include
TV/monitor models" option is on.

Punctuation is stripped from both the query and the data: `AT2075/30102`,
`AT-2075-30102` and `at 2075 30102` are one search. See `OEM_CODES.md`.

## Misspellings

A zero-result search may return a suggested rewrite. It is offered, never
applied: matching stays exact, so result counts are not guesses.

Suggestions come from the set-manufacturer names only, and only above five
characters. Below that, one edit reaches a different real manufacturer — among
three-letter names there are 1,027 pairs within one edit, with GEC, DEC, NEC, GVC
and KEC all mutually adjacent. Phonetic matching is worse: `soundex()` puts 891
of the 1,472 names into a collision group (AAS, ACE, AOC, AKAI, ASA, AGA and AIKO
share one key), `metaphone()` 529.

Part numbers are never fuzzed. One character in `BSC24-01N40G1` is a different
transformer.
