<?php
/**
 * build-db.php — assemble the published dataset into a relational SQLite
 * database for the PHP web app.
 *
 * This is the PHP equivalent of extract/build_web_data.py: it reads the same
 * already-produced JSON / CSV files in extract/ (plus analysis/bulk_extract/)
 * and applies the same merge rules, but writes normalised tables + an FTS5
 * trigram index instead of one big data.js blob.
 *
 * Run:  php bin/build-db.php
 * Output: database/database.sqlite  (overwritten each run)
 */

declare(strict_types=1);

$ROOT = dirname(__DIR__);               // project root
$EX   = "$ROOT/dataset";     // published data files; the extractors that write them live in extract/
$DBDIR = "$ROOT/database";              // where the built SQLite file goes
$DB_PATH = "$DBDIR/database.sqlite";

@mkdir(dirname($DB_PATH), 0775, true);

/** Identical to hr_norm() in src/util.php -- keep the two in step.
 *  Drops every character that is not a letter or a digit and uppercases what is
 *  left, so punctuation is irrelevant to matching: AT2075/30102, AT-2075-30102
 *  and AT 2075 30102 are one key. Unicode-aware, so accented letters in TV model
 *  names survive; brackets, *, +, comma, colon and OCR debris do not. */
function norm(?string $s): string {
    return strtoupper(preg_replace('/[^\p{L}\p{N}]+/u', '', $s ?? ''));
}

/** How many separator runs a literal spelling carries. Punctuation encodes the
 *  OEM's own field boundaries (Sony's 1-453-543-11), so the richest spelling is
 *  the one worth keeping; the flat one is derivable from it, never the reverse. */
function sepCount(string $s): int {
    return preg_match_all('/[\s\-_.\/]+/', $s);
}

/** Provenance strength, low = most authoritative. Where the same pair is claimed
 *  by more than one source the strongest one is what the chip should say. */
function srcRank(?string $src): int {
    return ['' => 0, 'xref' => 1, 'datapin' => 2, 'classic' => 3][$src ?? ''] ?? 4;
}

function loadJson(string $path): array {
    if (!is_file($path)) return [];
    $d = json_decode(file_get_contents($path), true);
    return is_array($d) ? $d : [];
}

echo "Building $DB_PATH\n";
if (is_file($DB_PATH)) unlink($DB_PATH);

$db = new PDO('sqlite:' . $DB_PATH);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA journal_mode = OFF');
$db->exec('PRAGMA synchronous = OFF');

// ---------------------------------------------------------------------------
// Schema
// ---------------------------------------------------------------------------
$db->exec(<<<'SQL'
CREATE TABLE hr (
  code        TEXT PRIMARY KEY,
  tester_type        TEXT,
  family     TEXT,
  family_image TEXT,
  mat_kv      REAL,
  pinB        TEXT,
  pinC        TEXT,
  pinsD       TEXT,            -- json array
  alim_or_deflection TEXT,
  weight_g    REAL,
  box_class    TEXT,
  box_x       REAL, box_y REAL, box_z REAL, box_image TEXT,
  source      TEXT,            -- NULL = 2003 database, 'site_2012' = catalogue site,
                               -- 'xref_2011' = catalogue cross-ref only, no data
  -- schematic-extract aggregates (denormalised for fast filter/sort)
  n_wnd       INTEGER,
  n_seg       INTEGER,
  v_min       INTEGER,
  v_max       INTEGER,
  pol_pos     INTEGER DEFAULT 0,
  pol_neg     INTEGER DEFAULT 0,
  n_uses      INTEGER DEFAULT 0,
  n_equiv     INTEGER DEFAULT 0,
  has_image   INTEGER DEFAULT 0,
  listed      INTEGER DEFAULT 0,  -- HR Diemen published a catalogue page for it,
                                  -- even if that page carried no actual data
  code_blob   TEXT,            -- normalised HR code + all OEM codes (substring search)
  use_blob    TEXT             -- normalised usage "fabname model" strings (toggled search)
);
CREATE TABLE equivalents (hr TEXT, oem TEXT, oem_norm TEXT, src TEXT,
                       canon INTEGER DEFAULT 0, alts TEXT);
                               -- src: NULL = 2003 database, 'xref' = 2011 catalogue,
                               -- 'classic' = inferred via a Classic FBT correspondence
                               -- canon: 1 on the one row per (hr, oem_norm) chosen to
                               --   represent the code on screen; every literal spelling
                               --   stays in the table, canon just picks the tag to show.
                               -- alts: on a canon row, JSON list of the other spellings
CREATE TABLE uses     (hr TEXT, fab TEXT, fabname TEXT, model TEXT, model_norm TEXT);
CREATE TABLE obs      (hr TEXT, note TEXT);
CREATE TABLE acc      (hr TEXT, accessory TEXT);
CREATE TABLE schematics (hr TEXT, kind TEXT, path TEXT);
CREATE TABLE hrt_r    (hr TEXT PRIMARY KEY, total REAL, top REAL, bot REAL, pot REAL, verified INTEGER, file TEXT);

-- Candidate substitutes, computed from the extracted tap profiles. Not
-- equivalents: HR never claimed these parts interchange, and inductance, DCR
-- and stray capacitance -- the things that actually decide it -- were never
-- published. See docs/SUBSTITUTES.md.
CREATE TABLE substitutes (
  hr        TEXT,      -- target part
  cand      TEXT,      -- candidate
  shape_d   REAL,      -- mean fractional difference between normalised profiles
  scale     REAL,      -- B+ rescale the candidate needs (EHT moves with it)
  same_pins INTEGER,   -- 1 = same B+ and COL pins, so no rewiring
  mat_ok    INTEGER,   -- 1 = candidate anode rating is not below the target's
  n_extra   INTEGER,   -- candidate taps the target does not have
  n_missing INTEGER    -- target taps the candidate lacks
);
CREATE TABLE vpps     (hr TEXT, v INTEGER, pin TEXT, pol TEXT, side TEXT, wnd INTEGER);
CREATE TABLE roles    (hr TEXT, role TEXT);
-- Functional pin labels (ABL / HTR / AFC / B+ / boost-up) and rectified rail
-- voltages, from the community "Data Pin Flyback" compilations. HR Diemen
-- never published these: their drawings give pulse amplitudes and the HV-stack
-- roles, but no functional label for the aux pins. `oem` records which OEM part
-- the pinout was actually stated for, since it reaches us via the cross-ref.
CREATE TABLE pinfunc  (hr TEXT, oem TEXT, pin INTEGER, function TEXT,
                       volts REAL, src TEXT);
-- Service-manual leads for parts we hold no data on. A set's manual carries the
-- LOPT pin-out, so the sets a part was fitted to are a way back in. `confidence`
-- is explicit — 'hit' means the chassis matches something in our own usage list
-- and the manual is reachable; 'lead' is plausible but unverified; 'weak' is a
-- sibling model or partial match. None of it has been read by a human yet.
CREATE TABLE manuals  (hr TEXT, chassis TEXT, url TEXT, note TEXT, confidence TEXT);
CREATE TABLE meta     (key TEXT PRIMARY KEY, value TEXT);
SQL);

