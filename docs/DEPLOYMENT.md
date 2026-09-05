# Flyback, LOPT & Tripler Cross-Reference Database — web app (PHP + SQLite)

A small, publication-ready front-end for the combined flyback / LOPT / tripler
dataset. An independent cross-reference archive, not a manufacturer product and
not affiliated with any manufacturer — see `../DATA.md`. Replaces the old static `webapp/` (which shipped a 10.5 MB
`data.js` to every visitor and searched in-browser) with a PHP API over a SQLite
database, so the page load is tiny and search scales.

Features:
- Free-text search over manufacturer codes, HR codes and TV/monitor models
  (spaces / dashes / case ignored), plus structured filters.
- **Cross-reference coverage** from the 2011 HR Diemen equivalence catalogue
  (~44k OEM→HR pairs) on top of the 2003 database's 23.7k — including the whole
  post-2003 Chinese code space (BSC24-*, BSC25-*, CF0801-*, FTK*), and ~4k HR
  codes carried for their equivalents alone. See `../XREF_INGEST.md`.
- **Per-part web sourcing panel**: pre-composed searches across five engines,
  six marketplaces, the Internet Archive and the component's trade name in
  eight languages (`Zeilentrafo`, `ТДКС`, `高压包`, `triplicador`, …).
- **Boolean search**: `AND`, `OR`, `NOT` and parentheses; adjacent terms are
  implicitly ANDed. e.g. `tipo:SM AND (vpp>=200 OR role:focus)`.
- A **guided query builder** (menus of factors + operators) that composes the same
  query syntax, and a **help modal** documenting every factor.
- Per-card focus-voltage (Uf) estimate, OCR-extracted schematic data (Vpps,
  polarities, windings, roles), tester pin-out, equivalents, usage lists, images.

No framework and no Composer dependencies — just PHP 8.1+ with `pdo_sqlite`.

## Layout

```

  public/          document root (point the web server here)
    index.php      front controller: /api/* + serves the SPA shell (stamps
                   asset URLs with ?v=<mtime> so a deploy busts the cache)
    app.html       single-page shell      app.js  client      styles.css
    sourcing.js    the "find this part on the web" link builder
  src/             Db, Catalog, QueryParser, QueryCompiler, Search
  bin/build-db.php builds data/database.sqlite from ../extract + ../analysis
  data/database.sqlite   built artifact (gitignored)
```

> **Adding a search factor?** Register its prefix in
> `QueryCompiler::looksLikeFactor()` as well as handling it in `leaf()`.
> Miss that and `QueryParser` treats the token as free text and merges it with
> its neighbours into one unmatchable phrase — the filter then silently returns
> zero results instead of erroring.

Schematic / family / box images are served from the project's `data/` directory
(one level above ``) under the `/data/...` URL prefix.

## Build the database

From the project root (needs the extracted JSON/CSV under `extract/` and
`analysis/bulk_extract/` — produced by the Python extraction pipeline).
Two optional inputs are produced by their own scripts and only need
regenerating when their sources change:

```bash
python3 extract/parse_xref_pdf.py            # -> dataset/xref_pdf.csv
python3 extract/extract_catalogue_presence.py # -> dataset/catalogue_presence.json
php bin/build-db.php
```

Expected output:

```
+ 25,822 new pairs from the 2011 catalogue PDF (3,972 data-less HR codes)
pairs: 49,553 · hr codes: 9,067 · with uses: 4,153 · with sch: 2,263 · ...
```

(Before the catalogue ingest this was 23,731 pairs / 5,095 codes — the figures
`extract/build_web_data.py` still produces.)

Re-run this whenever the upstream data is refreshed, then redeploy
`data/database.sqlite`.

## Run locally

```bash
php -S localhost:8000 -t public public/index.php
# open http://localhost:8000
```

The `-t public` is required: it makes the built-in server serve `app.js` /
`styles.css` directly; the router serves `/data/*` images from the project tree.

## API

