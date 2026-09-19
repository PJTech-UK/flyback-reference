<?php
/**
 * Front controller.
 *
 *   /api/catalog            → factor catalogue (drives the builder + help modal)
 *   /api/search?q=…&page=…  → one page of hydrated results
 *   /api/hr/{code}          → a single hydrated record (deep links)
 *   anything else           → the SPA shell (app.html)
 *
 * Works behind nginx (Forge) and under the PHP built-in server. In production,
 * nginx serves /data/* and static assets directly; under `php -S` this script
 * also serves them (see the cli-server block below) so local dev needs no config.
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);        // project root

// --- PHP built-in server: serve real static files itself --------------------
if (PHP_SAPI === 'cli-server') {
    $reqPath = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
    // Schematic / family / box images live outside the doc root, under data/.
    if (preg_match('#^/data/#', $reqPath)) {
        // urldecode() happens after parse_url(), so %2e%2e%2f arrives here as
        // "../" already past the web server's own path normalisation. Resolve
        // the result and require it to still be inside data/ — without this the
        // dev server will hand out any file the PHP process can read.
        $dataRoot = realpath($ROOT . '/data');
        $file     = realpath($ROOT . $reqPath);
        if ($dataRoot !== false && $file !== false
            && str_starts_with($file, $dataRoot . DIRECTORY_SEPARATOR)
            && is_file($file)) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $types = ['gif' => 'image/gif', 'png' => 'image/png', 'jpg' => 'image/jpeg',
                      'jpeg' => 'image/jpeg', 'pdf' => 'application/pdf', 'svg' => 'image/svg+xml'];
            header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
            readfile($file);
        } else {
            http_response_code(404);
        }
        return true;
    }
    // Other existing files in public/ (app.js, styles.css) → let the server handle.
    // Never hand back index.php itself: the built-in server would re-enter this
    // script to serve it and fail. nginx does not have the problem, but the
    // 500 it produced locally looked like a broken application.
    if ($reqPath !== '/' && $reqPath !== '/index.php' && is_file(__DIR__ . $reqPath)) return false;
}

require __DIR__ . '/../src/util.php';
require __DIR__ . '/../src/Db.php';
require __DIR__ . '/../src/Catalog.php';
require __DIR__ . '/../src/QueryParser.php';
require __DIR__ . '/../src/QueryCompiler.php';
require __DIR__ . '/../src/Suggest.php';
require __DIR__ . '/../src/Page.php';
require __DIR__ . '/../src/Search.php';

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';

function sendJson($data, int $code = 200, int $maxAge = 60): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=' . $maxAge);

    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    // A result page is mostly repeated part numbers and model names, so it
    // compresses about twelve to one — 278 KB down to 23 KB on the worst query.
    // Shared hosting does not always have compression switched on, so do it here
    // when nothing upstream has claimed the job. Small bodies are left alone;
    // the header costs more than the saving.
    if (strlen($json) > 4096
        && !ini_get('zlib.output_compression')
        && !headers_sent()
        && function_exists('gzencode')
        && str_contains((string)($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''), 'gzip')) {
        $gz = gzencode($json, 5);
        if ($gz !== false) {
            header('Content-Encoding: gzip');
            header('Vary: Accept-Encoding');
            $json = $gz;
        }
    }
    echo $json;
    exit;
}

/**
 * A real 404, with a real body.
 *
 * Everything the router does not recognise used to fall through to the
 * single-page shell and return 200. To a person that looks like an empty search
 * box; to a crawler it is a "soft 404" — an unlimited supply of URLs that all
 * answer 200 with identical content. Google demotes sites that do it, and with
 * ten thousand URLs in the sitemap any stale or mistyped one was feeding the
 * problem. Unknown paths now say so with the correct status.
 */
function notFound(string $what, string $suggest = ''): void
{
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: public, max-age=300');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>Not found</title>'
       . '<meta name="robots" content="noindex">'
       . '<link rel="icon" href="/favicon.ico" sizes="48x48">'
       . '<link rel="icon" href="/favicon.svg" type="image/svg+xml">'
       . '<link rel="stylesheet" href="/styles.css">'
       . '</head><body><div class="wrap" style="padding:40px 20px;max-width:640px">'
       . '<h1 style="font-size:22px;margin:0 0 10px">' . htmlspecialchars($what, ENT_QUOTES) . '</h1>'
       . '<p>' . ($suggest !== '' ? htmlspecialchars($suggest, ENT_QUOTES) . ' ' : '')
       . 'Try a <a href="/">search</a>, or browse '
       . '<a href="/parts">all parts</a> or <a href="/makes">all manufacturers</a>.</p>'
       . '</div></body></html>';
    exit;
}

