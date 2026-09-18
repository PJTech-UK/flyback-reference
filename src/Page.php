<?php
declare(strict_types=1);

/**
 * Server-rendered pages, for search engines and for anyone without JavaScript.
 *
 * The application is a single page: everything lives behind /?q=… and is drawn
 * by fetch. A crawler sees the shell and no part numbers at all, so nobody
 * searching the number stamped on their transformer can ever find this — which
 * is the one thing the archive exists to do.
 *
 * These pages carry the same records the application shows, as plain HTML, at a
 * stable URL per part, cross-linked to their substitutes so a crawler can walk
 * the whole dataset from any entry point.
 *
 * There is deliberately no page per TV model. 98,000 near-identical pages
 * carrying one line each is what search engines call doorway pages and rank
 * accordingly. The models appear on the part pages instead, where they are
 * content rather than filler.
 */
final class Page
{
    private const PER_PAGE = 250;

    public static function slug(string $code): string { return strtolower(hr_norm($code)); }

    /** SQL-side equivalent of slug(), for matching a make name from a URL. */
    private const SLUG_SQL = "lower(replace(replace(replace(replace(replace(replace(%s,' ',''),'-',''),'.',''),'/',''),'&',''),char(39),''))";

    private static function e(?string $s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function base(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }

    /** Shared chrome. $head is title/description/canonical; $body is the content. */
    private static function shell(string $title, string $desc, string $canonical, string $body): string
    {
        $t = self::e($title); $d = self::e($desc); $c = self::e($canonical);
        return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>$t</title>
<meta name="description" content="$d">
<link rel="canonical" href="$c">
<link rel="icon" href="/favicon.ico" sizes="48x48">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="stylesheet" href="/styles.css">
</head>
<body>
<header>
 <div class="wrap">
  <nav class="topbar" aria-label="Site links">
    <a class="linkish" href="/">Search the database</a>
    <a class="linkish" href="/parts">All parts</a>
    <a class="linkish" href="/makes">By TV make</a>
    <a class="linkish kofi" href="https://ko-fi.com/jonathanpjtech60339" target="_blank" rel="noopener">Support this archive</a>
  </nav>
 </div>
</header>
<main class="static-page">
$body
</main>
<footer>
 <div class="wrap">
  <p>HR Diemen is a trademark of Efiter S.L. This archive is not affiliated with,
  endorsed by, or connected to Efiter or HR Diemen.</p>
  <p><a class="linkish" href="/">Search the database</a>
  <a class="linkish" href="https://github.com/PJTech-UK/flyback-reference"
     target="_blank" rel="noopener">Source &amp; data on GitHub</a>
  <a class="linkish kofi" href="https://ko-fi.com/jonathanpjtech60339" target="_blank" rel="noopener">Support this archive on Ko-fi</a></p>
 </div>
</footer>
</body>
</html>
HTML;
    }


    /**
     * What the part actually is. "HRT 241 BP" is a tripler, and codes that turn
     * up in other parts' accessory lists are leads and control pots — HR 16525
     * is a screen control, and describing it as a line-output transformer, as
     * every one of these pages did, is simply wrong.
     */
    private static function kind(string $code, ?PDO $db = null): string
    {
        if ($db !== null) {
            $acc = self::accessoryMap($db)[trim($code)] ?? null;
            if ($acc !== null && $acc !== 'Type not recorded') return $acc;
        }
        return stripos($code, 'HRT') === 0 ? 'tripler' : 'line-output transformer';
    }

    /**
     * Split an accessory string such as "HR 16502-CA.MAT / HV CA." into its
     * code and a description a reader can act on.
     *
     * The catalogue writes these as a code, a Spanish abbreviation and its
     * English gloss: CA. is "cable", MAT is "muy alta tension", FOCO is focus,
     * BLD is "blindado" — screened. Rendered raw, or omitted as they were until
     * now, the reader sees a bare HR number and cannot tell an EHT lead from a
     * screen control. Someone hunting a Sony screen control read the HV lead as
     * one and went away empty-handed, which is the whole reason this exists.
     *
     * Returns [code, label, recognised].
     */
    private static function accessoryParse(string $s): array
    {
        $code = preg_match('/^(HRT?\s*\d+[A-Z]*)/i', $s, $m) ? trim($m[1]) : trim($s);
        $u = strtoupper($s);
        // BLD first: "CA.AT BLD/HV CA. BLD" carries both markers.
        if (str_contains($u, 'BLD'))                                   return [$code, 'Screened EHT lead', true];
        if (str_contains($u, 'FOCUS') || str_contains($u, 'FOCO'))     return [$code, 'Focus control', true];
        if (str_contains($u, 'SCREEN') || str_contains($u, 'G2'))      return [$code, 'Screen (G2) control', true];
        if (str_contains($u, 'MAT') || str_contains($u, 'HV CA'))      return [$code, 'EHT anode lead', true];
        return [$code, 'Type not recorded', false];
    }

    /**
     * Public face of accessoryParse()/accessoryMap(), for the JSON API: returns
     * ['code' => 'HR 16502', 'label' => 'EHT anode lead'].
     */
    public static function accessory(string $s): array
    {
        [$code, $label] = self::accessoryParse($s);
        return ['code' => $code, 'label' => self::accessoryMap(Db::get())[$code] ?? $label];
    }

    /**
     * code => label across the whole catalogue. OCR truncated a handful of the
     * strings to a bare code ("HR 16524"), and those rows would otherwise say
     * nothing — but the same code is spelled out in full on 116 other parts, so
     * resolve them against their siblings rather than shrugging.
     */
    private static function accessoryMap(PDO $db): array
    {
        static $map = null;
        if ($map !== null) return $map;
        $map = $firm = [];
        foreach ($db->query('SELECT DISTINCT accessory FROM acc') as $r) {
            [$code, $label, $known] = self::accessoryParse((string)$r['accessory']);
            if ($code === '' || (!$known && isset($map[$code]))) continue;
            if ($known && empty($firm[$code])) { $map[$code] = $label; $firm[$code] = true; }
            elseif (!isset($map[$code]))       { $map[$code] = $label; }
        }
        return $map;
    }

    /** One part. Returns null when the slug matches nothing. */
    public static function part(PDO $db, string $slug): ?string
    {
        $st = $db->prepare("SELECT * FROM hr WHERE lower(replace(replace(replace(replace(replace(
                            code,' ',''),'-',''),'_',''),'.',''),'/','')) = ?");
        $st->execute([strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $slug))]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $code = $row['code'];

        $get = function (string $sql) use ($db, $code): array {
            $s = $db->prepare($sql); $s->execute([$code]); return $s->fetchAll(PDO::FETCH_ASSOC);
        };
        $equivs = $get('SELECT oem FROM equivalents WHERE hr = ? AND canon = 1 ORDER BY oem');
        $uses   = $get('SELECT fabname, model FROM uses WHERE hr = ? ORDER BY fabname, model');
        $subs   = $get('SELECT cand FROM substitutes WHERE hr = ?
                        ORDER BY same_pins DESC, mat_ok DESC, shape_d ASC LIMIT 12');
        $imgs   = $get('SELECT kind, path FROM schematics WHERE hr = ?');
        $note   = $get('SELECT note FROM obs WHERE hr = ?');
        $accs   = $get('SELECT DISTINCT accessory FROM acc WHERE hr = ?');

        $oems  = array_column($equivs, 'oem');
        $kind  = self::kind($code, $db);
        $makes = array_values(array_unique(array_filter(
            array_map(fn($u) => trim((string)$u['fabname']), $uses))));

        $desc = "$code $kind"
              . ($oems ? '. Equivalent to ' . implode(', ', array_slice($oems, 0, 6)) : '')
              . ($uses ? '. Fitted to ' . implode(', ', array_map(
                    fn($u) => trim($u['fabname'] . ' ' . $u['model']), array_slice($uses, 0, 4))) : '')
              . ($accs ? '. Accessories: ' . implode(', ', array_map(
                    fn($a) => self::accessoryParse((string)$a['accessory'])[0], $accs)) : '')
              . '.';
        $desc = mb_substr($desc, 0, 300);

        $h = '<h1>' . self::e($code) . '</h1>';
        $bits = [];
        if ($row['tester_type']) $bits[] = 'type ' . self::e($row['tester_type']);
        if ($row['family'])      $bits[] = 'family ' . self::e($row['family']);
        if ($row['mat_kv'])      $bits[] = self::e((string)$row['mat_kv']) . ' kV maximum anode voltage';
        if ($row['alim_or_deflection']) $bits[] = self::e($row['alim_or_deflection']) . '&deg; deflection';
        if ($row['n_wnd'])       $bits[] = self::e((string)$row['n_wnd']) . ' windings';
        if ($row['weight_g'])    $bits[] = self::e((string)$row['weight_g']) . ' g';
        if ($bits) $h .= '<p class="meta-line">' . implode(' &middot; ', $bits) . '</p>';

        foreach ($imgs as $im) {
            $h .= '<figure><img src="' . self::e(preg_replace('#^\.\./#', '/', $im['path']))
                . '" alt="Schematic diagram for ' . self::e($code) . '" loading="lazy">'
                . '<figcaption>' . self::e($im['kind']) . '</figcaption></figure>';
        }

        if ($oems) {
            $h .= '<h2>Manufacturer equivalents (' . count($oems) . ')</h2><p class="codes">'
                . implode(' &middot; ', array_map([self::class, 'e'], $oems)) . '</p>';
        }
        if ($subs) {
            $h .= '<h2>Possible substitutes</h2><p>Parts of a similar design. A shortlist to '
                . 'check, not a recommendation.</p><p class="codes">'
                . implode(' &middot; ', array_map(
                    fn($s) => '<a href="/part/' . self::e(self::slug($s['cand'])) . '">'
                            . self::e($s['cand']) . '</a>', $subs)) . '</p>';
        }
        if ($accs) {
            $map = self::accessoryMap($db);
            $h .= '<h2>Accessories (' . count($accs) . ')</h2>'
                . '<p>Parts the catalogue lists alongside this one. They are separate '
                . 'items, not part of the transformer.</p><ul class="acc-list">';
            foreach ($accs as $a) {
                [$ac] = self::accessoryParse((string)$a['accessory']);
                $h .= '<li><b>' . self::e($ac) . '</b> &mdash; '
                    . self::e($map[$ac] ?? 'Type not recorded') . '</li>';
            }
            $h .= '</ul>';
        }
        if ($note) $h .= '<h2>Notes</h2><p>' . self::e($note[0]['note']) . '</p>';
        if ($uses) {
            $h .= '<h2>Fitted to (' . count($uses) . ')</h2><p class="codes">'
                . implode(' &middot; ', array_map(
                    fn($u) => self::e(trim($u['fabname'] . ' ' . $u['model'])), $uses)) . '</p>';
        }
        $h .= '<p><a class="linkish" href="/?q=' . rawurlencode($code)
            . '">Search this part in the full database</a></p>';

        // Every one of these pages used to be titled "<code> — flyback / LOPT
        // equivalents and specifications". Nine thousand titles differing only in
        // the code read as templated duplication, and Google indexed almost none
        // of them. The distinguishing fact is what the part was fitted to, so
        // lead with that, and fall back to the equivalent code when there is no
        // fitment — which is still something nobody else's page says.
        if ($uses && $makes) {
            if (count($uses) === 1) {
                $title = "$code — " . trim($uses[0]['fabname'] . ' ' . $uses[0]['model']) . " $kind";
            } elseif (count($makes) === 1) {
                $title = "$code — {$makes[0]} $kind, " . count($uses) . ' sets';
            } else {
                $title = "$code — $kind for " . $makes[0] . ', ' . $makes[1]
                       . (count($makes) > 2 ? ' and ' . (count($makes) - 2) . ' more' : '');
            }
        } elseif ($oems) {
            $title = "$code — $kind equivalent to " . $oems[0]
                   . (count($oems) > 1 ? ' and ' . (count($oems) - 1) . ' more' : '');
        } else {
            $title = "$code — $kind cross-reference";
        }
        return self::shell($title, $desc, self::base() . '/part/' . self::slug($code), $h);
    }

    /** Paginated index, so a crawler can reach every part without the sitemap. */
    public static function index(PDO $db, int $page): ?string
    {
        $total = (int)$db->query('SELECT COUNT(*) FROM hr')->fetchColumn();
        $pages = max(1, (int)ceil($total / self::PER_PAGE));
        if ($page < 1 || $page > $pages) return null;

        $st = $db->prepare('SELECT code, n_equiv, n_uses FROM hr ORDER BY code LIMIT ? OFFSET ?');
        $st->execute([self::PER_PAGE, ($page - 1) * self::PER_PAGE]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $h = '<h1>All parts' . ($page > 1 ? " — page $page of $pages" : '') . '</h1>'
           . '<p>' . number_format($total) . ' line-output transformers, triplers and related '
           . 'EHT components.</p><ul class="part-index">';
        foreach ($rows as $r) {
            $h .= '<li><a href="/part/' . self::e(self::slug($r['code'])) . '">'
                . self::e($r['code']) . '</a> <span>' . (int)$r['n_equiv'] . ' equivalents, '
                . (int)$r['n_uses'] . ' sets</span></li>';
        }
        $h .= '</ul><nav class="pager">';
        if ($page > 1)      $h .= '<a href="/parts' . ($page - 1 > 1 ? '/' . ($page - 1) : '') . '">Previous</a> ';
        if ($page < $pages) $h .= '<a href="/parts/' . ($page + 1) . '">Next</a>';
        $h .= '</nav>';

        return self::shell(
            'All parts' . ($page > 1 ? " — page $page" : '') . ' — Flyback & LOPT Cross-Reference',
            'Index of ' . number_format($total) . ' CRT line-output transformers, triplers and '
                . 'EHT components, with their manufacturer equivalents.',
            self::base() . '/parts' . ($page > 1 ? '/' . $page : ''), $h);
    }

    /** Every set manufacturer, A-Z. The original catalogue started here: you
     *  picked a make, then a model. Make and model are different things and the
     *  interface should not blur them. */
    public static function makes(PDO $db): string
    {
        $rows = $db->query("SELECT fabname, COUNT(DISTINCT model) m, COUNT(DISTINCT hr) p
                            FROM uses WHERE fabname <> '' GROUP BY fabname
                            ORDER BY fabname")->fetchAll(PDO::FETCH_ASSOC);
        $groups = [];
        foreach ($rows as $r) {
            $init = strtoupper(mb_substr(ltrim($r['fabname']), 0, 1));
            if (!preg_match('/[A-Z]/', $init)) $init = '#';
            $groups[$init][] = $r;
        }
        ksort($groups);
        $h = '<h1>TV and monitor manufacturers</h1><p>' . number_format(count($rows))
           . ' makes appear in the fitment lists. Pick one to see its models, or search a '
           . 'part number directly.</p>';
        $h .= '<p class="az">' . implode(' ', array_map(
            fn($k) => '<a href="#' . self::e($k) . '">' . self::e($k) . '</a>', array_keys($groups))) . '</p>';
        foreach ($groups as $init => $list) {
            $h .= '<h2 id="' . self::e($init) . '">' . self::e($init) . '</h2><ul class="part-index">';
            foreach ($list as $r) {
                $h .= '<li><a href="/make/' . self::e(self::slug($r['fabname'])) . '">'
                    . self::e($r['fabname']) . '</a> <span>' . number_format((int)$r['m'])
                    . ' models</span></li>';
            }
            $h .= '</ul>';
        }
        return self::shell('TV and monitor manufacturers — Flyback & LOPT Cross-Reference',
            number_format(count($rows)) . ' TV and monitor manufacturers whose sets appear in this '
            . 'flyback and LOPT cross-reference, with the models recorded for each.',
            self::base() . '/makes', $h);
    }

    /** One manufacturer: its models, and the part each was fitted with. */
    public static function make(PDO $db, string $slug, int $page = 1): ?string
    {
        // Match in PHP against slug(), rather than reimplementing it in SQL: a
        // second normaliser that drifts from the first is a bug waiting to
        // happen, and it already cost two makes whose names contain "&".
        $fab = null;
        foreach ($db->query("SELECT DISTINCT fabname FROM uses WHERE fabname <> ''") as $r) {
            if (self::slug($r['fabname']) === $slug) { $fab = $r['fabname']; break; }
        }
        if ($fab === null) return null;

        $st = $db->prepare('SELECT COUNT(DISTINCT model) FROM uses WHERE fabname = ?');
        $st->execute([$fab]);
        $total = (int)$st->fetchColumn();
        $pages = max(1, (int)ceil($total / self::PER_PAGE));
        if ($page < 1 || $page > $pages) return null;

        $st = $db->prepare("SELECT model, group_concat(DISTINCT hr) hrs, MAX(IFNULL(src,'')) src
                            FROM uses WHERE fabname = ? GROUP BY model ORDER BY model
                            LIMIT ? OFFSET ?");
        $st->execute([$fab, self::PER_PAGE, ($page - 1) * self::PER_PAGE]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $h = '<h1>' . self::e($fab) . ($page > 1 ? " — page $page of $pages" : '') . '</h1><p>'
           . number_format($total)
           . ' models recorded, with the line-output transformer or tripler each was fitted with.</p>'
           . '<table class="model-table"><thead><tr><th>Model</th><th>Parts</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $codes = array_slice(array_unique(explode(',', (string)$r['hrs'])), 0, 8);
            $h .= '<tr><td>' . self::e($r['model'] ?: '(unspecified)')
                . ($r['src'] ? ' <abbr title="Contributed, or read from a service manual">*</abbr>' : '')
                . '</td><td class="codes">' . implode(' ', array_map(
                    fn($c) => '<a href="/part/' . self::e(self::slug($c)) . '">' . self::e($c) . '</a>',
                    $codes)) . '</td></tr>';
        }
        $h .= '</tbody></table><nav class="pager">';
        $slugf = self::slug($fab);
        if ($page > 1)      $h .= '<a href="/make/' . $slugf . ($page - 1 > 1 ? '/' . ($page - 1) : '') . '">Previous</a> ';
        if ($page < $pages) $h .= '<a href="/make/' . $slugf . '/' . ($page + 1) . '">Next</a>';
        $h .= '</nav><p><a class="linkish" href="/makes">All manufacturers</a></p>';

        // Name real models in the title and description. "Sony — flyback / LOPT
        // parts by model" is indistinguishable from fourteen hundred siblings;
        // "Sony KX 2501, KV 2212 UB" is what somebody actually types.
        $sample = array_slice(array_filter(array_column($rows, 'model')), 0, 3);
        return self::shell(
            self::e($fab) . ($page > 1 ? " — page $page" : '')
                . ' — flyback, LOPT and tripler parts by model',
            'Line-output transformers, flybacks and triplers for ' . $fab . ' televisions and '
            . 'monitors: ' . number_format($total) . ' models'
            . ($sample ? ' including ' . implode(', ', $sample) : '')
            . ', each with the part it was fitted with.',
            self::base() . '/make/' . self::slug($fab) . ($page > 1 ? '/' . $page : ''), $h);
    }

    /**
     * A sitemap index rather than one flat list.
     *
     * Google reports coverage per submitted sitemap, so splitting the URLs by
     * what kind of page they are turns "8,000 not indexed" — which says nothing
     * about why — into a per-group figure: parts with fitment data, parts that
     * carry only equivalent codes, and manufacturer pages. That is a diagnosis
     * you can act on instead of a number you can only stare at.
     */
    public const SITEMAPS = ['core', 'makes', 'parts-fitted', 'parts-codes'];

    public static function sitemapIndex(PDO $db): string
    {
        $base = self::base();
        $mod  = self::generated($db);
        $out  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
              . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach (self::SITEMAPS as $name) {
            $out .= "<sitemap><loc>$base/sitemap-$name.xml</loc><lastmod>$mod</lastmod></sitemap>\n";
        }
        return $out . "</sitemapindex>\n";
    }

    /** One child sitemap. Returns null for a name that is not one of ours. */
    public static function sitemap(PDO $db, string $which): ?string
    {
        $base = self::base();
        $mod  = self::generated($db);
        $locs = [];

        switch ($which) {
            case 'core':
                $locs[] = "$base/";
                $locs[] = "$base/makes";
                $total = (int)$db->query('SELECT COUNT(*) FROM hr')->fetchColumn();
                for ($p = 1, $n = (int)ceil($total / self::PER_PAGE); $p <= $n; $p++) {
                    $locs[] = $base . '/parts' . ($p > 1 ? '/' . $p : '');
                }
                break;

            case 'makes':
                foreach ($db->query("SELECT DISTINCT fabname FROM uses WHERE fabname <> ''
                                     ORDER BY fabname") as $r) {
                    $locs[] = $base . '/make/' . self::slug($r['fabname']);
                }
                break;

            // Parts that name the sets they were fitted to. These are the pages
            // worth a crawl budget, so they are advertised on their own.
            case 'parts-fitted':
                foreach ($db->query('SELECT code FROM hr WHERE n_uses > 0 ORDER BY code') as $r) {
                    $locs[] = $base . '/part/' . self::slug($r['code']);
                }
                break;

            // Parts carrying equivalent codes but no fitment. Thinner, and still
            // the only page on the web that resolves some of those codes, so they
            // stay in the sitemap — just separated, so their indexing rate can be
            // read off on its own.
            case 'parts-codes':
                foreach ($db->query('SELECT code FROM hr WHERE n_uses = 0 ORDER BY code') as $r) {
                    $locs[] = $base . '/part/' . self::slug($r['code']);
                }
                break;

            default:
                return null;
        }

        $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
             . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($locs as $l) $out .= '<url><loc>' . $l . "</loc><lastmod>$mod</lastmod></url>\n";
        return $out . "</urlset>\n";
    }

    /** Build date of the dataset, as the date half of an ISO timestamp. */
    private static function generated(PDO $db): string
    {
        $st = $db->query("SELECT value FROM meta WHERE key = 'generated'");
        $v  = $st ? (string)$st->fetchColumn() : '';
        return substr($v, 0, 10) ?: date('Y-m-d');
    }
}