- `GET /api/catalog` — factor catalogue + dataset stats (drives builder & help).
- `GET /api/search?q=&page=&sort=&eht=&onlyImgs=&uses=` — `{total, page, pages, results[]}`.
  `q` takes the full boolean grammar (AND / OR / NOT / parentheses); a zero-result
  search may also return `suggestion` — see `../SEARCH.md`.
- `GET /api/hr/{code}` — one hydrated record (deep links / sharing).

Every result carries `subs`: the shape-matched substitute shortlist, precomputed
into the `substitutes` table at build time. `subs:"HR 80016"` searches it. See
`../SUBSTITUTES.md`.

All user input is bound as parameters or validated-and-inlined numerics, so the
search is injection-safe.

Codes are matched with punctuation stripped, and each part shows as a single
chip however many ways the sources spelled it — see `../OEM_CODES.md`.

Usage rows come back with `hit: true` when the search named that set, and the
card surfaces them: a count beside the heading, the matching sets as chips
above the list, and the list auto-expanded with them highlighted and their
brands sorted first. Searching `CM8833` otherwise returned three correct parts
with no sign of *why* — the term only existed inside a collapsed list that runs
to 2,116 models on the biggest triplers. Free-text terms only flag usage rows
when the "include TV/monitor models" toggle actually put them in scope;
`make:` and `model:` always do.

## Branches and versions

The published repository has two branches:

| branch | what it is |
|---|---|
| `master` | every export; where corrections land and where a reader looks first |
| `release` | what the server deploys — it only moves when a release is cut |

Point Forge’s *Repository → Branch* at **`release`**, and enable Quick Deploy.
`master` can then be updated whenever without a half-finished change reaching the
live site.

Versions are semver, in `VERSION`, and shown in the page footer so "what is
deployed" is answerable without reading a commit hash:

| bump | for |
|---|---|
| patch | corrections to data or wording, a bug in the application |
| minor | new data, a new search factor, anything additive |
| major | a change that breaks how something is used — a renamed API field, a restructured dataset, a query syntax that no longer parses |

Cutting a release, from the working repository:

```bash
tools/release.sh patch        # or minor / major
```

It bumps `VERSION`, commits and tags it, exports to the published repository,
tags that too, moves `release` to `master`, and then prints the push commands and
stops. It does not push: read what it says first.

## Hosting notes

The document root must be `public`. If your host will not let you set it
below the account root, the `.htaccess` at the repository root denies everything
and `public/.htaccess` re-allows the one directory that should be served
— without that, `database/database.sqlite` is a downloadable file.

The application is read-only: the SQLite connection is opened with
`PRAGMA query_only = ON`, nothing in `public/` or `src/` writes to disk, and
there is no upload, login or admin path. Errors are logged rather than returned;
set `APP_DEBUG=1` in the environment to see them in the response while
developing.

Enable compression at the server if you can — a result page is repeated part
numbers and compresses about twelve to one. `index.php` gzips JSON itself when
the server has not already done it.

## Deploy on Laravel Forge (generic PHP site)

**1. Web Directory.** Forge's *Site Settings → App* has a **Web Directory**
field, defaulted to `/public`. Set it to:

```
/public
```

That is the only nginx change needed. `public/data` is a committed
symlink to the repository's `data/` directory, so nginx serves the schematic and
family images straight off disk with the stock `try_files` rule — there is no
`location /data/` block to remember, and forgetting one used to mean every image
silently returned the HTML shell instead.

**2. Deploy script — leave it alone.** Forge's default for a PHP site runs
`composer install`, and that is all this needs. There is a `composer.json` with
no dependencies whose only job is to hang the database build off Composer's
`post-install-cmd`, so the stock script builds the site:

```bash
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
# → Building .../database/database.sqlite
# → pairs: 48,811 · hr codes: 9,067 · with sch: 2,894 …
```

That keeps the build step in the repository, under version control and visible in
review, rather than in a text box in the Forge UI that nothing backs up.

