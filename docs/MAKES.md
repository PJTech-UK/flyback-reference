# Makes and their other names

The catalogue does not always file a set under the name it was sold under.

**Compound names.** Where brands shared a chassis it writes both, or all three:
`FERGUSON-THORN-EMI`, `BUSH-MURPHY-RANK`, `NOKIA - ITT`, `GOLDSTAR - LG`. 41
names are compound and 21,324 fitment rows sit behind one.

**Abbreviations.** Sometimes it writes a code instead of a name. Amstrad is filed
as `AMS` — 97 models including the CTM 644, the CPC 6128 and the PC 1640
monitors. 466 of the 1,472 make names are three characters or fewer; most of
those are genuine three-letter brands (RCA, PYE, JVC, GEC, IBM, NEC), but some
are abbreviations and there is no way to tell them apart mechanically.

## `dataset/make_aliases.csv`

`alias,fabname,note` — a name people browse or search by, and the catalogue name
it reaches. Curated, not derived: splitting on punctuation alone turns
`WATT-RADIO` into "Watt" and "Radio", and `LITE-ON` into "Lite" and "On".

Two rules the build enforces, and a load fails loudly rather than silently if
either is broken:

- an alias that is **already a make in its own right** is rejected, so it cannot
  shadow that make's own page — which is why `SINUDYNE`, `SCHNEIDER`,
  `WESTINGHOUSE`, `HITACHI` and `GORLER` are absent from the file despite being
  halves of compound names
- an alias pointing at a **fabname that does not exist** is rejected

## Where aliases appear

| place | behaviour |
|---|---|
| `/makes` | listed in the A-Z as `THORN → FERGUSON-THORN-EMI (shared chassis)` |
| `/make/thorn` | **301** to `/make/fergusonthornemi`, page number preserved |
| `/make/ams` | headed "Also listed as AMSTRAD"; the title and description carry it too, so the page can match the name a person types |
| search | the aliases are indexed into `use_blob`, so `AMSTRAD CTM 644` finds HR 6355 and HR 7674 |
| sitemaps | **excluded** — they redirect, and a sitemap of redirects is noise |

Compound names were already handled for *search* by splitting the name into
words at build time (see `bin/build-db.php`), which reaches "Thorn" but can
never reach "Amstrad" — the name is not in the string to be split. Aliases cover
what splitting cannot, and browsing, which splitting never touched.

## Adding one

Append a row and rebuild. Only add a name you can evidence — the model list
under the code is usually enough: `AMS` carries `CPC 464`, `CPC 6128` and
`CTM 644`, and the independent Classic catalogue lists the same models spelled
`AMSTRAD`. Two catalogues, same models, different spelling. That is the standard;
a plausible-looking abbreviation is not.

## Catalogue codes

The catalogue stores a three-letter code per make and resolves it to a name in a
separate table. `bin/build-db.php` registers every code as an alias of its name,
derived from `dataset/manufacturers.json` rather than curated, so `/make/ams`
still reaches Amstrad and the code itself is searchable. These are tagged
`catalogue code` and are deliberately **left out of the `/makes` A-Z** — there
are about a thousand of them and they would bury the makes the page exists to
show.

343 codes still resolve to nothing: `TCL`, `PHX`, `BRE`, `CHH`, `AKR`, `XCC`,
`HSN` and others, 2,615 models behind them. They are absent from the catalogue's
manufacturer table, not lost in extraction. They show as the bare code, which is
honest, and they are not guessed at.

## Records split across a page boundary

`diemen.v12` is a **V12 Database Engine** file (the app ships
`xtras/V12_DBE_FOR_DIRECTOR.X32`), and it is paged. A record can straddle a page
boundary: `\x02\x10<CODE>\x00` ends one block and the name field lands in
another as `\x1a\x00<len>\x10<NAME>\x00`. No contiguous regex joins the two, so
the row is lost.

Seven manufacturer codes are affected: `BRE ERM HUN KOG LIE MEG PHX`. Three are
recovered in `dataset/manufacturer_overrides.csv`, each evidenced by its
alphabetical neighbours — the table is sorted by code, so a name has to fit
between the two intact rows that bracket it:

| code | name | bracketed by |
|---|---|---|
| PHX | PHOENIX | PHO=PHILCO … PIA=PIACA |
| MEG | MEGATRON | MEE=M ELECTRONIC … MEI=MEIHUAN ELECTRONICS |
| ERM | EUROMAN | ERL=EUROLINE … ERO=ERO |

`BRE` is `BRANDT`: it is the only BR brand in the index without a code and the
only BR code without a name, its models are ICC and TX chassis — Thomson-Brandt
families — and `CHASIS ICC 6 -> HR 7269 + HR 7270` matches what The Book shows
for Brandt. `HUN`, `KOG` and `LIE` are left as bare codes. The override file
never overrides a name the extractor did find.

## Testing whether a code is a brand already named

A code might be a second listing of a brand the catalogue also spells out. Two
tests, both cheap, neither conclusive on its own:

- **Duplication** — how many of the code's (model, part) pairs also appear under
  a named brand. `JEN` is the only significant hit: all 43 of its pairs are also
  under `PRIMA - PRIME`, which holds 63. A subset, not a rename, and two brands
  sharing a rebadged chassis is ordinary, so they are left separate.
- **Splitting** — a brand divided between code and title would show *no*
  duplication, so absence of overlap proves nothing. Compare the model sets
  instead. `CHH` shares no part and no model with `CHANG HAI`, so they are not
  the same brand.
- **A single model+part match against a source that has the name.** `CHH` is
  `CHANGHONG`: its model `2131 NA` is fitted with `HR 80050`, and a later
  edition of The Book lists exactly that pair under Changhong. One matching
  pair from an independent source beats any amount of reasoning about what
  three letters might abbreviate.

## The later edition

`CHANGHONG` does not appear anywhere in `diemen.v12` — the 2003 manufacturer
table holds `CHANG FEI`, `CHANG FENG`, `CHANG HAI` and nothing else of the sort.
The roughly 340 still-unresolved codes came from the 2012 website, and their
names live in an edition of The Book newer than the 2003 file this repository
extracts (the app ships `HRBOOKupdate.exe`, dated 2024).

That is the source that would resolve the rest of them. Nothing in this
repository can.

Overlaps of one to three pairs are noise — rebadged sets are common — and are
not acted on.

## Two different kinds of unresolved code

Worth keeping straight, because only one of them is a defect:

- **In the catalogue, lost in extraction** — the seven above, and the 51 A-codes
  the byte window cut off. Recoverable, and recovered.
- **Never in the catalogue** — roughly 340 codes used by the 2012 website whose
  fitment we hold but whose names the 2003 manufacturer table never contained:
  `TCL`, `CHH`, `AKR`, `XCC`, `HSN`, `KOG`, `JEN`, `ERN`. The Book's own brand
  list cannot expand these either, so it displays the bare code exactly as this
  site does. Nothing is lost; there is simply no name to show.

A code is only ever expanded from the catalogue's own table or from an evidenced
override. It is never inferred from what the letters look like they stand for.
`CHH` may well be Changhong and `PHX` obviously was Phoenix — but one of those
is in the file and the other is a guess, and guesses do not go in the data.
Real three-letter brands are safe by the same rule: `DEC` and `ICL` appear in
the catalogue's table as themselves and come through unchanged.
