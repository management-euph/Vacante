<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Support;

/**
 * In-memory booking table that evaluates the WHERE clause of a CS-Cart style
 * query (?i / ?s placeholders, bound left to right) against fixture rows.
 *
 * Lets ownership tests assert WHICH rows a query matches — "an anonymous
 * visitor does not own a stranger's guest booking" — instead of only pinning
 * the SQL text. Supports exactly what the ownership queries use: comparisons
 * (=, <>, !=, >, <) against ?i, ?s, integer or quoted literals, combined with
 * AND, OR and parentheses. Anything else throws, so a query the fake cannot
 * read fails the test rather than silently matching nothing.
 */
final class BookingTableFake
{
    /** @param list<array<string, int|string>> $rows */
    public function __construct(private readonly array $rows)
    {
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string, int|string>>
     */
    public function select(string $sql, array $params): array
    {
        if (preg_match('/\bWHERE\s+(.+?)(?:\s+ORDER\s+BY\b|\s+LIMIT\b|$)/is', $sql, $m) !== 1) {
            throw new \InvalidArgumentException('No WHERE clause in: ' . $sql);
        }
        $tokens = self::tokenize($m[1], $params);

        $matched = [];
        foreach ($this->rows as $row) {
            $pos = 0;
            $hit = self::parseOr($tokens, $pos, $row);
            if ($pos !== count($tokens)) {
                throw new \InvalidArgumentException('Unparsed WHERE tail in: ' . $sql);
            }
            if ($hit) {
                $matched[] = $row;
            }
        }

        return $matched;
    }

    /**
     * @param list<mixed> $params
     * @return list<string|array{0: string, 1: string, 2: int|string}>
     */
    private static function tokenize(string $where, array $params): array
    {
        $pattern = '/\s*(?:(\()|(\))|(AND|OR)\b|([a-z_]+)\s*(=|<>|!=|>|<)\s*(\?i|\?s|-?\d+|\'[^\']*\'))/iA';
        $tokens = [];
        $offset = 0;
        $length = strlen(rtrim($where));
        while ($offset < $length) {
            if (preg_match($pattern, $where, $m, 0, $offset) !== 1) {
                throw new \InvalidArgumentException('Unsupported WHERE syntax at: ' . substr($where, $offset));
            }
            $offset += strlen($m[0]);
            if (($m[1] ?? '') !== '') {
                $tokens[] = '(';
            } elseif (($m[2] ?? '') !== '') {
                $tokens[] = ')';
            } elseif (($m[3] ?? '') !== '') {
                $tokens[] = strtoupper($m[3]);
            } else {
                $raw = $m[6];
                if ($raw === '?i' || $raw === '?s') {
                    if ($params === []) {
                        throw new \InvalidArgumentException('Not enough params for placeholders');
                    }
                    $param = array_shift($params);
                    $value = $raw === '?i' ? (int) $param : (string) $param;
                } elseif ($raw[0] === "'") {
                    $value = substr($raw, 1, -1);
                } else {
                    $value = (int) $raw;
                }
                $tokens[] = [$m[4], $m[5], $value];
            }
        }
        if ($params !== []) {
            throw new \InvalidArgumentException('More params than placeholders');
        }

        return $tokens;
    }

    /**
     * @param list<string|array{0: string, 1: string, 2: int|string}> $tokens
     * @param array<string, int|string> $row
     */
    private static function parseOr(array $tokens, int &$pos, array $row): bool
    {
        $result = self::parseAnd($tokens, $pos, $row);
        while (($tokens[$pos] ?? null) === 'OR') {
            $pos++;
            $rhs = self::parseAnd($tokens, $pos, $row);
            $result = $result || $rhs;
        }

        return $result;
    }

    /**
     * @param list<string|array{0: string, 1: string, 2: int|string}> $tokens
     * @param array<string, int|string> $row
     */
    private static function parseAnd(array $tokens, int &$pos, array $row): bool
    {
        $result = self::parseFactor($tokens, $pos, $row);
        while (($tokens[$pos] ?? null) === 'AND') {
            $pos++;
            $rhs = self::parseFactor($tokens, $pos, $row);
            $result = $result && $rhs;
        }

        return $result;
    }

    /**
     * @param list<string|array{0: string, 1: string, 2: int|string}> $tokens
     * @param array<string, int|string> $row
     */
    private static function parseFactor(array $tokens, int &$pos, array $row): bool
    {
        $token = $tokens[$pos] ?? null;
        if ($token === '(') {
            $pos++;
            $result = self::parseOr($tokens, $pos, $row);
            if (($tokens[$pos] ?? null) !== ')') {
                throw new \InvalidArgumentException('Unbalanced parentheses');
            }
            $pos++;

            return $result;
        }
        if (!is_array($token)) {
            throw new \InvalidArgumentException('Expected a comparison');
        }
        $pos++;

        [$column, $op, $value] = $token;
        if (!array_key_exists($column, $row)) {
            throw new \InvalidArgumentException('Unknown column: ' . $column);
        }
        $actual = is_int($value) ? (int) $row[$column] : (string) $row[$column];

        return match ($op) {
            '=' => $actual === $value,
            '<>', '!=' => $actual !== $value,
            '>' => $actual > $value,
            '<' => $actual < $value,
            default => throw new \InvalidArgumentException('Unsupported operator: ' . $op),
        };
    }
}
