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

// --- Anything else: serve the single-page app shell -------------------------
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
