<?php
declare(strict_types=1);

/**
 * The search-factor catalogue: the single source of truth for the guided query
 * builder and the help modal. Each factor describes how the user picks a value
 * and how it maps to the advanced query syntax (the `token` template), so the
 * builder and the raw text box always agree.
 *
 * The grammar these tokens slot into (parsed by QueryParser / compiled by
 * QueryCompiler) supports AND, OR, NOT and parentheses; adjacent terms are
 * implicitly ANDed.
 */
final class Catalog
{
    /** Static factor definitions. `enum` factors get their options filled in by dynamic(). */
    public static function factors(): array
    {
        return [
            [
                'key' => 'text', 'label' => 'Anywhere (codes and set models)', 'type' => 'text',
                'help' => 'The broadest option: matches anywhere in a part’s HR code, in any of its manufacturer equivalent codes, and in the make and model of the sets it was fitted to. Set makes and models are only included while the “include TV/monitor models” box is ticked. It does NOT search notes, output roles, family codes or accessory lists — use the specific fields for those. Punctuation and case are ignored, so AT2075/30102, AT-2075-30102 and at 2075 30102 are the same search.',
                'ops' => [['op' => 'contains', 'token' => '{v}', 'label' => 'contains']],
                'examples' => ['BG1897', 'BSC25', 'sony kv 2752'],
            ],
            [
                'key' => 'oem', 'label' => 'Manufacturer part code only', 'type' => 'text',
                'help' => 'Search ONLY the manufacturer / OEM equivalent part codes (not HR codes or models). Punctuation and case are ignored; spell the code however your source spells it.',
                'ops' => [['op' => 'contains', 'token' => 'oem:{v}', 'label' => 'contains']],
                'examples' => ['oem:8-598-925-00', 'oem:BG1897'],
            ],
            [
                'key' => 'make', 'label' => 'Set make only (TV / monitor brand)', 'type' => 'text',
                'help' => 'Search ONLY the make (manufacturer) of the set the part is fitted in. Quote multi-word makes, e.g. make:"general electric".',
                'ops' => [['op' => 'contains', 'token' => 'make:{v}', 'label' => 'contains']],
                'examples' => ['make:sony', 'make:philips'],
            ],
            [
                'key' => 'model', 'label' => 'Set model only (TV / monitor)', 'type' => 'text',
                'help' => 'Search ONLY the model of the set the part is fitted in. Punctuation and case are ignored.',
                'ops' => [['op' => 'contains', 'token' => 'model:{v}', 'label' => 'contains']],
                'examples' => ['model:CTV 2592', 'model:kv 2752'],
            ],
            [
                'key' => 'tester_type', 'label' => 'Type', 'type' => 'enum',
                'help' => 'Which tester the manufacturer characterised the part on. ST = TV flyback (about 15.6 kHz line), SM = multiscan-monitor flyback (32 kHz and up), CH = chopper PSU, TR = tripler, MC/BN = niche. IMPORTANT: only about a quarter of parts have a tester type recorded — the rest reached this archive through cross-reference catalogues that carried no descriptors. Filtering by type excludes every one of them, so a small result count means thin coverage of the field, not a small population.',
                'ops' => [['op' => 'is', 'token' => 'type:{v}', 'label' => 'is']],
                'options' => [], // filled by dynamic()
                'examples' => ['type:SM', 'type:ST'],
            ],
            [
                'key' => 'vpp', 'label' => 'Vpp output (V)', 'type' => 'number',
                'help' => 'Peak-to-peak output voltage extracted from the schematic.',
                'ops' => [
                    ['op' => '=',  'token' => 'vpp:{v}',      'label' => 'equals'],
                    ['op' => 'range', 'token' => 'vpp:{a}-{b}', 'label' => 'between'],
                    ['op' => '>=', 'token' => 'vpp>={v}',     'label' => 'max ≥'],
                    ['op' => '<=', 'token' => 'vpp<={v}',     'label' => 'min ≤'],
                ],
                'examples' => ['vpp:200', 'vpp:200-300', 'vpp>=200'],
            ],
            [
                'key' => 'wnd', 'label' => 'Winding count', 'type' => 'number',
                'help' => 'Number of windings detected on the schematic.',
                'ops' => [
                    ['op' => '=',  'token' => 'wnd:{v}',  'label' => 'equals'],
                    ['op' => '>=', 'token' => 'wnd>={v}', 'label' => '≥'],
                    ['op' => '<=', 'token' => 'wnd<={v}', 'label' => '≤'],
                ],
                'examples' => ['wnd:5', 'wnd>=4'],
            ],
            [
                'key' => 'role', 'label' => 'Output role', 'type' => 'enum',
                'help' => 'A labelled wire-out present on the schematic (focus, screen, H.V., etc.).',
                'ops' => [['op' => 'has', 'token' => 'role:{v}', 'label' => 'present']],
                'options' => [],
                'examples' => ['role:focus', 'role:H.V.'],
            ],
            [
                'key' => 'uf', 'label' => 'Focus voltage Uf (kV)', 'type' => 'number',
                'help' => 'Estimated focus voltage from the bleeder network, assuming a 24 kV CRT anode (EHT) supply.',
                'ops' => [
                    ['op' => '=',  'token' => 'uf:{v}',     'label' => '≈ (±0.3)'],
                    ['op' => 'range', 'token' => 'uf:{a}-{b}', 'label' => 'between'],
                    ['op' => '>=', 'token' => 'uf>={v}',    'label' => 'max ≥'],
                    ['op' => '<=', 'token' => 'uf<={v}',    'label' => 'min ≤'],
                ],
                'examples' => ['uf:6-8', 'uf>=7'],
            ],
            [
                'key' => 'mat', 'label' => 'Max EHT rating (kV)', 'type' => 'number',
                'help' => 'The flyback’s rated peak anode (EHT) voltage from the tester data. HR Diemen label this “MAT” (Spanish Màxima Alta Tensió). Distinct from the 24 kV anode supply assumed for the Uf estimate.',
                'ops' => [
                    ['op' => '>=', 'token' => 'mat>={v}', 'label' => '≥'],
                    ['op' => '<=', 'token' => 'mat<={v}', 'label' => '≤'],
                    ['op' => 'range', 'token' => 'mat:{a}-{b}', 'label' => 'between'],
                ],
                'examples' => ['mat>=24', 'mat:24-30'],
            ],
            [
                'key' => 'family', 'label' => 'Family', 'type' => 'enum',
                'help' => 'HR Diemen family code.',
                'ops' => [['op' => 'is', 'token' => 'family:{v}', 'label' => 'is']],
                'options' => [],
                'examples' => ['family:1NP1'],
            ],
            [
                'key' => 'img', 'label' => 'Has schematic image', 'type' => 'bool',
                'help' => 'Only parts that have a schematic image on file.',
                'ops' => [['op' => 'is', 'token' => 'img:{v}', 'label' => 'is']],
                'options' => [['value' => 'yes', 'label' => 'yes'], ['value' => 'no', 'label' => 'no']],
                'examples' => ['img:yes'],
            ],
            [
                'key' => 'data', 'label' => 'How much we know', 'type' => 'enum',
                'help' => 'Filter by how complete our record is. “nothing known” lists parts we can name but hold no tester reading, schematic or usage list for — mostly codes that reached us only through the 2011 equivalence catalogue. They are still searchable because their equivalents may be obtainable even when the part itself is not.',
                'ops' => [['op' => 'is', 'token' => 'data:{v}', 'label' => 'is']],
                'options' => [
                    ['value' => 'full', 'label' => 'have technical data'],
                    ['value' => 'none', 'label' => 'nothing known'],
                ],
                'examples' => ['data:none', 'data:none listed:yes'],
            ],
            [
                'key' => 'listed', 'label' => 'Listed by HR Diemen', 'type' => 'bool',
                'help' => 'Whether HR Diemen published a catalogue page for the code on their own site (captured before it went offline). A “yes” means the part is a confirmed product even where the page carried no data — as opposed to a code known only from a cross-reference table.',
                'ops' => [['op' => 'is', 'token' => 'listed:{v}', 'label' => 'is']],
                'options' => [['value' => 'yes', 'label' => 'yes'], ['value' => 'no', 'label' => 'no']],
                'examples' => ['listed:yes data:none'],
            ],
            [
                'key' => 'src', 'label' => 'Record source', 'type' => 'enum',
                'help' => 'Which source the record came from: the manufacturer’s 2003 reference database, their website before it went offline, or their 2011 equivalence catalogue (cross-reference only).',
                'ops' => [['op' => 'is', 'token' => 'src:{v}', 'label' => 'is']],
                'options' => [
                    ['value' => 'book', 'label' => '2003 database'],
                    ['value' => 'site', 'label' => 'hrdiemen.com 2012'],
                    ['value' => 'xref', 'label' => '2011 catalogue only'],
                ],
                'examples' => ['src:xref'],
            ],
            [
                'key' => 'network', 'label' => 'Same bleeder network as', 'type' => 'hr',
                'help' => 'Parts whose internal bleeder network (R_lower / R_upper) matches the given HR.',
                'ops' => [['op' => 'as', 'token' => 'network:{v}', 'label' => 'as']],
                'examples' => ['network:"HRT 226 BS"'],
            ],
            [
                'key' => 'subs', 'label' => 'Possible substitutes for', 'type' => 'hr',
                'help' => 'Parts whose extracted tap profile is the same SHAPE as the given HR at a comparable scale, in the same line-rate class and deflection angle. A shortlist to check on the bench — NOT an equivalence claim, and nothing here accounts for inductance, DCR or stray capacitance.',
                'ops' => [['op' => 'as', 'token' => 'subs:{v}', 'label' => 'for']],
                'examples' => ['subs:"HR 80016"'],
            ],
            [
                'key' => 'similaruf', 'label' => 'Similar Uf range to', 'type' => 'hr',
                'help' => 'Parts whose estimated focus-voltage range overlaps the given HR (at the chosen EHT).',
                'ops' => [['op' => 'as', 'token' => 'similaruf:{v}', 'label' => 'as']],
                'examples' => ['similaruf:"HRT 226 BS"'],
            ],
        ];
    }