// ---------------------------------------------------------------------------
// Load sources
// ---------------------------------------------------------------------------
$hr   = loadJson("$EX/hr.json");
$uses = loadJson("$EX/hr_to_uses.json");
$obs  = loadJson("$EX/notes_en.json");
$acc  = loadJson("$EX/accessories.json");
$fab  = loadJson("$EX/manufacturers.json");
$schem= loadJson("$EX/hr_to_schematic.json");

// Box-letter -> dimensions, from data/packaging/modHR-family.txt
$boxes = [];
$famTxt = @file_get_contents("$ROOT/data/packaging/modHR-family.txt");
if ($famTxt !== false) {
    foreach (preg_split('/\r?\n/', $famTxt) as $line) {
        if (preg_match('/^\s*([A-Z])\s*-\s*([\d,.]+)cm\s*\/\s*([\d,.]+)cm\s*\/\s*([\d,.]+)cm/', $line, $m)) {
            $f = fn($x) => (float) str_replace(',', '.', $x);
            $boxes[$m[1]] = ['x' => $f($m[2]), 'y' => $f($m[3]), 'z' => $f($m[4]),
                             'image' => "../data/packaging/{$m[1]}.jpg"];
        }
    }
}

// site_2012 hr-table records fill gaps (local wins on overlap)
$added_hr = 0;
foreach (loadJson("$EX/hr_from_site.json") as $code => $rec) {
    if (isset($hr[$code])) continue;
    $hr[$code] = [
        'hr' => $code, 'tester_type' => null, 'family' => null,
        'mat_kv' => $rec['mat_kv'] ?? null,
        'pinB' => $rec['pinB'] ?? null, 'pinC' => $rec['pinC'] ?? null,
        'pinsD' => $rec['pinsD'] ?? [],
        'alim_or_deflection' => $rec['alim_or_deflection'] ?? null,
        'weight_g' => $rec['weight_g'] ?? null,
        'box_class' => null, '_source' => 'site_2012',
        'dim' => $rec['dim'] ?? null,
    ];
    $added_hr++;
}

// site_2012 usage lists (reshaped to {fab, fabname, model})
$added_uses = 0;
foreach (loadJson("$EX/uses_from_site.json") as $code => $ul) {
    if (isset($uses[$code])) continue;
    $uses[$code] = [];
    foreach ($ul as $u) {
        $f = $u['fab'] ?? '';
        $uses[$code][] = ['fab' => $f, 'fabname' => $fab[$f] ?? $f, 'model' => $u['model'] ?? ''];
    }
    $added_uses++;
}

// Catalogue presence (extract/extract_catalogue_presence.py): which codes HR
// Diemen actually listed on their own site, including the 1,197 pages that
// rendered but carried no data. Knowing a code was a real catalogue product —
// rather than only a line in a cross-reference table — is worth recording even
// when we hold nothing else about it.
$presence = loadJson("$EX/catalogue_presence.json");

// Resistor values + curated overrides
$hrt_r = loadJson("$EX/hrt_resistors.json");
foreach (loadJson("$EX/hrt_resistors_overrides.json") as $ov => $fields) {
    $hrt_r[$ov] = array_merge($hrt_r[$ov] ?? ['hr' => $ov], $fields, ['verified' => true]);
}
foreach ($hrt_r as &$r) { unset($r['raw'], $r['survivors']); } unset($r);

// Family image map (case-insensitive)
$fam_map = [];
foreach (glob("$ROOT/data/families/*.gif") as $p) {
    $fam_map[strtoupper(pathinfo($p, PATHINFO_FILENAME))] = '../data/families/' . basename($p);
}

// Schematic image paths: prefix ../ and record per-HR
foreach ($schem as $h => &$imgs) {
    foreach ($imgs as &$img) {
        if (strncmp($img['path'], '../', 3) !== 0) $img['path'] = '../' . $img['path'];
    }
} unset($imgs, $img);

// equivalents pairs — [oem, hr, src] with src NULL for the 2003 database
$pairs = [];
$fh = fopen("$EX/equivalents.csv", 'r');
$header = fgetcsv($fh);
$ci = array_flip($header);
while (($row = fgetcsv($fh)) !== false) {
    $pairs[] = [$row[$ci['oem']], $row[$ci['hr']], null];
}
fclose($fh);

