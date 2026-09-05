<?php
declare(strict_types=1);

/**
 * Runs a search end-to-end: parse → compile → execute → sort → paginate →
 * hydrate. Returns plain arrays ready to json_encode for the front-end, which
 * renders each result card (and computes the per-card Uf estimate in JS).
 */
final class Search
{
    public const PER_PAGE = 50;

    public function __construct(private PDO $db) {}

    /**
     * Coarse part-type filter for the selector beside the search box. Returns a
     * constant SQL fragment (no user value is interpolated — `$cat` is one of a
     * fixed whitelist), or null for "any type". The dataset has no scan-frequency
     * field, so these are proxied from the tester `tester_type` plus the HRT code prefix:
     *   tripler → TR / HRT-…   lopt15 → ST (TV)   lopt32 → SM (monitor)
     *   misc    → everything else (untyped parts, choppers, MC/BN, …)
     */
    private static function categorySql(string $cat): ?string
    {
        switch ($cat) {
            case 'tripler': return "(UPPER(IFNULL(hr.tester_type,'')) = 'TR' OR hr.code LIKE 'HRT%')";
            case 'lopt15':  return "UPPER(IFNULL(hr.tester_type,'')) = 'ST'";
            case 'lopt32':  return "UPPER(IFNULL(hr.tester_type,'')) = 'SM'";
            case 'misc':    return "NOT (UPPER(IFNULL(hr.tester_type,'')) IN ('ST','SM','TR') OR hr.code LIKE 'HRT%')";
            default:        return null;   // '' / 'any' / unknown → no constraint
        }
    }

    /** Rewrite a stored "../data/..." asset path to the web URL "/data/...". */
    private static function assetUrl(?string $p): ?string
    {
        if ($p === null || $p === '') return $p;
        return preg_replace('#^\.\./#', '/', $p);
    }

    public function run(array $opt): array
    {
        $q       = trim((string)($opt['q'] ?? ''));
        $page    = max(1, (int)($opt['page'] ?? 1));
        $sort    = (string)($opt['sort'] ?? 'code');
        $eht     = (float)($opt['eht'] ?? 24);
        if (!($eht > 0)) $eht = 24.0;
        $onlyImgs    = !empty($opt['onlyImgs']);
        $includeUses = $opt['uses'] ?? true;
        $catSql      = self::categorySql((string)($opt['category'] ?? ''));

        // Nothing to search on (no text, no category) → the "type a part code" hint.
        if ($q === '' && $catSql === null) {
            return ['empty' => true, 'total' => 0, 'page' => 1, 'pages' => 0, 'results' => [], 'eht' => $eht];
        }

        $ast = $q === '' ? null : QueryParser::parse($q);   // category-only search → match-all text
        $compiled = (new QueryCompiler($this->db, $eht, (bool)$includeUses))->compile($ast);

        $where = $compiled['sql'];
        if ($catSql !== null) $where = "($where) AND $catSql";
        if ($onlyImgs) $where = "($where) AND hr.has_image = 1";

        $sql = "SELECT hr.code, r.top, r.bot, r.pot, r.total
                FROM hr LEFT JOIN hrt_r r ON r.hr = hr.code
                WHERE $where";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($compiled['params']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Compute Uf per row for sorting/filtering display, then sort in PHP.
        foreach ($rows as &$row) {
            $u = hr_uf_range(
                $row['top'] !== null ? (float)$row['top'] : null,
                $row['bot'] !== null ? (float)$row['bot'] : null,
                $row['pot'] !== null ? (float)$row['pot'] : null, $eht);
            $row['uf_min'] = $u['min'] ?? null;
            $row['uf_max'] = $u['max'] ?? null;
            $row['uf_nom'] = $u ? ($u['min'] + $u['max']) / 2 : null;
        }
        unset($row);

        $rows = $this->sortRows($rows, $sort);

        $total = count($rows);
        $pages = (int)ceil($total / self::PER_PAGE);
        $slice = array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE);
        $codes = array_column($slice, 'code');

