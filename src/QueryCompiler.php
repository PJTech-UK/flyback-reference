<?php
declare(strict_types=1);

/**
 * Compiles a QueryParser AST into a parameterised SQL WHERE clause over the
 * base query:
 *
 *     SELECT ... FROM hr LEFT JOIN hrt_r r ON r.hr = hr.code WHERE <expr>
 *
 * All user-supplied values are bound parameters (unique names) — never
 * interpolated — so the search is injection-safe. Each leaf compiles either to
 * a column comparison on `hr`/`r` or to a correlated EXISTS over vpps/roles.
 */
final class QueryCompiler
{
    private int $n = 0;
    private array $params = [];
    /** @var array<string,string>|null normalised HR code => real code, built on demand */
    private ?array $hrCodes = null;
    /** @var string[] normalised terms that can highlight an OEM chip */
    private array $terms = [];
    /** @var string[] normalised terms that can highlight a TV/monitor usage row.
     *  Kept apart from $terms so `model:CM8833` does not light up an unrelated
     *  OEM code that happens to contain the same digits, and vice versa. */
    private array $useTerms = [];
    private ?array $hrtRefs = null;   // cache of all hrt_r rows for ref resolution

    public function __construct(
        private PDO $db,
        private float $eht,
        private bool $includeUses
    ) {}

    /** Does this token look like a factor filter (vs free text)? Used by the
     *  parser to decide whether to merge adjacent words into one phrase. */
    public static function looksLikeFactor(string $tok): bool
    {
        // Every prefix leaf() understands must be listed here, or QueryParser
        // treats the token as free text and merges it with its neighbours into
        // one unmatchable phrase — the filter then silently returns nothing.
        return (bool) preg_match('/^(uf|vpp|wnd|mat|tester_type|type|family|pol|role|img|oem|make|model|network|similaruf|subs|data|listed|src)[:<>=]/i', $tok);
    }

    /** @return array{sql:string, params:array, terms:string[], useTerms:string[]} */
    public function compile(?array $ast): array
    {
        $sql = $ast === null ? '1' : $this->node($ast);
        if ($sql === '') $sql = '1';     // note: '0' is a valid (match-nothing) clause
        return ['sql' => $sql, 'params' => $this->params,
                'terms' => array_values(array_unique($this->terms)),
                'useTerms' => array_values(array_unique($this->useTerms))];
    }

    /** Bind a STRING value as a parameter (injection-safe). */
    private function p(string $v): string
    {
        $k = ':p' . $this->n++;
        $this->params[$k] = $v;
        return $k;
    }

    /**
     * Inline a NUMERIC value as a SQL literal. Safe because every caller has
     * already validated/cast it with (int)/(float). Inlining (rather than
     * binding) avoids SQLite type-affinity surprises: a bound string "7" sorts
     * AFTER any REAL, so `<real expr> >= "7"` would always be false.
     */
    private function lit($v): string
    {
        if (is_int($v)) return (string)$v;
        $s = rtrim(rtrim(sprintf('%.6f', (float)$v), '0'), '.');
        return ($s === '' || $s === '-') ? '0' : $s;
    }

    private function node(array $n): string
    {
        switch ($n[0]) {
            case 'and': return '(' . implode(' AND ', array_map([$this, 'node'], $n[1])) . ')';
            case 'or':  return '(' . implode(' OR ',  array_map([$this, 'node'], $n[1])) . ')';
            case 'not': return '(NOT ' . $this->node($n[1]) . ')';
            case 'leaf': return $this->leaf($n[1]);
        }
        return '1';
    }

    // -- uf SQL expressions (EHT inlined as a numeric literal) ---------------
    private function ufMinSql(): string
    {
        $e = $this->lit($this->eht);
        return "(CASE WHEN r.pot IS NULL OR r.pot<=0 THEN $e*r.top/(r.top+r.bot)"
             . " ELSE $e*r.top/(r.top+r.bot+r.pot) END)";
    }
    private function ufMaxSql(): string
    {
        $e = $this->lit($this->eht);
        return "(CASE WHEN r.pot IS NULL OR r.pot<=0 THEN $e*r.top/(r.top+r.bot)"
             . " ELSE $e*(r.top+r.pot)/(r.top+r.bot+r.pot) END)";
    }
    private const UF_VALID = '(r.top IS NOT NULL AND r.bot IS NOT NULL)';