// ---------------------------------------------------------------------------
// 2011 catalogue cross-reference (dataset/xref_pdf.csv, from parse_xref_pdf.py)
//
// ~44k OEM->HR pairs, ~26k of which the 2003 database does not have — including
// essentially the whole post-2003 Chinese code space (BSC24-*, BSC25-*,
// CF0801-*, FTK*), plus ~4k HR codes we hold no technical data for whatsoever.
// Those data-less "ghost" codes still earn their place: identifying a dead
// part as HR48663 and learning it is also BSC25N3604GA turns a dead end into
// a shopping list, which is the whole point of a cross-reference.
//
// Where the 2003 database and the catalogue disagree about an OEM code (~0.9% of the
// 18.5k they share) BOTH mappings are kept and tagged — one source is the 2003
// edition and the catalogue is 2011, so the disagreement is usually a genuine
// revision rather than an error, and a repairer wants to see both leads.
// ---------------------------------------------------------------------------
$xref_pairs = 0;
$ghost_codes = [];
$seenPair = [];
foreach ($pairs as [$oem, $h, $_]) $seenPair[norm($oem) . "\t" . $h] = true;

$xrefPath = "$EX/xref_pdf.csv";
if (is_file($xrefPath)) {
    $fh = fopen($xrefPath, 'r');
    $xh = fgetcsv($fh);
    $xi = array_flip($xh);
    while (($row = fgetcsv($fh)) !== false) {
        $oem = $row[$xi['oem']];
        $h   = $row[$xi['hr_code']];
        $key = norm($oem) . "\t" . $h;
        if (isset($seenPair[$key])) continue;      // book already has this pair
        $seenPair[$key] = true;
        $pairs[] = [$oem, $h, 'xref'];
        $xref_pairs++;
        if (empty($row[$xi['matched']])) $ghost_codes[$h] = true;
    }
    fclose($fh);
}

// ---------------------------------------------------------------------------
// Schematic-extract aggregates per HR
// ---------------------------------------------------------------------------
$sch = [];   // code => ['n_wnd','n_seg','vpps'=>[...],'roles'=>[...]]

// Prefer the consolidated dataset file; fall back to the reader's own per-HR
// output when it is present, so a fresh extraction run is picked up without
// having to consolidate first. Regenerate with
// `php tools/consolidate-schematic-extracts.php`.
$bulkDir  = "$ROOT/analysis/bulk_extract";
$bulkRecs = [];
if (is_dir($bulkDir) && glob("$bulkDir/HR*.json")) {
    foreach (glob("$bulkDir/HR*.json") as $fp) {
        $d = json_decode((string)@file_get_contents($fp), true);
        if (is_array($d) && isset($d['hr'])) $bulkRecs[] = $d;
    }
} else {
    foreach (loadJson("$EX/schematic_extracts.json") as $code => $rec) {
        $rec['hr'] = $code;
        $bulkRecs[] = $rec;
    }
}
{
    foreach ($bulkRecs as $d) {
        $vpps = []; $rolesList = []; $nSeg = 0;
        foreach ($d['windings'] ?? [] as $w) {
            $nSeg += $w['segments'] ?? 1;
            foreach ($w['taps'] ?? [] as $t) {
                if (($t['kind'] ?? '') === 'vpp' && ($t['vpp'] ?? null) !== null) {
                    $vpps[] = ['v' => $t['vpp'], 'pin' => $t['pin'] ?? null,
                               'pol' => $t['polarity'] ?? null, 'side' => $w['side'] ?? null,
                               'wnd' => $w['winding_num'] ?? null];
                } elseif (($t['kind'] ?? '') === 'role' && !empty($t['role'])) {
                    $rolesList[] = $t['role'];
                }
            }
        }
        // Match build_web_data.py: skip records with no vpps/roles and a falsy
        // n_windings (None or 0) — they carry no useful schematic data.
        if (!$vpps && !$rolesList && empty($d['n_windings'])) continue;
        $vs = array_values(array_filter(array_map(fn($v) => $v['v'], $vpps), 'is_int'));
        $rolesU = array_values(array_unique($rolesList)); sort($rolesU);
        $sch[$d['hr']] = [
            'n_wnd' => $d['n_windings'] ?? null,
            'n_seg' => $nSeg,
            'vpps'  => $vpps,
            'roles' => $rolesU,
            'v_min' => $vs ? min($vs) : null,
            'v_max' => $vs ? max($vs) : null,
            'pol_pos' => count(array_filter($vpps, fn($v) => ($v['pol'] ?? '') === 'positive')),
            'pol_neg' => count(array_filter($vpps, fn($v) => ($v['pol'] ?? '') === 'negative')),
        ];
    }
}

