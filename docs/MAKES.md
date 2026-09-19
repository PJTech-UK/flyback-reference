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
