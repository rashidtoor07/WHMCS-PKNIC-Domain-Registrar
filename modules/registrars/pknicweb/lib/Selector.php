<?php

declare(strict_types=1);

namespace PknicWeb;

/**
 * Element lookup for pknic_map.php selectors. Three syntaxes are accepted:
 *  - XPath:   starts with "/", "./", "(" or "xpath:"   e.g. //td[.="Expiry"]/following-sibling::td[1]
 *  - Regex:   starts with "regex:"                      e.g. regex:#Expiry:\s*([\d-]+)#  (group 1, else whole match)
 *  - CSS-ish: tag, #id, .class, [attr], [attr=v], [attr^=v], [attr$=v], [attr*=v], [attr~=v],
 *             :contains(text), :first-child, :last-child, :nth-child(n),
 *             combinators " ", ">", "+", "~" and "," groups.
 */
final class Selector
{
    private const BLOCK_TAGS = [
        'br', 'p', 'div', 'li', 'tr', 'td', 'th', 'table', 'ul', 'ol', 'dt', 'dd',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'option', 'section', 'article', 'span',
    ];

    public static function loadHtml(string $html): \DOMDocument
    {
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        if (trim($html) === '') {
            $html = '<html><body></body></html>';
        }
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $doc;
    }

    public static function isXPath(string $selector): bool
    {
        $s = ltrim($selector);
        return $s !== '' && ($s[0] === '/' || $s[0] === '(' || str_starts_with($s, './') || str_starts_with($s, 'xpath:'));
    }

    /** @return \DOMNode[] */
    public static function query(\DOMDocument $doc, string $selector, ?\DOMNode $context = null): array
    {
        $xpath = new \DOMXPath($doc);
        $expr = self::toXPath($selector, $context !== null);
        $result = @$xpath->query($expr, $context);
        if ($result === false) {
            throw new MapException("Invalid selector/XPath: $selector");
        }
        return iterator_to_array($result);
    }

