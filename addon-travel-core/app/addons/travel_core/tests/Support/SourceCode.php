<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Support;

/**
 * Read PHP source the way a test that asserts ON source should read it.
 *
 * Much of this suite asserts against source text, because the CS-Cart kit is
 * licensed code that is not in the repository and cannot be executed here.
 * Two mistakes kept letting such assertions pass on broken code, and a review
 * proved it by mutation — eight separate mutants survived every suite:
 *
 *  1. Comments satisfied them. A test for "calls CronKeyService::generate()"
 *     passed against a controller whose only mention of that call was in a
 *     comment explaining it.
 *  2. They matched the wrong occurrence. strpos() returns the FIRST hit, and
 *     once two functions share a string the assertion silently starts
 *     answering about the other one. A heal-order check anchored on a loop
 *     that appears twice in the file, and could no longer fail at all.
 *
 * code() removes (1). body() removes (2) by returning exactly one function's
 * body, found by matching braces on PHP's own tokens rather than on text — so
 * a brace inside a string literal or a comment cannot end it early.
 */
final class SourceCode
{
    /** The file with every comment and docblock removed. */
    public static function code(string $path): string
    {
        $out = '';
        foreach (self::tokens($path) as $token) {
            $out .= $token[0];
        }

        return $out;
    }

    /**
     * The comment-stripped body of the one function whose declaration contains
     * $signature — from its opening brace to its matching closing brace.
     *
     * Fails loudly (throws) if the signature is missing or ambiguous, rather
     * than returning something a caller would then assert against.
     */
    public static function body(string $path, string $signature, ?string $within = null): string
    {
        $tokens = self::tokens($path);
        $code = '';
        $offsets = [];
        foreach ($tokens as $i => $token) {
            $offsets[$i] = strlen($code);
            $code .= $token[0];
        }

        // $within narrows the search to one enclosing block first — for a
        // statement like a foreach header that legitimately appears in more
        // than one function of the same file.
        [$from, $to] = [0, strlen($code)];
        if ($within !== null) {
            [$from, $to] = self::range($tokens, $offsets, $code, $within, 0, strlen($code), $path);
        }

        [$start, $end] = self::range($tokens, $offsets, $code, $signature, $from, $to, $path);

        return substr($code, $start, $end - $start);
    }

    /**
     * [start, end) of the braced block that follows the ONE occurrence of
     * $needle inside [$from, $to).
     *
     * @param list<array{0: string, 1: string}> $tokens
     * @param array<int, int>                   $offsets
     *
     * @return array{0: int, 1: int}
     */
    private static function range(array $tokens, array $offsets, string $code, string $needle, int $from, int $to, string $path): array
    {
        $region = substr($code, $from, $to - $from);
        $rel = strpos($region, $needle);
        if ($rel === false) {
            throw new \RuntimeException("'{$needle}' is not in {$path}");
        }
        if (strpos($region, $needle, $rel + 1) !== false) {
            throw new \RuntimeException("'{$needle}' is ambiguous in {$path}: it occurs more than once in the searched region");
        }
        $at = $from + $rel;

        $depth = 0;
        $start = null;
        foreach ($tokens as $i => $token) {
            if ($offsets[$i] < $at) {
                continue;
            }
            if ($token[1] === 'open') {
                $depth++;
                $start ??= $offsets[$i];
            } elseif ($token[1] === 'close' && $start !== null) {
                $depth--;
                if ($depth === 0) {
                    return [$start, $offsets[$i] + 1];
                }
            }
        }

        throw new \RuntimeException("no balanced body after '{$needle}' in {$path}");
    }

    /**
     * Comment-free tokens as [text, 'open'|'close'|''].
     *
     * `{` inside a double-quoted string arrives as T_CURLY_OPEN or
     * T_DOLLAR_OPEN_CURLY_BRACES and is closed by a bare `}`, so both count as
     * openers. A `{` inside a single-quoted string or a heredoc body is part
     * of one T_CONSTANT_ENCAPSED_STRING / T_ENCAPSED_AND_WHITESPACE token and
     * is correctly never seen as a brace.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function tokens(string $path): array
    {
        $src = file_get_contents($path);
        if ($src === false) {
            throw new \RuntimeException("cannot read {$path}");
        }

        $out = [];
        foreach (token_get_all($src) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $kind = in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true) ? 'open' : '';
                $out[] = [$token[1], $kind];

                continue;
            }
            $out[] = [$token, $token === '{' ? 'open' : ($token === '}' ? 'close' : '')];
        }

        return $out;
    }
}