// ---------------------------------------------------------------------------
// Classic (a second aftermarket maker) — transitive OEM -> HR links
//
// dataset/classic_oem.csv maps OEM codes to Classic's own FBT part numbers.
// Where an FBT part and an HR part are claimed for the same OEM code, the two
// aftermarket makers independently arrived at the same original — so any OTHER
// OEM code Classic lists against that FBT is, by transitivity, a candidate
// equivalent of the HR part.
//
// This is inference, not testimony, so it is admitted only under strict terms:
//   * the FBT part must resolve to exactly ONE HR part (837 of 1,071 do);
//     ambiguous FBTs are dropped rather than guessed at
//   * rows are tagged src='classic' and rendered distinctly, never passed off
//     as something HR Diemen said
// The two bridges (shared OEM codes, and shared TV models) agree on 96.3% of
// the FBT parts where both are available, which is what justifies trusting the
// correspondence at all. See docs/CLASSIC_BRIDGE.md.
// ---------------------------------------------------------------------------
$classic_pairs = 0;
$classicPath = "$EX/classic_oem.csv";
if (is_file($classicPath)) {
    $oemsByFbt = [];     // fbt => [oem, ...]
    $fbtsByOem = [];     // oem_norm => [fbt, ...]
    $fh = fopen($classicPath, 'r');
    $ch = fgetcsv($fh);
    $cidx = array_flip($ch);
    while (($row = fgetcsv($fh)) !== false) {
        $oem = $row[$cidx['oem']];
        $fbt = $row[$cidx['fbt']];
        $oemsByFbt[$fbt][] = $oem;
        $fbtsByOem[norm($oem)][$fbt] = true;
    }
    fclose($fh);

    // Which HR parts does each FBT part correspond to?
    $hrByOemNorm = [];
    foreach ($pairs as [$oem, $h, $_s]) $hrByOemNorm[norm($oem)][$h] = true;

    $hrByFbt = [];
    foreach ($fbtsByOem as $oemNorm => $fbts) {
        if (!isset($hrByOemNorm[$oemNorm])) continue;
        foreach ($fbts as $fbt => $_) {
            foreach ($hrByOemNorm[$oemNorm] as $h => $__) $hrByFbt[$fbt][$h] = true;
        }
    }

    foreach ($hrByFbt as $fbt => $hrs) {
        if (count($hrs) !== 1) continue;          // ambiguous — drop it
        $h = array_key_first($hrs);
        foreach ($oemsByFbt[$fbt] ?? [] as $oem) {
            $key = norm($oem) . "\t" . $h;
            if (isset($seenPair[$key])) continue;
            $seenPair[$key] = true;
            $pairs[] = [$oem, $h, 'classic'];
            $classic_pairs++;
        }
    }
}

// ---------------------------------------------------------------------------
// Data Pin Flyback compilations (extract/parse_datapin_pdfs.py)
//
// Functional pin labels and rectified rail voltages, keyed by OEM part number
// and resolved to HR codes through the cross-reference. Also a handful of extra
// equivalents, and alternative diagrams: a physical bottom-view footprint and a
// connection schematic. The footprint is genuinely new information — HR's own
// drawings show the windings but never the physical pin layout.
// ---------------------------------------------------------------------------
$datapin_pins = 0; $datapin_diagrams = 0; $datapin_equivs = 0;
$hrByOemAll = [];
foreach ($pairs as [$oem, $h, $_s]) $hrByOemAll[norm($oem)][$h] = true;

$pinRows = [];
if (is_file("$EX/datapin_pins.csv")) {
    $fh = fopen("$EX/datapin_pins.csv", 'r');
    $dh = fgetcsv($fh); $di = array_flip($dh);
    while (($row = fgetcsv($fh)) !== false) {
        $cn = $row[$di['code_norm']];
        foreach (array_keys($hrByOemAll[$cn] ?? []) as $h) {
            $pinRows[] = [$h, $row[$di['code']], (int)$row[$di['pin']],
                          $row[$di['function']],
                          $row[$di['volts']] === '' ? null : (float)$row[$di['volts']],
                          $row[$di['src']]];
        }
    }
    fclose($fh);
}

// Extra equivalents stated by the pin-out compilations.
if (is_file("$EX/datapin_equiv.csv")) {
    $fh = fopen("$EX/datapin_equiv.csv", 'r');
    $dh = fgetcsv($fh); $di = array_flip($dh);
    while (($row = fgetcsv($fh)) !== false) {
        $cn = $row[$di['code_norm']];
        $eq = $row[$di['equiv']];
        foreach (array_keys($hrByOemAll[$cn] ?? []) as $h) {
            $key = norm($eq) . "\t" . $h;
            if (isset($seenPair[$key])) continue;
            $seenPair[$key] = true;
            $pairs[] = [$eq, $h, 'datapin'];
            $datapin_equivs++;
        }
    }
    fclose($fh);
}

// Alternative diagrams, appended to the schematics table under their own kind.
if (is_file("$EX/datapin_diagrams.csv")) {
    $fh = fopen("$EX/datapin_diagrams.csv", 'r');
    $dh = fgetcsv($fh); $di = array_flip($dh);
    while (($row = fgetcsv($fh)) !== false) {
        $cn = $row[$di['code_norm']];
        $path = '../' . $row[$di['path']];
        foreach (array_keys($hrByOemAll[$cn] ?? []) as $h) {
            $schem[$h][] = ['kind' => 'datapin', 'path' => $path];
            $datapin_diagrams++;
        }
    }
    fclose($fh);
}

// All HR codes (union of pairs / hr / uses), like build_web_data.py
$codes = [];
foreach ($pairs as [$oem, $h, $_s]) $codes[$h] = true;
foreach ($hr as $h => $_)   $codes[$h] = true;
foreach ($uses as $h => $_) $codes[$h] = true;
$hr_codes = array_keys($codes);

// equivalents grouped by HR (for search_blob + counts)
$equivByHr = [];
foreach ($pairs as [$oem, $h, $_s]) $equivByHr[$h][] = $oem;

// ---------------------------------------------------------------------------
// Canonical spelling per (HR, normalised code)
//
// The sources spell the same part number several ways -- `AT 2075-30102` and
// `AT 2075/30102`, or Sony's `1-453-543-11` alongside a flat `145354311`. They
// already normalise to one key, so search has never been confused by it, but
// the card rendered one chip per spelling and looked like it held six codes
// where it holds three. Pick one spelling to show and hang the rest off it.
//
// The pick is: most separators (richest form), then the spelling most common
// across the whole dataset, then longest, then alphabetical so a rebuild is
// deterministic. Nothing is deleted -- every literal stays a row.
// ---------------------------------------------------------------------------
$litFreq = [];   // how often each exact spelling occurs anywhere
$sepFreq = [];   // house style: which separator characters the sources favour
foreach ($pairs as [$oem, $_h, $_s]) {
    $litFreq[$oem] = ($litFreq[$oem] ?? 0) + 1;
    foreach (preg_split('//u', preg_replace('/[^\s\-_.\/]/', '', (string) $oem), -1,
                        PREG_SPLIT_NO_EMPTY) as $ch) {
        $sepFreq[$ch] = ($sepFreq[$ch] ?? 0) + 1;
    }
}
// Where two spellings differ only in which separator they use -- AT 2075-30102
// vs AT 2075/30102 -- neither is more right; OEMs printed both. Break the tie
// towards whichever character this dataset uses more often, so the tags read
// consistently instead of alternating at random.
$sepScore = function (string $s) use ($sepFreq): int {
    $n = 0;
    foreach (str_split(preg_replace('/[^\s\-_.\/]/', '', $s)) as $ch) {
        $n += $sepFreq[$ch] ?? 0;
    }
    return $n;
};

