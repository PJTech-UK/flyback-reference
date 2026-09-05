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
<link rel="stylesheet" href="/styles.css">
</head>
<body>
<header>
 <div class="wrap">
  <nav class="topbar" aria-label="Site links">
    <a class="linkish" href="/">Search the database</a>
    <a class="linkish" href="/parts">All parts</a>
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

        $oems = array_column($equivs, 'oem');
        $desc = "$code line-output transformer"
              . ($oems ? '. Equivalent to ' . implode(', ', array_slice($oems, 0, 6)) : '')
              . ($uses ? '. Fitted to ' . implode(', ', array_map(
                    fn($u) => trim($u['fabname'] . ' ' . $u['model']), array_slice($uses, 0, 4))) : '')
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
        if ($note) $h .= '<h2>Notes</h2><p>' . self::e($note[0]['note']) . '</p>';
        if ($uses) {
            $h .= '<h2>Fitted to (' . count($uses) . ')</h2><p class="codes">'
                . implode(' &middot; ', array_map(
                    fn($u) => self::e(trim($u['fabname'] . ' ' . $u['model'])), $uses)) . '</p>';
        }
        $h .= '<p><a class="linkish" href="/?q=' . rawurlencode($code)
            . '">Search this part in the full database</a></p>';

        $title = "$code — flyback / LOPT equivalents and specifications";
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

    public static function sitemap(PDO $db): string
    {
        $base = self::base();
        $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
             . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
             . "<url><loc>$base/</loc><priority>1.0</priority></url>\n";
        $total = (int)$db->query('SELECT COUNT(*) FROM hr')->fetchColumn();
        for ($p = 1, $n = (int)ceil($total / self::PER_PAGE); $p <= $n; $p++) {
            $out .= '<url><loc>' . $base . '/parts' . ($p > 1 ? '/' . $p : '')
                  . "</loc><priority>0.5</priority></url>\n";
        }
        foreach ($db->query('SELECT code FROM hr ORDER BY code') as $r) {
            $out .= '<url><loc>' . $base . '/part/' . self::slug($r['code'])
                  . "</loc><priority>0.7</priority></url>\n";
        }
        return $out . "</urlset>\n";
    }
}
