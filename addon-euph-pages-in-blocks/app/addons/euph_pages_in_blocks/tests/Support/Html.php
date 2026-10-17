<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphPagesInBlocks\Tests\Support;

/**
 * Rendered markup, queried with XPath. libxml reads HTML 4, so it complains
 * about <details> and <summary>: those notices are dropped.
 */
final class Html
{
    public readonly \DOMXPath $xpath;

    public function __construct(public readonly string $html)
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>', LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $this->xpath = new \DOMXPath($document);
    }

    /**
     * @return list<\DOMElement>
     */
    public function all(string $query): array
    {
        $nodes = $this->xpath->query($query);
        $elements = [];
        foreach ($nodes === false ? [] : $nodes as $node) {
            if ($node instanceof \DOMElement) {
                $elements[] = $node;
            }
        }

        return $elements;
    }

    public function one(string $query): \DOMElement
    {
        $found = $this->all($query);
        if (count($found) !== 1) {
            throw new \LogicException(sprintf('%d elements for %s in %s', count($found), $query, $this->html));
        }

        return $found[0];
    }

    public function count(string $query): int
    {
        return count($this->all($query));
    }

    /** The text of an element, on one line. */
    public static function text(\DOMNode $node): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
    }

    /**
     * @return list<string>
     */
    public function texts(string $query): array
    {
        return array_map(self::text(...), $this->all($query));
    }

    /** XPath for "has this class". */
    public static function cls(string $class): string
    {
        return "contains(concat(' ', normalize-space(@class), ' '), ' " . $class . " ')";
    }
}