$byGroup = [];   // "hr\0norm" => [oem => best src rank]
foreach ($pairs as [$oem, $h, $src]) {
    $g = $h . "\0" . norm($oem);
    if (!isset($byGroup[$g][$oem]) || srcRank($src) < $byGroup[$g][$oem]) {
        $byGroup[$g][$oem] = srcRank($src);
    }
}

$canonOf = [];   // "hr\0norm" => [canonical literal, [other literals...]]
$dupRows = 0;
foreach ($byGroup as $g => $lits) {
    // array keys coerce all-numeric codes to int, so cast back before comparing
    $spellings = array_map('strval', array_keys($lits));
    usort($spellings, function (string $a, string $b) use ($litFreq, $sepScore) {
        return [sepCount($b), $litFreq[$b] ?? 0, $sepScore($b), strlen($b), $a]
           <=> [sepCount($a), $litFreq[$a] ?? 0, $sepScore($a), strlen($a), $b];
    });
    $canon = array_shift($spellings);
    $canonOf[$g] = [$canon, $spellings];
    if ($spellings) $dupRows += count($spellings);
}
printf("canonical spellings: %s groups, %s duplicate spellings folded into tooltips\n",
       number_format(count($canonOf)), number_format($dupRows));

// ---------------------------------------------------------------------------
// Insert
// ---------------------------------------------------------------------------
$db->beginTransaction();

