<?php
declare(strict_types=1);

/**
 * "Did you mean" for zero-result searches, over the set-manufacturer vocabulary.
 *
 * Why not phonetic matching. It was measured against the real 1,472 brand names
 * before being rejected:
 *
 *   soundex()   — 891 of 1,472 names land in a collision group. A200 is AAS,
 *                 ACE, AOC, AKAI, ASA, AGA, AIKO...; N200 is NEC, NIKKEI,
 *                 NOKKAI, NAIKO. `make:nec` would drag in AKAI.
 *   metaphone() — better, still 529 names caught up. NEC still collides with
 *                 NIKKEI and NOKKAI; DEC with TEAC and TOKAI.
 *
 * Edit distance behaves the same way at short lengths — among three-letter
 * names there are 1,027 pairs within one edit (GEC/DEC/NEC/GVC/KEC are all
 * mutually adjacent), because a three-letter brand name has nowhere to hide.
 * From five characters up it collapses to 63 pairs across the whole vocabulary,
 * and those are real distinct brands (INTEL/INTER, FALCO/FALCON) rather than
 * noise. So: no fuzzy matching below MIN_LEN, ever.
 *
 * The other rule is that this never widens a result set. Searching is exact;
 * a suggestion is offered only when the search found nothing, and the user has
 * to click it. Silently folding PHILLIPS into PHILIPS would mean every result
 * count became a guess.
 */
final class Suggest
{
    /** Below this many characters, one edit is not a typo — it is a different brand. */
    private const MIN_LEN = 5;
    /** Longer words earn a second edit; shorter ones get one. */
    private const TWO_EDIT_LEN = 8;
    /** Beyond a few corrections in one query it is not a typo, it is a different question. */
    private const MAX_SUGGESTIONS = 3;

    /** @var array<string, array{name:string, n:int}>|null  normalised => brand */
    private static ?array $brands = null;

    /**
     * The vocabulary is built by bin/build-db.php into the `brands` table. It
     * used to be derived here with a GROUP BY over every usage row, on every
     * zero-result search — which made a flood of nonsense queries an unusually
     * cheap way to load the database.
     */
    private static function brands(PDO $db): array
    {
        if (self::$brands !== null) return self::$brands;
        self::$brands = [];
        foreach ($db->query('SELECT name, name_norm, n FROM brands') as $r) {
            self::$brands[$r['name_norm']] = ['name' => $r['name'], 'n' => (int)$r['n']];
        }
        return self::$brands;
    }

    /**
     * One rewrite of $query with every correctable word fixed at once, or null.
     *
     * Fixing them one at a time would be useless: `phillips OR sonny` would
     * offer two suggestions, each still containing the other typo, and both
     * would return nothing.
     *
     * @return array{query:string, changes:list<array{was:string, is:string}>}|null
     */
    public static function forQuery(PDO $db, string $query): ?array
    {
        $changes = [];
        $rewritten = $query;
        foreach (self::candidateWords($query) as $word) {
            $best = self::nearestBrand($db, $word);
            if ($best === null) continue;
            $rewritten = self::replaceWord($rewritten, $word, $best);
            $changes[] = ['was' => $word, 'is' => $best];
            if (count($changes) >= self::MAX_SUGGESTIONS) break;
        }
        return $changes ? ['query' => $rewritten, 'changes' => $changes] : null;
    }

    /**
     * Words in the query that could plausibly be a misspelt brand: long enough
     * to be worth guessing at, not an operator, not a factor filter, and not
     * already a brand we know. `make:phillips` contributes `phillips`.
     */
    private static function candidateWords(string $query): array
    {
        $brands = null;
        $words = [];
        foreach (preg_split('/[\s()"]+/', $query, -1, PREG_SPLIT_NO_EMPTY) as $tok) {
            if (preg_match('/^(AND|OR|NOT)$/i', $tok)) continue;
            // A factor filter contributes only its value, and only for the two
            // fields that hold brand names.
            if (preg_match('/^(make|model):(.+)$/i', $tok, $m)) $tok = $m[2];
            elseif (QueryCompiler::looksLikeFactor($tok)) continue;

            $nrm = hr_norm($tok);
            if (strlen($nrm) < self::MIN_LEN) continue;
            $words[] = $tok;
        }
        return $words;
    }

    /** Closest brand name within the allowed edit budget, or null. */
    private static function nearestBrand(PDO $db, string $word): ?string
    {
        $nrm = hr_norm($word);
        $brands = self::brands($db);
        if (isset($brands[$nrm])) return null;          // spelled fine already

        $budget = strlen($nrm) >= self::TWO_EDIT_LEN ? 2 : 1;
        $bestName = null; $bestDist = PHP_INT_MAX; $bestUse = -1;
        foreach ($brands as $k => $b) {
            if (abs(strlen($k) - strlen($nrm)) > $budget) continue;
            $d = levenshtein($nrm, $k);
            if ($d > $budget) continue;
            // Nearer wins; at equal distance the brand on more parts wins, so a
            // typo for PHILIPS is not answered with an obscure one-part make.
            if ($d < $bestDist || ($d === $bestDist && $b['n'] > $bestUse)) {
                $bestDist = $d; $bestUse = $b['n']; $bestName = $b['name'];
            }
        }
        return $bestName;
    }

    /** Swap one word for another in the raw query, preserving any make:/model: prefix. */
    private static function replaceWord(string $query, string $was, string $is): string
    {
        return preg_replace('/(?<![^\s("])((?:make:|model:)?)' . preg_quote($was, '/') . '(?![^\s)"])/i',
                            '${1}' . str_replace('$', '\$', $is), $query, 1) ?? $query;
    }
}