        $out = [
            'empty'   => false,
            'total'   => $total,
            'page'    => $page,
            'pages'   => $pages,
            'eht'     => $eht,
            'results' => $this->hydrate($codes, $compiled['terms'], $compiled['useTerms']),
        ];
        // Nothing found: see whether a word in the query is one edit off a brand
        // we know. Offered, never applied — see Suggest.php for why fuzzy
        // matching must not widen a search on this vocabulary.
        if ($total === 0) {
            $s = Suggest::forQuery($this->db, $q);
            if ($s !== null) $out['suggestion'] = $s;
        }
        return $out;
    }

    /** Rows the search named, then others for context, up to a cap. */
    private const USES_CAP = 250;

    private static function capUses(array $rows, array $useTerms): array
    {
        $hits = []; $rest = [];
        foreach ($rows as $u) {
            $row = ['fab' => $u['fab'], 'fabname' => $u['fabname'], 'model' => $u['model']];
            $isHit = false;
            foreach ($useTerms as $t) {
                if ($t !== '' && str_contains((string)$u['model_norm'], $t)) { $isHit = true; break; }
            }
            if ($isHit) { $row['hit'] = true; $hits[] = $row; } else { $rest[] = $row; }
        }
        return array_merge($hits, array_slice($rest, 0, max(0, self::USES_CAP - count($hits))));
    }

    private function sortRows(array $rows, string $sort): array
    {
        $inf = INF;
        $natural = function (string $c): string {
            return preg_replace_callback('/\d+/', fn($m) => str_pad($m[0], 8, '0', STR_PAD_LEFT), $c);
        };
        usort($rows, function ($a, $b) use ($sort, $inf, $natural) {
            switch ($sort) {
                case 'uf':    return ($a['uf_nom'] ?? $inf) <=> ($b['uf_nom'] ?? $inf);
                case 'ufmin': return ($a['uf_min'] ?? $inf) <=> ($b['uf_min'] ?? $inf);
                case 'ufmax': return ($b['uf_max'] ?? -$inf) <=> ($a['uf_max'] ?? -$inf);
                case 'total': return ($a['total'] ?? $inf) <=> ($b['total'] ?? $inf);
                default:      return $natural($a['code']) <=> $natural($b['code']);
            }
        });
        return $rows;
    }

    /** Fetch one HR by exact code (deep-link / share). */
    public function one(string $code): ?array
    {
        $s = $this->db->prepare('SELECT code FROM hr WHERE code = ?');
        $s->execute([$code]);
        if ($s->fetchColumn() === false) return null;
        $r = $this->hydrate([$code], []);
        return $r[0] ?? null;
    }

    /** Build full result objects for a page of HR codes (batched queries). */
    private function hydrate(array $codes, array $terms, array $useTerms = []): array
    {
        if (!$codes) return [];
        $ph = implode(',', array_fill(0, count($codes), '?'));

        // Base hr rows
        $hrRows = [];
        $s = $this->db->prepare("SELECT * FROM hr WHERE code IN ($ph)");
        $s->execute($codes);
        foreach ($s as $r) $hrRows[$r['code']] = $r;

        // Grouped child tables
        $group = function (string $sql) use ($ph, $codes): array {
            $st = $this->db->prepare($sql);
            $st->execute($codes);
            $out = [];
            foreach ($st as $row) $out[$row['hr']][] = $row;
            return $out;
        };
        // canon=1 is one row per distinct part; the other spellings of it ride
        // along in `alts` so the card can mention them without a chip each.
        $equivs = $group("SELECT hr, oem, oem_norm, src, alts FROM equivalents
                          WHERE hr IN ($ph) AND canon = 1");
        // every spelling, for deciding which chips a free-text search lit up
        $allEq  = $group("SELECT hr, oem, oem_norm FROM equivalents WHERE hr IN ($ph)");
        $images = $group("SELECT hr, kind, path FROM schematics WHERE hr IN ($ph)");
        $vpps   = $group("SELECT hr, v, pin, pol, side, wnd FROM vpps WHERE hr IN ($ph)");
        $roles  = $group("SELECT hr, role FROM roles WHERE hr IN ($ph)");
        $pinfn  = $group("SELECT hr, oem, pin, function, volts, src FROM pinfunc
                          WHERE hr IN ($ph) ORDER BY oem, pin");
        $manuals = $group("SELECT hr, chassis, url, note, confidence FROM manuals
                           WHERE hr IN ($ph)");
        // Candidate substitutes, best first: no rewiring beats rewiring, an
        // adequate anode rating beats an inadequate one, then closeness of fit.
        $subs   = $group("SELECT s.hr, s.cand, s.shape_d, s.scale, s.same_pins, s.mat_ok,
                                 s.n_extra, s.n_missing, h.mat_kv, h.family
                          FROM substitutes s JOIN hr h ON h.code = s.cand
                          WHERE s.hr IN ($ph)
                          ORDER BY s.same_pins DESC, s.mat_ok DESC, s.shape_d ASC");
        // model_norm is norm("fabname model"), so one substring test covers a
        // free-text term, a make: term and a model: term alike.
        $uses   = $group("SELECT hr, fab, fabname, model, model_norm FROM uses WHERE hr IN ($ph)");
        $accs   = $group("SELECT hr, accessory FROM acc WHERE hr IN ($ph)");
        $obs    = [];
        $st = $this->db->prepare("SELECT hr, note FROM obs WHERE hr IN ($ph)");
        $st->execute($codes);
        foreach ($st as $row) $obs[$row['hr']] = $row['note'];

        $results = [];
        foreach ($codes as $code) {                 // preserve sort order
            $row = $hrRows[$code] ?? ['code' => $code];

            // matched OEM codes (free-text highlight). Matching is on the
            // normalised form, so a hit on any spelling lights the canon chip.
            $matchedNorm = [];
            foreach ($allEq[$code] ?? [] as $e) {
                foreach ($terms as $t) {
                    if ($t !== '' && str_contains($e['oem_norm'], $t)) {
                        $matchedNorm[$e['oem_norm']] = true;
                        break;
                    }
                }
            }
            $matched = [];
            foreach ($equivs[$code] ?? [] as $e) {
                if (isset($matchedNorm[$e['oem_norm']])) $matched[] = $e['oem'];
            }

            // schematic-extract object (or null)
            $sch = null;
            if ($row['n_wnd'] !== null || isset($vpps[$code]) || isset($roles[$code])) {
                $sch = [
                    'n_wnd' => $row['n_wnd'] !== null ? (int)$row['n_wnd'] : null,
                    'n_seg' => $row['n_seg'] !== null ? (int)$row['n_seg'] : null,
                    'v_min' => $row['v_min'] !== null ? (int)$row['v_min'] : null,
                    'v_max' => $row['v_max'] !== null ? (int)$row['v_max'] : null,
                    'pol_pos' => (int)$row['pol_pos'],
                    'pol_neg' => (int)$row['pol_neg'],
                    'vpps' => array_map(fn($v) => [
                        'v' => $v['v'] !== null ? (int)$v['v'] : null,
                        'pin' => $v['pin'], 'pol' => $v['pol'], 'side' => $v['side'],
                    ], $vpps[$code] ?? []),
                    'roles' => array_map(fn($r) => $r['role'], $roles[$code] ?? []),
                ];
            }

            // hrt_r object (or null)
            $hrt = null;
            $rr = $this->db->prepare('SELECT total, top, bot, pot, verified FROM hrt_r WHERE hr = ?');
            $rr->execute([$code]);
            if ($x = $rr->fetch(PDO::FETCH_ASSOC)) {
                $hrt = [
                    'total' => $x['total'] !== null ? (float)$x['total'] : null,
                    'top' => $x['top'] !== null ? (float)$x['top'] : null,
                    'bot' => $x['bot'] !== null ? (float)$x['bot'] : null,
                    'pot' => $x['pot'] !== null ? (float)$x['pot'] : null,
                    'verified' => (bool)$x['verified'],
                ];
            }

            // box / dim reconstruction
            $box = null; $dim = null;
            if (!empty($row['box_image'])) {
                $box = ['x_cm' => (float)$row['box_x'], 'y_cm' => (float)$row['box_y'],
                        'z_cm' => (float)$row['box_z'], 'image' => self::assetUrl($row['box_image'])];
            } elseif ($row['box_x'] !== null) {
                $dim = ['x_cm' => (float)$row['box_x'], 'y_cm' => (float)$row['box_y'], 'z_cm' => (float)$row['box_z']];
            }

            $results[] = [
                'code' => $code,
                'row' => [
                    'tester_type' => $row['tester_type'] ?? null,
                    'family' => $row['family'] ?? null,
                    'family_image' => self::assetUrl($row['family_image'] ?? null),
                    'mat_kv' => $row['mat_kv'] !== null ? (float)$row['mat_kv'] : null,
                    'pinB' => $row['pinB'] ?? null,
                    'pinC' => $row['pinC'] ?? null,
                    'pinsD' => json_decode($row['pinsD'] ?? '[]', true) ?: [],
                    'alim_or_deflection' => $row['alim_or_deflection'] ?? null,
                    'weight_g' => $row['weight_g'] !== null ? (float)$row['weight_g'] : null,
                    'box_class' => $row['box_class'] ?? null,
                    'box' => $box,
                    'dim' => $dim,
                    'source' => $row['source'] ?? null,
                    // 1 = HR Diemen published a catalogue page for this code,
                    // even where that page carried no usable data.
                    'listed' => !empty($row['listed']) ? 1 : 0,
                ],
                // src: null = 2003 database, 'xref' = 2011 catalogue. The front-end
                // marks the latter so a repairer knows which claim came from where.
                'equivs' => array_map(fn($e) => [
                    'oem'  => $e['oem'],
                    'src'  => $e['src'],
                    'alts' => $e['alts'] ? json_decode($e['alts'], true) : null,
                ], $equivs[$code] ?? []),
                'matched' => array_values(array_unique($matched)),
                'images' => array_map(fn($i) => ['kind' => $i['kind'], 'path' => self::assetUrl($i['path'])], $images[$code] ?? []),
                'sch' => $sch,
                'hrt_r' => $hrt,
                // Functional pin labels, grouped by the OEM part the pinout was
                // stated for. Kept as separate lists rather than merged: the
                // compilations sometimes disagree, and averaging them away would
                // hide exactly the thing a repairer needs to see.
                'pinfunc' => array_values(array_reduce($pinfn[$code] ?? [], function ($acc, $r) {
                    $k = $r['oem'] . '|' . $r['src'];
                    $acc[$k] ??= ['oem' => $r['oem'], 'src' => $r['src'], 'pins' => []];
                    $acc[$k]['pins'][] = ['pin' => (int)$r['pin'], 'fn' => $r['function'],
                                          'v' => $r['volts'] !== null ? (float)$r['volts'] : null];
                    return $acc;
                }, [])),
                // Service-manual leads. Unverified by design — nobody has read
                // the PDFs — so the confidence level travels with each one.
                'manuals' => array_map(fn($m) => [
                    'chassis' => $m['chassis'], 'url' => $m['url'],
                    'note' => $m['note'], 'confidence' => $m['confidence'],
                ], $manuals[$code] ?? []),
                'obs' => $obs[$code] ?? null,
                'acc' => array_map(fn($a) => $a['accessory'], $accs[$code] ?? []),
                // `hit` marks a set the search term actually named. Without it a
                // search for CM8833 returned three parts with the reason for
                // each buried inside a collapsed list of up to 2,000 models.
                // A shortlist, not a claim: HR never said these interchange, and
                // the figures that would settle it were never published.
                'subs' => [
                    'n' => count($subs[$code] ?? []),
                    'top' => array_map(fn($s) => [
                        'code' => $s['cand'],
                        'd' => (float)$s['shape_d'],
                        'scale' => (float)$s['scale'],
                        'pins' => (int)$s['same_pins'],
                        'mat_ok' => (int)$s['mat_ok'],
                        'mat' => $s['mat_kv'] !== null ? (float)$s['mat_kv'] : null,
                        'extra' => (int)$s['n_extra'],
                        'missing' => (int)$s['n_missing'],
                    ], array_slice($subs[$code] ?? [], 0, 8)),
                ],
                // `hit` marks a set the search term actually named, and long lists
                // are truncated. 82 parts carry more than 250 models between them
                // and account for 42,000 rows, so a search touching a few of them
                // shipped half a megabyte of JSON to render a collapsed box.
                // Matches are never dropped -- they are the reason the part came
                // back -- and uses_total keeps the heading honest.
                'uses' => self::capUses($uses[$code] ?? [], $useTerms),
                'uses_total' => count($uses[$code] ?? []),
            ];
        }
        return $results;
    }
}