    /** "We hold something substantive about this part" — used by the `data:` factor. */
    private const HAS_DATA = '(hr.tester_type IS NOT NULL OR hr.mat_kv IS NOT NULL'
                           . ' OR hr.n_wnd IS NOT NULL OR hr.n_uses > 0 OR hr.has_image = 1)';

    /** SQL expression that normalises a text column exactly as hr_norm() does
     *  (strip space/dash/underscore/dot/slash, uppercase), so scoped matches are
     *  separator-insensitive like the general search. */
    private function normCol(string $col): string
    {
        return "UPPER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE($col,' ',''),'-',''),'_',''),'.',''),'/',''))";
    }

    private function leaf(string $tok): string
    {
        $m = [];

        // --- uf (focus voltage), depends on EHT --------------------------------
        if (preg_match('/^uf:(\d+(?:\.\d+)?)-(\d+(?:\.\d+)?)$/i', $tok, $m))
            return self::UF_VALID . ' AND NOT(' . $this->ufMaxSql() . ' < ' . $this->lit((float)$m[1])
                 . ' OR ' . $this->ufMinSql() . ' > ' . $this->lit((float)$m[2]) . ')';
        if (preg_match('/^uf>=(\d+(?:\.\d+)?)$/i', $tok, $m))
            return self::UF_VALID . ' AND ' . $this->ufMaxSql() . ' >= ' . $this->lit((float)$m[1]);
        if (preg_match('/^uf<=(\d+(?:\.\d+)?)$/i', $tok, $m))
            return self::UF_VALID . ' AND ' . $this->ufMinSql() . ' <= ' . $this->lit((float)$m[1]);
        if (preg_match('/^uf[:=](\d+(?:\.\d+)?)$/i', $tok, $m)) {   // single ≈ ±0.3
            $v = (float)$m[1];
            return self::UF_VALID . ' AND NOT(' . $this->ufMaxSql() . ' < ' . $this->lit($v - 0.3)
                 . ' OR ' . $this->ufMinSql() . ' > ' . $this->lit($v + 0.3) . ')';
        }

        // --- vpp ---------------------------------------------------------------
        if (preg_match('/^vpp:(\d+)-(\d+)$/i', $tok, $m))
            return 'EXISTS(SELECT 1 FROM vpps v WHERE v.hr=hr.code AND v.v BETWEEN ' . $this->lit((int)$m[1]) . ' AND ' . $this->lit((int)$m[2]) . ')';
        if (preg_match('/^vpp:(\d+)$/i', $tok, $m))
            return 'EXISTS(SELECT 1 FROM vpps v WHERE v.hr=hr.code AND v.v = ' . $this->lit((int)$m[1]) . ')';
        if (preg_match('/^vpp>=(\d+)$/i', $tok, $m))
            return 'hr.v_max >= ' . $this->lit((int)$m[1]);
        if (preg_match('/^vpp<=(\d+)$/i', $tok, $m))
            return 'hr.v_min <= ' . $this->lit((int)$m[1]);

        // --- wnd ---------------------------------------------------------------
        if (preg_match('/^wnd:(\d+)$/i', $tok, $m))  return 'hr.n_wnd = '  . $this->lit((int)$m[1]);
        if (preg_match('/^wnd>=(\d+)$/i', $tok, $m)) return 'hr.n_wnd >= ' . $this->lit((int)$m[1]);
        if (preg_match('/^wnd<=(\d+)$/i', $tok, $m)) return 'hr.n_wnd <= ' . $this->lit((int)$m[1]);

        // --- mat (rated EHT) ---------------------------------------------------
        if (preg_match('/^mat:(\d+(?:\.\d+)?)-(\d+(?:\.\d+)?)$/i', $tok, $m))
            return 'hr.mat_kv BETWEEN ' . $this->lit((float)$m[1]) . ' AND ' . $this->lit((float)$m[2]);
        if (preg_match('/^mat>=(\d+(?:\.\d+)?)$/i', $tok, $m)) return 'hr.mat_kv >= ' . $this->lit((float)$m[1]);
        if (preg_match('/^mat<=(\d+(?:\.\d+)?)$/i', $tok, $m)) return 'hr.mat_kv <= ' . $this->lit((float)$m[1]);

        // --- categorical -------------------------------------------------------
        if (preg_match('/^(?:tester_type|type):(\w+)$/i', $tok, $m))   // `type:` is the English alias for `tester_type:`
            return "UPPER(IFNULL(hr.tester_type,'')) = " . $this->p(strtoupper($m[1]));
        if (preg_match('/^family:(.+)$/i', $tok, $m))
            return "UPPER(IFNULL(hr.family,'')) = " . $this->p(strtoupper(trim($m[1])));
        if (preg_match('/^pol:([+\-])$/i', $tok, $m))
            return $m[1] === '+' ? 'hr.pol_pos > 0' : 'hr.pol_neg > 0';
        if (preg_match('/^role:(.+)$/i', $tok, $m))
            return 'EXISTS(SELECT 1 FROM roles r2 WHERE r2.hr=hr.code AND LOWER(r2.role) LIKE ' . $this->p('%' . strtolower($m[1]) . '%') . ')';
        if (preg_match('/^img:(yes|no|y|n|1|0|true|false)$/i', $tok, $m)) {
            $yes = in_array(strtolower($m[1]), ['yes', 'y', '1', 'true'], true);
            return 'hr.has_image = ' . ($yes ? '1' : '0');
        }

        // --- data completeness -------------------------------------------------
        // `data:none` lists the parts we can name but know nothing about: no
        // tester reading, no schematic, no usage list. That set is worth being
        // able to browse — it is the shape of the gap in the archive, and every
        // entry is a part someone may still be trying to identify.
        if (preg_match('/^data:(none|full|any)$/i', $tok, $m)) {
            $has = self::HAS_DATA;
            return match (strtolower($m[1])) {
                'none'  => "NOT ($has)",
                'full'  => $has,
                default => '1',
            };
        }
        // `listed:yes` — HR Diemen published a catalogue page for the code, so
        // it is a confirmed product rather than only a cross-reference entry.
        if (preg_match('/^listed:(yes|no|y|n|1|0|true|false)$/i', $tok, $m)) {
            $yes = in_array(strtolower($m[1]), ['yes', 'y', '1', 'true'], true);
            return 'hr.listed = ' . ($yes ? '1' : '0');
        }
        // `src:` — where the record came from.
        if (preg_match('/^src:(book|site|xref)$/i', $tok, $m)) {
            return match (strtolower($m[1])) {
                'book' => 'hr.source IS NULL',
                'site' => "hr.source = 'site_2012'",
                default => "hr.source = 'xref_2011'",
            };
        }

        // --- field-scoped text (OEM code / set make / set model) ---------------
        if (preg_match('/^oem:(.+)$/i', $tok, $m)) {
            $nrm = hr_norm($m[1]);
            if ($nrm === '') return '1';
            $this->terms[] = $nrm;     // also highlight the matched OEM chip
            return 'EXISTS(SELECT 1 FROM equivalents e2 WHERE e2.hr=hr.code AND e2.oem_norm LIKE ' . $this->p('%' . $nrm . '%') . ')';
        }
        if (preg_match('/^make:(.+)$/i', $tok, $m)) {
            $nrm = hr_norm($m[1]);
            if ($nrm === '') return '1';
            $this->useTerms[] = $nrm;   // also highlight the matching usage rows
            return 'EXISTS(SELECT 1 FROM uses u2 WHERE u2.hr=hr.code AND ' . $this->normCol('u2.fabname') . ' LIKE ' . $this->p('%' . $nrm . '%') . ')';
        }
        if (preg_match('/^model:(.+)$/i', $tok, $m)) {
            $nrm = hr_norm($m[1]);
            if ($nrm === '') return '1';
            $this->useTerms[] = $nrm;   // also highlight the matching usage rows
            return 'EXISTS(SELECT 1 FROM uses u2 WHERE u2.hr=hr.code AND ' . $this->normCol('u2.model') . ' LIKE ' . $this->p('%' . $nrm . '%') . ')';
        }

        // --- candidate substitutes for a given part ----------------------------
        // Not equivalents. These are parts whose extracted tap profile is the
        // same shape at a comparable scale, in the same line-rate class -- a
        // shortlist to check on the bench, never a claim of interchangeability.
        if (preg_match('/^subs:(.+)$/i', $tok, $m)) {
            $code = $this->resolveHrCode($m[1]);
            if ($code === null) return '0';
            return 'EXISTS(SELECT 1 FROM substitutes s2 WHERE s2.hr = ' . $this->p($code)
                 . ' AND s2.cand = hr.code)';
        }

        // --- structural (resolve reference HR, depends on EHT) -----------------
        if (preg_match('/^network:(.+)$/i', $tok, $m)) {
            $ref = $this->resolveRef($m[1]);
            if (!$ref || $ref['top'] === null || $ref['bot'] === null) return '0';
            return '(r.top = ' . $this->lit((float)$ref['top']) . ' AND r.bot = ' . $this->lit((float)$ref['bot']) . ')';
        }
        if (preg_match('/^similaruf:(.+)$/i', $tok, $m)) {
            $ref = $this->resolveRef($m[1]);
            $u = $ref ? hr_uf_range(
                $ref['top'] !== null ? (float)$ref['top'] : null,
                $ref['bot'] !== null ? (float)$ref['bot'] : null,
                $ref['pot'] !== null ? (float)$ref['pot'] : null, $this->eht) : null;
            if (!$u) return '0';
            return self::UF_VALID . ' AND NOT(' . $this->ufMaxSql() . ' < ' . $this->lit($u['min'])
                 . ' OR ' . $this->ufMinSql() . ' > ' . $this->lit($u['max']) . ')';
        }

        // --- free text (contains-match over code/model blobs) ------------------
        $nrm = hr_norm($tok);
        if ($nrm === '') return '1';
        $this->terms[] = $nrm;
        // Only when the toggle actually put usage rows in scope; otherwise a
        // card would claim a model "matches your search" when the match was on
        // an OEM code and the model list was never consulted.
        if ($this->includeUses) $this->useTerms[] = $nrm;
        $like = '%' . $nrm . '%';
        if ($this->includeUses)
            return '(hr.code_blob LIKE ' . $this->p($like) . ' OR hr.use_blob LIKE ' . $this->p($like) . ')';
        return 'hr.code_blob LIKE ' . $this->p($like);
    }

    /** Resolve a user-typed HR reference to a real hr.code — exactly, else by
     *  normalised form, so subs:hr80016 and subs:"HR 80016" both land. */
    private function resolveHrCode(string $ref): ?string
    {
        $st = $this->db->prepare('SELECT code FROM hr WHERE code = ?');
        $st->execute([trim($ref)]);
        if ($c = $st->fetchColumn()) return (string)$c;
        $nrm = hr_norm($ref);
        if ($nrm === '') return null;
        if ($this->hrCodes === null) {
            $this->hrCodes = [];
            foreach ($this->db->query('SELECT code FROM hr') as $r) {
                $this->hrCodes[hr_norm($r['code'])] ??= $r['code'];
            }
        }
        return $this->hrCodes[$nrm] ?? null;
    }

    /** Resolve a network:/similaruf: reference to an hrt_r row, by exact or normalised code. */
    private function resolveRef(string $ref): ?array
    {
        if ($this->hrtRefs === null) {
            $this->hrtRefs = [];
            foreach ($this->db->query('SELECT hr, top, bot, pot FROM hrt_r') as $row) {
                $this->hrtRefs[hr_norm($row['hr'])] = $row;
            }
        }
        return $this->hrtRefs[hr_norm($ref)] ?? null;
    }
}
