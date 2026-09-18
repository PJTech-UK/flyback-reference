# Search indexing

How the site presents itself to crawlers, and what to look at when Search
Console says pages are not indexed.

## Sitemaps

`/sitemap.xml` is a sitemap *index*. It names four child sitemaps:

| sitemap | URLs | contents |
|---|---|---|
| `/sitemap-core.xml` | 39 | the root, `/makes`, and each `/parts/n` page |
| `/sitemap-makes.xml` | 1,472 | one page per manufacturer |
| `/sitemap-parts-fitted.xml` | 4,154 | parts that name the sets they were fitted to |
| `/sitemap-parts-codes.xml` | 4,939 | parts carrying equivalent codes but no fitment |

The split exists because Google reports coverage per submitted sitemap. A single
flat list answers "8,000 not indexed" and nothing else; split this way, the
indexing rate of the thin pages can be read separately from the substantial
ones, which is the difference between a number and a diagnosis.

Submit the index URL in Search Console. Google reads the children from it — the
children do not need submitting individually, though doing so makes the
per-group figures appear sooner.

## Page titles

Every part page was once titled `<code> — flyback / LOPT equivalents and
specifications`. Nine thousand titles differing only in the code read as
templated duplication and were largely not indexed.

Titles now lead with whatever distinguishes the part, in this order:

1. the set it was fitted to — `HR 6414 — SONY line-output transformer, 5 sets`
2. failing that, an equivalent code — `HR 1664 — line-output transformer
   equivalent to 079-06099/0.3`
3. failing that, the bare form — 38 parts have neither

A part whose code appears in another part's accessory list is described as what
it is (`HR 16525 — Screen (G2) control`) rather than as a transformer, which it
is not.

## 404s

Unknown paths return 404 with a short body. They previously fell through to the
single-page shell and returned 200, which Search Console reports as *Soft 404*
and which made the whole URL space untrustworthy. `public/index.php` serves the
shell for `/` only; `app.js` does no client-side path routing, so nothing else
needs it.

## Accessories

Accessory strings are stored as the catalogue wrote them — `HR 16502-CA.MAT /
HV CA.` — a code, a Spanish abbreviation and its English gloss. `Page::accessory()`
decodes these to a code and a plain label, and both the server-rendered pages and
the JSON API use it, so the two cannot disagree:

| string contains | label |
|---|---|
| `BLD` | Screened EHT lead |
| `FOCO` / `FOCUS` | Focus control |
| `G2` / `SCREEN` | Screen (G2) control |
| `MAT` / `HV CA` | EHT anode lead |

OCR truncated a handful of strings to a bare code. Those resolve against other
rows carrying the same code rather than being left blank.

## What is not a fault

"Discovered – currently not indexed" and "Crawled – currently not indexed" on a
new domain mean Google has decided the pages are not yet worth an index slot.
That is a judgement about the site's standing, not an error to fix. Inbound
links move it; nothing in this repository does.
