<?php
declare(strict_types=1);

/** Normalise a code/model: drop every non-alphanumeric character, uppercase the
 *  rest. Punctuation carries no meaning in these part numbers -- the same code is
 *  printed AT 2075/30102, AT 2075-30102 and AT207530102 -- so it is stripped
 *  rather than matched. Unicode-aware: accented letters in TV model names are
 *  letters and survive; brackets, *, +, comma, colon and OCR debris are not.
 *  Must stay identical to norm() in bin/build-db.php, which builds the
 *  columns this is compared against. See docs/OEM_CODES.md. */
function hr_norm(?string $s): string
{
    return strtoupper(preg_replace('/[^\p{L}\p{N}]+/u', '', $s ?? ''));
}

/** Estimated focus-voltage range for a bleeder network at a given EHT (kV).
 *  Mirrors ufRange() in the original webapp. Returns ['min'=>, 'max'=>] or null. */
function hr_uf_range(?float $top, ?float $bot, ?float $pot, float $eht): ?array
{
    if ($top === null || $bot === null) return null;
    $P = $pot ?? 0.0;
    if ($P <= 0) {
        $denom = $top + $bot;
        if ($denom <= 0) return null;
        $uf = $eht * $top / $denom;
        return ['min' => $uf, 'max' => $uf];
    }
    $total = $top + $bot + $P;
    if ($total <= 0) return null;
    return ['min' => $eht * $top / $total, 'max' => $eht * ($top + $P) / $total];
}