`database.sqlite` is a build artefact and is not committed; the build takes about
three seconds and needs no database server. It prints its record counts, so a
failed deploy is visible in the Forge deploy log rather than as a broken site.

`composer.json` also declares `php >= 8.1`, `ext-pdo_sqlite` and `ext-json`, so a
host missing one of them fails the deploy with a clear message instead of serving
500s.

Two things will stop this working, both obvious in the deploy log: a deploy
script that passes `--no-scripts`, and one that never runs Composer at all. In
either case the site answers with *"The search index has not been built"* and the
command to run, rather than a blank page.

**2b. Site isolation.** If the site is isolated, everything lives under the
per-site user's home — `/home/<site-user>/<domain>`, not `/home/forge/...` — and
PHP-FPM runs as that user. Nothing in the application cares; it matters only for
the logging setup and the cron entry in `docs/ANALYTICS.md`, both of which take
the user and path as arguments rather than assuming `forge`.

**3. PHP version.** 8.1 or newer, with `pdo_sqlite` — both are the default on
Forge. Nothing else is required: no MySQL or Postgres, no migrations, no queue
worker, no scheduler, no `.env`.

**4. First deployment is slow.** The repository is around 130 MB, almost all of
it schematic images. Give the initial clone a few minutes before assuming it has
hung.

### DNS and the www name

`www` is not special. It is an ordinary subdomain label, exactly like `api` or
`mail` — its universality is convention and nothing else. DNS has no concept of
it, and neither does nginx beyond matching `server_name`.

In Cloudflare, alongside the apex `A` record:

| Type | Name | Target |
|---|---|---|
| A | `flyback-reference.net` (or `@`) | the server's IP |
| CNAME | `www` | `flyback-reference.net` |

Put the **full apex name** in the CNAME target rather than `@`. Cloudflare's UI
accepts `@` there as shorthand, but the target of a CNAME is a name in the wider
DNS, not a position within the zone file, so the fully qualified form is what it
actually means and what every other provider expects. In the *Name* field `www`
alone is right — Cloudflare appends the zone.

An `A` record for `www` pointing at the same IP works just as well. With
Cloudflare proxying enabled it makes no practical difference either way, because
the edge answers both names itself.

**Set both records to the same proxy status.** An apex that is proxied and a
`www` that is not will behave differently — different IPs, different TLS,
different logs — and the difference only shows up for whichever name you did not
test.

### TLS, in the order that works

1. **DNS first.** Both records resolving, before anything else.
2. **Certificate covering both names.** In Forge, add `www.<domain>` to the
   site's aliases *before* requesting the Let's Encrypt certificate, and tick
   both when you request it. A redirect from a name the certificate does not
   cover fails with a certificate warning before it ever redirects — the browser
   validates the certificate first.
3. **Then the redirect.** Forge's redirect from `www` to the apex.

Two Cloudflare settings will waste an afternoon if they are wrong:

- **SSL/TLS mode must be Full (strict), not Flexible.** Flexible means Cloudflare
  speaks HTTP to the origin; Forge redirects HTTP to HTTPS; Cloudflare follows it
  back to itself. That is an infinite redirect loop, and it looks like a broken
  application rather than a misconfigured proxy.
- **Issuing the certificate through an orange-clouded record** sometimes fails
  HTTP-01 validation. If it does, set the record to DNS-only for the couple of
  minutes it takes, then turn the proxy back on.

### Optional

Set `APP_DEBUG=1` in the site's environment only while diagnosing something —
without it, errors are logged and the response says nothing useful, which is the
correct behaviour on a public host.

If symlinks are disabled on your server, serve the images with an nginx block
instead and the application will work identically:

```nginx
location /data/ {
    alias /home/forge/<your-site>/data/;
    expires 30d;
    access_log off;
}
```

Put a CDN in front if you expect a burst of traffic. The API responses are small
and cached, but the images are not: they are the bulk of the bandwidth, they
never change, and a free Cloudflare tier will absorb essentially all of it.