try {
    if ($path === '/api/catalog') {
        // Fixed for the life of a deployment — every visitor fetches this once
        // on load, so let browsers and any CDN in front keep it for an hour.
        sendJson(Catalog::dynamic(Db::get()), 200, 3600);
    }

    if ($path === '/api/search') {
        $opt = [
            'q'        => $_GET['q'] ?? '',
            'category' => $_GET['category'] ?? '',
            'page'     => $_GET['page'] ?? 1,
            'sort'     => $_GET['sort'] ?? 'code',
            'eht'      => $_GET['eht'] ?? 24,
            'onlyImgs' => isset($_GET['onlyImgs']) && $_GET['onlyImgs'] !== '0' && $_GET['onlyImgs'] !== 'false',
            'uses'     => !isset($_GET['uses']) || ($_GET['uses'] !== '0' && $_GET['uses'] !== 'false'),
        ];
        sendJson((new Search(Db::get()))->run($opt));
    }

    if (preg_match('#^/api/hr/(.+)$#', $path, $m)) {
        $rec = (new Search(Db::get()))->one(urldecode($m[1]));
        $rec ? sendJson($rec) : sendJson(['error' => 'not found'], 404);
    }

    if (str_starts_with($path, '/api/')) {
        sendJson(['error' => 'unknown endpoint'], 404);
    }

    // --- server-rendered pages, for crawlers and for no-JavaScript ----------
    //
    // The application draws everything with fetch, so a crawler sees the shell
    // and not one part number. These carry the same records as plain HTML at a
    // stable URL, cross-linked so the whole dataset is reachable.
    function sendHtml(string $html, int $code = 200, int $maxAge = 3600): void
    {
        http_response_code($code);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: public, max-age=' . $maxAge);
        echo $html;
        exit;
    }

    if (preg_match('#^/part/([^/]+)/?$#', $path, $m)) {
        $html = Page::part(Db::get(), urldecode($m[1]));
        if ($html !== null) sendHtml($html);
        notFound('No such part', 'That part number is not in the archive.');
    }

    if ($path === '/makes' || $path === '/makes/') {
        sendHtml(Page::makes(Db::get()));
    }

    if (preg_match('#^/make/([^/]+?)(?:/(\d+))?/?$#', $path, $m)) {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '', urldecode($m[1])));
        $html = Page::make(Db::get(), $slug, isset($m[2]) ? (int)$m[2] : 1);
        if ($html !== null) sendHtml($html);
        // /make/thorn is not a page of its own — it is another name for a make
        // the catalogue files as FERGUSON-THORN-EMI. Redirect rather than serve
        // the same models at two URLs: duplicate content at scale is exactly
        // what the indexing work was undoing.
        $canon = Page::makeAlias(Db::get(), $slug);
        if ($canon !== null) {
            header('Location: /make/' . Page::slug($canon)
                   . (isset($m[2]) ? '/' . (int)$m[2] : ''), true, 301);
            exit;
        }
        notFound('No such manufacturer', 'That manufacturer is not in the archive.');
    }

    if (preg_match('#^/parts(?:/(\d+))?/?$#', $path, $m)) {
        $html = Page::index(Db::get(), isset($m[1]) ? (int)$m[1] : 1);
        if ($html !== null) sendHtml($html);
        notFound('No such page', 'That page number is past the end of the list.');
    }

    // /sitemap.xml is now an index pointing at one child sitemap per kind of
    // page, so Search Console reports indexing separately for each.
    if ($path === '/sitemap.xml') {
        header('Content-Type: application/xml; charset=utf-8');
        header('Cache-Control: public, max-age=86400');
        echo Page::sitemapIndex(Db::get());
        exit;
    }

    if (preg_match('#^/sitemap-([a-z-]+)\.xml$#', $path, $m)) {
        $xml = Page::sitemap(Db::get(), $m[1]);
        if ($xml === null) notFound('No such sitemap');
        header('Content-Type: application/xml; charset=utf-8');
        header('Cache-Control: public, max-age=86400');
        echo $xml;
        exit;
    }
} catch (Throwable $e) {
    // A missing database is a deployment state, not a fault, and it is the one
    // failure a first deploy actually hits — the build step is easy to leave out
    // of the deploy script. Naming it saves an afternoon, and says nothing an
    // attacker could use.
    if ($e instanceof RuntimeException && str_contains($e->getMessage(), 'database.sqlite')) {
        sendJson(['error' => 'The search index has not been built. '
                           . 'Run: php bin/build-db.php'], 503);
    }
    // Everything else: the message carries absolute paths and SQL fragments.
    // Log it, do not publish it — on shared hosting that is free reconnaissance.
    error_log('flyback-reference: ' . $e->getMessage());
    $detail = (getenv('APP_DEBUG') === '1') ? ['detail' => $e->getMessage()] : [];
    sendJson(['error' => 'server error'] + $detail, 500);
}

// --- The single-page app shell ----------------------------------------------
//
// The application has no client-side path routing — app.js only ever rewrites
// the query string (history.replaceState against location.pathname) — so the
// shell belongs at "/" and nowhere else. Any other unmatched path is a 404, not
// a silent 200 with an empty search box. See notFound() above.
if ($path !== '/' && $path !== '/index.php') {
    notFound('Page not found');
}

//
// Asset URLs get a ?v=<mtime> stamp. Without it a browser holding a cached
// app.js will happily run it against a newer API response shape — which shows
// up as gibberish in the UI (equivalents rendering as "[object Object]") rather
// than as an obvious error. Stamping means a deploy invalidates the cache.
header('Content-Type: text/html; charset=utf-8');
$shell = file_get_contents(__DIR__ . '/app.html');
$shell = preg_replace_callback(
    '#(?:src|href)="(/(?:app\.js|sourcing\.js|styles\.css))"#',
    function (array $m): string {
        $mtime = @filemtime(__DIR__ . $m[1]) ?: 0;
        return str_replace($m[1], $m[1] . '?v=' . $mtime, $m[0]);
    },
    $shell
);
echo $shell;
