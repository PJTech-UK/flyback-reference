<?php
declare(strict_types=1);

/**
 * Recursive-descent parser for the search grammar:
 *
 *   expr    := orExpr
 *   orExpr  := andExpr ( 'OR' andExpr )*
 *   andExpr := notExpr ( ['AND'] notExpr )*        -- adjacency = implicit AND
 *   notExpr := 'NOT' notExpr | primary
 *   primary := '(' expr ')' | TERM
 *
 * A TERM is a single whitespace-delimited token: either a factor filter such as
 * `vpp:200-300`, `uf>=7`, `role:focus`, or a free-text word like `BG1897`.
 *
 * Produces an AST of nested arrays:
 *   ['and', [child, ...]]  ['or', [child, ...]]  ['not', child]  ['leaf', 'token']
 *
 * The parser is intentionally forgiving: unmatched ')' and trailing operators are
 * ignored rather than erroring, so a half-typed query never throws.
 */
final class QueryParser
{
    /** @var string[] */
    private array $toks;
    private int $i = 0;

    public static function parse(string $q): ?array
    {
        $p = new self($q);
        if (!$p->toks) return null;
        $ast = $p->parseOr();
        return $ast;
    }

    private function __construct(string $q)
    {
        $this->toks = self::mergeFreeText(self::tokenize($q));
    }

    /** True if a token is plain free text (not a paren, operator, or factor filter). */
    private static function isFreeText(string $t): bool
    {
        return $t !== '(' && $t !== ')'
            && !self::isKeyword($t, 'AND') && !self::isKeyword($t, 'OR') && !self::isKeyword($t, 'NOT')
            && !QueryCompiler::looksLikeFactor($t);
    }

    /**
     * Merge runs of adjacent free-text tokens into a single phrase so that
     * "sony kv-1234" matches as one contiguous string (as the original app did),
     * while operators and parentheses still break the run. Factor filters are
     * never merged.
     */
    private static function mergeFreeText(array $toks): array
    {
        $out = [];
        foreach ($toks as $t) {
            if ($out !== [] && self::isFreeText($t) && self::isFreeText($out[count($out) - 1])) {
                $out[count($out) - 1] .= ' ' . $t;
            } else {
                $out[] = $t;
            }
        }
        return $out;
    }

    /**
     * Split into tokens: parentheses are standalone; everything else splits on
     * whitespace. Double quotes group text that contains spaces or parens into a
     * single token (the quote chars are dropped), e.g. network:"HR 7002".
     */
    private static function tokenize(string $q): array
    {
        $out = [];
        $buf = '';
        $inQuote = false;
        $flush = function () use (&$buf, &$out) {
            if ($buf !== '') { $out[] = $buf; $buf = ''; }
        };
        $len = strlen($q);
        for ($k = 0; $k < $len; $k++) {
            $c = $q[$k];
            if ($c === '"') { $inQuote = !$inQuote; continue; }
            if ($inQuote)                 { $buf .= $c; }
            elseif ($c === '(' || $c === ')') { $flush(); $out[] = $c; }
            elseif (ctype_space($c))      { $flush(); }
            else                          { $buf .= $c; }
        }
        $flush();
        return $out;
    }

    private function peek(): ?string { return $this->toks[$this->i] ?? null; }
    private function next(): ?string { return $this->toks[$this->i++] ?? null; }

    private static function isKeyword(?string $t, string $kw): bool
    {
        return $t !== null && strcasecmp($t, $kw) === 0;
    }

    private function parseOr(): ?array
    {
        $left = $this->parseAnd();
        $parts = $left !== null ? [$left] : [];
        while (self::isKeyword($this->peek(), 'OR')) {
            $this->next();
            $r = $this->parseAnd();
            if ($r !== null) $parts[] = $r;
        }
        if (!$parts) return null;
        return count($parts) === 1 ? $parts[0] : ['or', $parts];
    }

    private function parseAnd(): ?array
    {
        $parts = [];
        while (true) {
            $t = $this->peek();
            if ($t === null) break;
            if ($t === ')') break;
            if (self::isKeyword($t, 'OR')) break;
            if (self::isKeyword($t, 'AND')) { $this->next(); continue; }
            $node = $this->parseNot();
            if ($node !== null) $parts[] = $node;
            else break;
        }
        if (!$parts) return null;
        return count($parts) === 1 ? $parts[0] : ['and', $parts];
    }

    private function parseNot(): ?array
    {
        if (self::isKeyword($this->peek(), 'NOT')) {
            $this->next();
            $child = $this->parseNot();
            return $child === null ? null : ['not', $child];
        }
        return $this->parsePrimary();
    }

    private function parsePrimary(): ?array
    {
        $t = $this->peek();
        if ($t === null) return null;
        if ($t === '(') {
            $this->next();
            $e = $this->parseOr();
            if ($this->peek() === ')') $this->next(); // tolerate missing close
            return $e;
        }
        if ($t === ')') return null;
        $this->next();
        return ['leaf', $t];
    }
}