    /** Fill enum options (tester_type, role, family) from the live database. */
    public static function dynamic(PDO $db): array
    {
        $factors = self::factors();

        $tipos = $db->query("SELECT DISTINCT tester_type FROM hr WHERE tester_type IS NOT NULL AND tester_type<>'' ORDER BY tester_type")
                    ->fetchAll(PDO::FETCH_COLUMN);
        $roles = $db->query("SELECT DISTINCT role FROM roles WHERE role IS NOT NULL AND role<>'' ORDER BY role")
                    ->fetchAll(PDO::FETCH_COLUMN);
        $fams  = $db->query("SELECT DISTINCT family FROM hr WHERE family IS NOT NULL AND family<>'' ORDER BY family")
                    ->fetchAll(PDO::FETCH_COLUMN);

        foreach ($factors as &$f) {
            if ($f['key'] === 'tester_type')   $f['options'] = array_map(fn($t) => ['value' => $t, 'label' => $t], $tipos);
            if ($f['key'] === 'role')   $f['options'] = array_map(fn($r) => ['value' => $r, 'label' => $r], $roles);
            if ($f['key'] === 'family') $f['options'] = array_map(fn($x) => ['value' => $x, 'label' => $x], $fams);
        }
        unset($f);

        return [
            'factors'   => $factors,
            // Coarse part-type buckets for the selector beside the search box.
            // Backed by the tester `tester_type` (and the HRT code prefix for triplers),
            // since the dataset has no real scan-frequency field — see Search::categorySql().
            'categories' => [
                ['value' => '',        'label' => 'Any type'],
                ['value' => 'tripler', 'label' => 'Triplers (HRT-…)'],
                ['value' => 'lopt15',  'label' => '15.6 kHz flyback / LOPT (TV)'],
                ['value' => 'lopt32',  'label' => '32 kHz flyback / LOPT (monitor)'],
                ['value' => 'misc',    'label' => 'Misc / other'],
            ],
            'operators' => ['AND', 'OR', 'NOT'],
            'sorts'     => [
                ['value' => 'code',  'label' => 'code'],
                ['value' => 'uf',    'label' => 'Uf nominal'],
                ['value' => 'ufmin', 'label' => 'Uf min'],
                ['value' => 'ufmax', 'label' => 'Uf max'],
                ['value' => 'total', 'label' => 'R total'],
            ],
            'generated' => Db::meta('generated'),
            'version'   => Db::meta('version'),
            // Counted once when the database is built, not per request: the model
            // count is a DISTINCT over ~400,000 rows and this endpoint is hit on
            // every page load. They still follow the data, because a rebuild is
            // what changes them. (Distinct codes, not spellings — docs/OEM_CODES.md.)
            'stats'     => [
                'parts'  => (int) Db::meta('stat_parts'),
                // How many parts have no tester type at all. A type filter
                // silently excludes them, and that is most of the archive.
                'untyped' => (int) Db::meta('stat_untyped'),
                'codes'  => (int) Db::meta('stat_codes'),
                'models' => (int) Db::meta('stat_models'),
            ],
        ];
    }
}