$insEq = $db->prepare('INSERT INTO equivalents (hr, oem, oem_norm, src, canon, alts)
                       VALUES (?,?,?,?,?,?)');
$canonDone = [];
foreach ($pairs as [$oem, $h, $src]) {
    $nrm = norm($oem);
    $g   = $h . "\0" . $nrm;
    [$canon, $alts] = $canonOf[$g];
    // one canon row per group, and only for the winning spelling -- a literal
    // repeated across sources must not be flagged twice.
    $isCanon = ($oem === $canon && !isset($canonDone[$g]));
    if ($isCanon) $canonDone[$g] = true;
    $insEq->execute([$h, $oem, $nrm, $src, $isCanon ? 1 : 0,
                     $isCanon && $alts ? json_encode(array_values($alts)) : null]);
}

$insUse = $db->prepare('INSERT INTO uses (hr, fab, fabname, model, model_norm) VALUES (?,?,?,?,?)');
$useCount = [];
foreach ($uses as $h => $list) {
    $useCount[$h] = count($list);
    foreach ($list as $u) {
        $model = trim($u['model'] ?? '');
        $fabname = $u['fabname'] ?? ($fab[$u['fab'] ?? ''] ?? ($u['fab'] ?? ''));
        $insUse->execute([$h, $u['fab'] ?? '', $fabname, $model, norm("$fabname $model")]);
    }
}

$insObs = $db->prepare('INSERT INTO obs (hr, note) VALUES (?,?)');
foreach ($obs as $h => $note) $insObs->execute([$h, $note]);

$insAcc = $db->prepare('INSERT INTO acc (hr, accessory) VALUES (?,?)');
foreach ($acc as $h => $list) foreach ($list as $a) $insAcc->execute([$h, $a]);

/** Image kinds carry the source's own vocabulary; publish them in English.
 *  The distinction is provenance, not subject: an "archived" drawing is the
 *  same drawing, recovered from a web archive rather than from the site. */
function schemKind(string $k): string {
    return [
        'esquema'   => 'schematic',
        'wayback'   => 'schematic (archived)',
        'recovered' => 'schematic (recovered)',
        'trip'      => 'tripler',
        'accesorio' => 'accessory',
        'acc_pdf'   => 'accessory sheet',
        'datapin'   => 'pin-out diagram',
    ][$k] ?? $k;
}

$insSchem = $db->prepare('INSERT INTO schematics (hr, kind, path) VALUES (?,?,?)');
$hasImage = [];
foreach ($schem as $h => $imgs) {
    $hasImage[$h] = count($imgs) > 0;
    foreach ($imgs as $img) $insSchem->execute([$h, schemKind((string)($img['kind'] ?? '')), $img['path']]);
}

$insR = $db->prepare('INSERT INTO hrt_r (hr, total, top, bot, pot, verified, file) VALUES (?,?,?,?,?,?,?)');
foreach ($hrt_r as $h => $r) {
    $insR->execute([$h, $r['total'] ?? null, $r['top'] ?? null, $r['bot'] ?? null,
                    $r['pot'] ?? null, !empty($r['verified']) ? 1 : 0, $r['file'] ?? null]);
}

$insPf = $db->prepare('INSERT INTO pinfunc (hr, oem, pin, function, volts, src) VALUES (?,?,?,?,?,?)');
foreach ($pinRows as $r) { $insPf->execute($r); $datapin_pins++; }

// Service-manual leads (extract/service_manual_worklist.py --found)
$manual_rows = 0;
$insMan = $db->prepare('INSERT INTO manuals (hr, chassis, url, note, confidence) VALUES (?,?,?,?,?)');
foreach (loadJson("$EX/service_manual_found.json") as $code => $m) {
    $insMan->execute([$code, $m['chassis'] ?? '', $m['url'] ?? '',
                      $m['note'] ?? '', $m['confidence'] ?? 'lead']);
    $manual_rows++;
}

$insVpp = $db->prepare('INSERT INTO vpps (hr, v, pin, pol, side, wnd) VALUES (?,?,?,?,?,?)');
$insRole = $db->prepare('INSERT INTO roles (hr, role) VALUES (?,?)');
foreach ($sch as $h => $s) {
    foreach ($s['vpps'] as $v) $insVpp->execute([$h, $v['v'], $v['pin'], $v['pol'], $v['side'], $v['wnd']]);
    foreach ($s['roles'] as $r) $insRole->execute([$h, $r]);
}

$insHr = $db->prepare(<<<'SQL'
INSERT INTO hr (code, tester_type, family, family_image, mat_kv, pinB, pinC, pinsD,
  alim_or_deflection, weight_g, box_class, box_x, box_y, box_z, box_image, source,
  n_wnd, n_seg, v_min, v_max, pol_pos, pol_neg, n_uses, n_equiv, has_image, listed,
  code_blob, use_blob)
VALUES (:code,:tester_type,:family,:family_image,:mat_kv,:pinB,:pinC,:pinsD,
  :alim,:weight,:caixa,:bx,:by,:bz,:bimg,:source,
  :n_wnd,:n_seg,:v_min,:v_max,:pol_pos,:pol_neg,:n_uses,:n_equiv,:has_image,:listed,
  :code_blob,:use_blob)
SQL);

foreach ($hr_codes as $code) {
    $row = $hr[$code] ?? ['hr' => $code];
    $s   = $sch[$code] ?? null;

    // box dims: prefer box-letter lookup, else raw dim from site record
    $caixa = $row['box_class'] ?? null;
    $bx = $by = $bz = null; $bimg = null;
    if ($caixa && isset($boxes[$caixa])) {
        $bx = $boxes[$caixa]['x']; $by = $boxes[$caixa]['y'];
        $bz = $boxes[$caixa]['z']; $bimg = $boxes[$caixa]['image'];
    } elseif (!empty($row['dim'])) {
        $bx = $row['dim']['x_cm'] ?? null; $by = $row['dim']['y_cm'] ?? null; $bz = $row['dim']['z_cm'] ?? null;
    }

    $famKey = strtoupper($row['family'] ?? '');
    $famImg = $famKey && isset($fam_map[$famKey]) ? $fam_map[$famKey] : null;

    // normalised search blobs. code_blob = HR code + all OEM codes (always
    // searched); use_blob = usage "fabname model" strings (only when the
    // "include TV/monitor models" toggle is on).
    $codeParts = [norm($code)];
    foreach ($equivByHr[$code] ?? [] as $oem) $codeParts[] = norm($oem);
    $codeBlob = implode(' ', array_filter(array_unique($codeParts)));
    $useParts = [];
    foreach ($uses[$code] ?? [] as $u) $useParts[] = norm(($u['fabname'] ?? '') . ' ' . ($u['model'] ?? ''));
    $useBlob = implode(' ', array_filter(array_unique($useParts)));

    $insHr->execute([
        ':code' => $code,
        ':tester_type' => $row['tester_type'] ?? null,
        ':family' => $row['family'] ?? null,
        ':family_image' => $famImg,
        ':mat_kv' => $row['mat_kv'] ?? null,
        ':pinB' => $row['pinB'] ?? null,
        ':pinC' => $row['pinC'] ?? null,
        ':pinsD' => json_encode($row['pinsD'] ?? []),
        ':alim' => $row['alim_or_deflection'] ?? null,
        ':weight' => $row['weight_g'] ?? null,
        ':caixa' => $caixa,
        ':bx' => $bx, ':by' => $by, ':bz' => $bz, ':bimg' => $bimg,
        ':source' => $row['_source'] ?? (isset($ghost_codes[$code]) ? 'xref_2011' : null),
        ':n_wnd' => $s['n_wnd'] ?? null,
        ':n_seg' => $s['n_seg'] ?? null,
        ':v_min' => $s['v_min'] ?? null,
        ':v_max' => $s['v_max'] ?? null,
        ':pol_pos' => $s['pol_pos'] ?? 0,
        ':pol_neg' => $s['pol_neg'] ?? 0,
        ':n_uses' => $useCount[$code] ?? 0,
        ':n_equiv' => count(array_unique(array_map('norm', $equivByHr[$code] ?? []))),
        ':listed' => isset($presence[$code]) ? 1 : 0,
        ':has_image' => !empty($hasImage[$code]) ? 1 : 0,
        ':code_blob' => $codeBlob,
        ':use_blob' => $useBlob,
    ]);
}

// Indexes (after bulk insert)
$db->exec('CREATE INDEX idx_eq_hr   ON equivalents(hr)');
$db->exec('CREATE INDEX idx_eq_norm ON equivalents(oem_norm)');
$db->exec('CREATE INDEX idx_eq_canon ON equivalents(hr, canon)');
$db->exec('CREATE INDEX idx_use_hr  ON uses(hr)');
$db->exec('CREATE INDEX idx_vpp_hr  ON vpps(hr)');
$db->exec('CREATE INDEX idx_role_hr ON roles(hr)');
$db->exec('CREATE INDEX idx_pinfunc_hr ON pinfunc(hr)');
$db->exec('CREATE INDEX idx_manuals_hr ON manuals(hr)');
$db->exec('CREATE INDEX idx_schem_hr ON schematics(hr)');
$db->exec('CREATE INDEX idx_hr_tipo ON hr(tester_type)');

// ---------------------------------------------------------------------------
// Candidate substitutes
//
// Cross-referencing on raw voltages finds almost nothing, because two flybacks
// are rarely identical. What they can be is the *same design at a different
// scale*: every winding on a flyback is ratiometric to the primary, so
// normalising each part's taps to its own largest tap makes two parts that are
// the same shape comparable even when no single voltage matches.
//
// Filters, in the order a repairer would apply them:
//   1. same tester class -- a 15.6 kHz TV flyback is not a candidate for a
//      multiscan monitor at any voltage, so this comes first
//   2. tap profiles within MAX_SHAPE_D of each other
//   3. the implied B+ rescale stays inside SCALE_LO..SCALE_HI. The anode
//      voltage scales by the same factor, so a part needing B+ x0.5 halves the
//      EHT, which the CRT will not forgive
//   4. same deflection angle / scan band where both are recorded
//
// MAT adequacy and pin identity are recorded rather than filtered, so the UI
// can rank on them without hiding a part that only needs its wires moved.
// ---------------------------------------------------------------------------
const SUB_MAX_SHAPE_D = 0.12;
const SUB_SCALE_LO = 0.80;
const SUB_SCALE_HI = 1.25;
const SUB_TOL = 0.10;      // fractional tolerance when pairing individual taps

/** Tap voltages normalised to the part's own largest, descending. */
function subShape(array $taps): array {
    $top = max($taps);
    if ($top <= 0) return [];
    $out = array_map(fn($v) => $v / $top, $taps);
    rsort($out);
    return $out;
}

/** Mean fractional difference between two profiles, largest paired with largest.
 *  Null when they cannot be aligned meaningfully. */
function subShapeDist(array $a, array $b): ?float {
    if (!$a || !$b || abs(count($a) - count($b)) > 1) return null;
    $n = min(count($a), count($b));
    if ($n < 2) return null;               // one tap normalises to 1.0 -- no information
    $sum = 0.0;
    for ($i = 0; $i < $n; $i++) $sum += abs($a[$i] - $b[$i]) / max($a[$i], $b[$i], 1e-9);
    return $sum / $n;
}

/** How many of $want are absent from $have, pairing greedily within SUB_TOL. */
function subMissing(array $want, array $have): int {
    $pool = $have; $missing = 0;
    foreach ($want as $w) {
        $hit = null;
        foreach ($pool as $k => $h) {
            if ($w == 0 || $h == 0 ? $w == $h : abs($w - $h) / max($w, $h) <= SUB_TOL) { $hit = $k; break; }
        }
        if ($hit === null) $missing++; else unset($pool[$hit]);
    }
    return $missing;
}

/** The 2003 database writes pin "02" where the site records write "2"; compare numerically. */
function subPin(?string $p): ?string {
    $p = strtoupper(trim((string)$p));
    if ($p === '') return null;
    return ctype_digit($p) ? (string)(int)$p : $p;
}

$subParts = [];
foreach ($db->query("SELECT code, tester_type, mat_kv, pinB, pinC, alim_or_deflection FROM hr") as $r) {
    $subParts[$r['code']] = [
        'tester_type' => strtoupper((string)$r['tester_type']),
        'mat'  => $r['mat_kv'] !== null ? (float)$r['mat_kv'] : null,
        'pinB' => subPin($r['pinB']), 'pinC' => subPin($r['pinC']),
        'alim' => strtoupper(trim((string)$r['alim_or_deflection'])),
        'taps' => [],
    ];
}
foreach ($db->query("SELECT hr, v FROM vpps WHERE v IS NOT NULL") as $r) {
    if (isset($subParts[$r['hr']])) $subParts[$r['hr']]['taps'][] = (int)$r['v'];
}
// Two taps is the floor: a single tap normalises to 1.0 and carries no shape.
$subParts = array_filter($subParts, fn($p) => count($p['taps']) >= 2);
foreach ($subParts as &$p) { $p['shape'] = subShape($p['taps']); $p['top'] = max($p['taps']); }
unset($p);

// Bucket by tap count: profiles differing by more than one tap can never align,
// so only three buckets are ever compared against each other.
$subByLen = [];
foreach ($subParts as $code => $p) $subByLen[count($p['shape'])][] = $code;

// Already inside the build's single transaction; do not open another.
$insSub = $db->prepare('INSERT INTO substitutes
    (hr, cand, shape_d, scale, same_pins, mat_ok, n_extra, n_missing) VALUES (?,?,?,?,?,?,?,?)');
$subPairs = 0;
foreach ($subParts as $a => $A) {
    $len = count($A['shape']);
    foreach ([$len - 1, $len, $len + 1] as $l) {
        foreach ($subByLen[$l] ?? [] as $b) {
            if ($a === $b) continue;
            $B = $subParts[$b];
            if ($A['tester_type'] !== '' && $B['tester_type'] !== '' && $A['tester_type'] !== $B['tester_type']) continue;
            $d = subShapeDist($A['shape'], $B['shape']);
            if ($d === null || $d > SUB_MAX_SHAPE_D) continue;
            $scale = $A['top'] > 0 ? $B['top'] / $A['top'] : 0;
            if ($scale < SUB_SCALE_LO || $scale > SUB_SCALE_HI) continue;
            if ($A['alim'] !== '' && $B['alim'] !== '' && $A['alim'] !== $B['alim']) continue;
            $insSub->execute([
                $a, $b, round($d, 4), round($scale, 4),
                ($A['pinB'] !== null && $A['pinB'] === $B['pinB']
                 && $A['pinC'] !== null && $A['pinC'] === $B['pinC']) ? 1 : 0,
                ($A['mat'] !== null && $B['mat'] !== null) ? ($B['mat'] >= $A['mat'] - 0.05 ? 1 : 0) : 1,
                subMissing($B['taps'], $A['taps']),   // candidate taps the target lacks
                subMissing($A['taps'], $B['taps']),   // target taps the candidate lacks
            ]);
            $subPairs++;
        }
    }
}
$db->exec('CREATE INDEX idx_sub_hr ON substitutes(hr)');
printf("substitute candidates: %s pairs over %s parts\n",
       number_format($subPairs),
       number_format((int)$db->query('SELECT COUNT(DISTINCT hr) FROM substitutes')->fetchColumn()));

$insMeta = $db->prepare('INSERT INTO meta (key, value) VALUES (?,?)');
$insMeta->execute(['generated', date('c')]);
// Which release this database was built from, so the page can say what is
// deployed without anyone having to check a commit hash.
$insMeta->execute(['version', trim((string)@file_get_contents("$ROOT/VERSION")) ?: 'dev']);
// n_pairs counts distinct parts, not spellings of them -- $canonOf has exactly
// one entry per (HR, normalised OEM code).
$insMeta->execute(['n_pairs', (string) count($canonOf)]);
$insMeta->execute(['n_pair_rows', (string) count($pairs)]);
$insMeta->execute(['n_codes', (string) count($hr_codes)]);
$insMeta->execute(['n_xref_pairs', (string) $xref_pairs]);
$insMeta->execute(['n_ghost_codes', (string) count($ghost_codes)]);
$insMeta->execute(['n_classic_pairs', (string) $classic_pairs]);

// Headline counts for the page header. COUNT(DISTINCT model_norm) over ~400,000
// usage rows took ~60 ms, and it ran on every single page load; precomputing it
// here turns the busiest endpoint on the site into three key lookups.
$insMeta->execute(['stat_parts',  (string) (int) $db->query('SELECT COUNT(*) FROM hr')->fetchColumn()]);
$insMeta->execute(['stat_codes',  (string) (int) $db->query('SELECT COUNT(DISTINCT oem_norm) FROM equivalents')->fetchColumn()]);
$insMeta->execute(['stat_models', (string) (int) $db->query('SELECT COUNT(DISTINCT model_norm) FROM uses')->fetchColumn()]);
$insMeta->execute(['stat_untyped', (string) (int) $db->query(
    "SELECT COUNT(*) FROM hr WHERE tester_type IS NULL OR tester_type = ''")->fetchColumn()]);

// Set-manufacturer vocabulary for the "did you mean" suggester. Built here so a
// flood of nonsense queries cannot make the site re-derive it per request.
$db->exec('CREATE TABLE brands (name TEXT, name_norm TEXT PRIMARY KEY, n INTEGER)');
$insBrand = $db->prepare('INSERT OR REPLACE INTO brands (name, name_norm, n) VALUES (?,?,?)');
$brandRows = $db->query("SELECT fabname, COUNT(DISTINCT hr) n FROM uses
                         WHERE fabname IS NOT NULL AND fabname <> '' GROUP BY fabname");
$brandSeen = [];
foreach ($brandRows as $b) {
    $k = norm($b['fabname']);
    if ($k === '') continue;
    // Two spellings can normalise together; keep the better-attested one.
    if (isset($brandSeen[$k]) && $brandSeen[$k] >= (int)$b['n']) continue;
    $brandSeen[$k] = (int)$b['n'];
    $insBrand->execute([$b['fabname'], $k, (int)$b['n']]);
}
printf("brand vocabulary: %s names\n", number_format(count($brandSeen)));

$db->commit();
$db->exec('PRAGMA optimize');

// ---------------------------------------------------------------------------
// Report (parity check against build_web_data.py)
// ---------------------------------------------------------------------------
$count = fn(string $sql) => (int) $db->query($sql)->fetchColumn();
printf("  + %d hr-table records from site_2012\n", $added_hr);
printf("  + %d hr->uses lists from site_2012\n", $added_uses);
printf("  + %d schematic-extract records (windings, taps, roles)\n", count($sch));
printf("  + %s new pairs from the 2011 catalogue PDF (%s data-less HR codes)\n",
       number_format($xref_pairs), number_format(count($ghost_codes)));
printf("  + %s inferred pairs via Classic FBT correspondences\n", number_format($classic_pairs));
printf("  + %s pin-function rows, %s alt diagrams, %s equivalents from Data-Pin PDFs\n",
       number_format($datapin_pins), number_format($datapin_diagrams), number_format($datapin_equivs));
printf("  + %s service-manual leads\n", number_format($manual_rows));
echo "wrote $DB_PATH (" . number_format(filesize($DB_PATH)) . " bytes)\n";
printf("  pairs:        %s  (%s rows incl. spelling variants)\n",
       number_format($count('SELECT COUNT(*) FROM equivalents WHERE canon=1')),
       number_format($count('SELECT COUNT(*) FROM equivalents')));
printf("  hr codes:     %s\n", number_format($count('SELECT COUNT(*) FROM hr')));
printf("  with hr-row:  %s\n", number_format(count(array_filter($hr, fn($r) => isset($r['hr'])))));
printf("  with uses:    %s\n", number_format($count('SELECT COUNT(DISTINCT hr) FROM uses')));
printf("  with engl:    %s\n", number_format($count('SELECT COUNT(*) FROM obs')));
printf("  with accs:    %s\n", number_format($count('SELECT COUNT(DISTINCT hr) FROM acc')));
printf("  with sch:     %s\n", number_format($count('SELECT COUNT(*) FROM hr WHERE n_wnd IS NOT NULL OR EXISTS(SELECT 1 FROM vpps WHERE vpps.hr=hr.code) OR EXISTS(SELECT 1 FROM roles WHERE roles.hr=hr.code)')));
printf("  box sizes:    %s\n", number_format(count($boxes)));