    /**
     * Text of every match. Inputs/selects yield their value; <br>/block boundaries become newlines.
     * @return string[]
     */
    public static function texts(string $html, string $selector): array
    {
        $selector = trim($selector);
        if (str_starts_with($selector, 'regex:')) {
            $pattern = substr($selector, 6);
            if (@preg_match_all($pattern, $html, $m) === false) {
                throw new MapException("Invalid regex selector: $selector");
            }
            $group = isset($m[1]) ? $m[1] : $m[0];
            return array_values(array_map(
                static fn (string $s): string => self::clean(html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                $group
            ));
        }

        $doc = self::loadHtml($html);
        $out = [];
        foreach (self::query($doc, $selector) as $node) {
            $out[] = self::nodeValue($node);
        }
        return $out;
    }

    public static function nodeValue(\DOMNode $node): string
    {
        if ($node instanceof \DOMElement) {
            $tag = strtolower($node->nodeName);
            if ($tag === 'input') {
                return trim($node->getAttribute('value'));
            }
            if ($tag === 'select') {
                foreach ($node->getElementsByTagName('option') as $opt) {
                    if ($opt->hasAttribute('selected')) {
                        return self::clean($opt->textContent);
                    }
                }
            }
            return self::clean(self::innerText($node));
        }
        return self::clean((string) $node->nodeValue);
    }

    public static function innerText(\DOMNode $node): string
    {
        $text = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $text .= $child->nodeValue;
            } elseif ($child instanceof \DOMElement) {
                $tag = strtolower($child->nodeName);
                if ($tag === 'script' || $tag === 'style') {
                    continue;
                }
                $isBlock = in_array($tag, self::BLOCK_TAGS, true);
                $text .= ($isBlock ? "\n" : '') . self::innerText($child) . ($isBlock ? "\n" : '');
            }
        }
        return $text;
    }

    /** Trim each line, drop blank lines, collapse inner whitespace. */
    private static function clean(string $text): string
    {
        $lines = preg_split('/\R/u', str_replace("\xC2\xA0", ' ', $text)) ?: [];
        $lines = array_filter(array_map(static fn ($l) => trim((string) preg_replace('/[ \t]+/', ' ', $l)), $lines), 'strlen');
        return implode("\n", $lines);
    }

    public static function toXPath(string $selector, bool $relative = false): string
    {
        $selector = trim($selector);
        if ($selector === '') {
            throw new MapException('Empty selector');
        }
        if (str_starts_with($selector, 'xpath:')) {
            return trim(substr($selector, 6));
        }
        if (self::isXPath($selector)) {
            return $selector;
        }
        $prefix = $relative ? './/' : '//';
        return implode(' | ', array_map(
            static fn (string $part): string => self::compile(trim($part), $prefix),
            self::splitGroups($selector)
        ));
    }

    /** @return string[] */
    private static function splitGroups(string $selector): array
    {
        $parts = [];
        $depth = 0;
        $quote = null;
        $current = '';
        for ($i = 0, $n = strlen($selector); $i < $n; $i++) {
            $c = $selector[$i];
            if ($quote !== null) {
                if ($c === $quote) {
                    $quote = null;
                }
            } elseif ($c === '"' || $c === "'") {
                $quote = $c;
            } elseif ($c === '[' || $c === '(') {
                $depth++;
            } elseif ($c === ']' || $c === ')') {
                $depth--;
            } elseif ($c === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';
                continue;
            }
            $current .= $c;
        }
        $parts[] = $current;
        return array_values(array_filter($parts, static fn ($p) => trim($p) !== ''));
    }

    private static function compile(string $sel, string $prefix): string
    {
        $xpath = '';
        $i = 0;
        $len = strlen($sel);
        $first = true;

        while ($i < $len) {
            $sawSpace = false;
            while ($i < $len && ctype_space($sel[$i])) {
                $i++;
                $sawSpace = true;
            }
            if ($i >= $len) {
                break;
            }
            $combinator = 'descendant';
            if (in_array($sel[$i], ['>', '+', '~'], true)) {
                $combinator = ['>' => 'child', '+' => 'adjacent', '~' => 'sibling'][$sel[$i]];
                $i++;
                while ($i < $len && ctype_space($sel[$i])) {
                    $i++;
                }
            } elseif (!$first && !$sawSpace) {
                throw new MapException("Unsupported selector syntax near: " . substr($sel, $i));
            }

            [$tag, $conds, $i] = self::parseCompound($sel, $i);
            $preds = implode('', array_map(static fn ($c) => "[$c]", $conds));

            if ($first) {
                $xpath = ($combinator === 'child' ? rtrim($prefix, '/') . '/' : $prefix) . $tag . $preds;
                $first = false;
                continue;
            }
            $xpath .= match ($combinator) {
                'child' => '/' . $tag . $preds,
                'adjacent' => '/following-sibling::*[1]' . ($tag !== '*' ? "[self::$tag]" : '') . $preds,
                'sibling' => '/following-sibling::' . $tag . $preds,
                default => '//' . $tag . $preds,
            };
        }

        if ($xpath === '') {
            throw new MapException("Could not parse selector: $sel");
        }
        return $xpath;
    }

    /** @return array{0: string, 1: string[], 2: int} */
    private static function parseCompound(string $sel, int $i): array
    {
        $start = $i;
        $tag = '*';
        $conds = [];

        if (preg_match('/\G([a-zA-Z][a-zA-Z0-9-]*|\*)/', $sel, $m, 0, $i)) {
            $tag = strtolower($m[1]);
            $i += strlen($m[0]);
        }

        while ($i < strlen($sel)) {
            $c = $sel[$i];
            if ($c === '#' && preg_match('/\G#([\w-]+)/', $sel, $m, 0, $i)) {
                $conds[] = '@id=' . self::literal($m[1]);
            } elseif ($c === '.' && preg_match('/\G\.([\w-]+)/', $sel, $m, 0, $i)) {
                $conds[] = 'contains(concat(" ", normalize-space(@class), " "), ' . self::literal(' ' . $m[1] . ' ') . ')';
            } elseif (
                $c === '['
                && preg_match('/\G\[\s*([\w:-]+)\s*(?:([~^$*|]?=)\s*(?:"([^"]*)"|\'([^\']*)\'|([^\]\s]*)))?\s*\]/', $sel, $m, 0, $i)
            ) {
                $attr = '@' . $m[1];
                $op = $m[2] ?? '';
                $value = ($m[3] ?? '') . ($m[4] ?? '') . ($m[5] ?? '');
                $lit = self::literal($value);
                $conds[] = match ($op) {
                    '' => $attr,
                    '=' => "$attr=$lit",
                    '^=' => "starts-with($attr, $lit)",
                    '*=' => "contains($attr, $lit)",
                    '$=' => "substring($attr, string-length($attr) - string-length($lit) + 1) = $lit",
                    '~=' => 'contains(concat(" ", normalize-space(' . $attr . '), " "), ' . self::literal(" $value ") . ')',
                    '|=' => "($attr=$lit or starts-with($attr, " . self::literal($value . '-') . '))',
                    default => throw new MapException("Unsupported attribute operator $op"),
                };
            } elseif ($c === ':' && preg_match('/\G:([\w-]+)(?:\(\s*(?:"([^"]*)"|\'([^\']*)\'|([^)]*))\s*\))?/', $sel, $m, 0, $i)) {
                $arg = ($m[2] ?? '') . ($m[3] ?? '') . trim($m[4] ?? '');
                $conds[] = match (strtolower($m[1])) {
                    'contains' => 'contains(normalize-space(.), ' . self::literal($arg) . ')',
                    'first-child' => 'not(preceding-sibling::*)',
                    'last-child' => 'not(following-sibling::*)',
                    'nth-child' => 'count(preceding-sibling::*) = ' . (max(1, (int) $arg) - 1),
                    default => throw new MapException("Unsupported pseudo-class :{$m[1]}"),
                };
            } else {
                break;
            }
            $i += strlen($m[0]);
        }

        if ($i === $start) {
            throw new MapException('Unsupported selector syntax near: ' . substr($sel, $i));
        }
        return [$tag, $conds, $i];
    }

    public static function literal(string $s): string
    {
        if (!str_contains($s, '"')) {
            return '"' . $s . '"';
        }
        if (!str_contains($s, "'")) {
            return "'" . $s . "'";
        }
        return 'concat("' . str_replace('"', '", \'"\', "', $s) . '")';
    }
}
