<?php

declare(strict_types=1);

namespace Modules\Imaging\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Keeps only simple formatting in report text: paragraphs, line breaks,
 * bold, italic, underline, small headings, lists and plain tables. Every
 * attribute, script, style, link and image is removed.
 */
final class Html
{
    private const ALLOWED = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'table', 'thead', 'tbody', 'tr', 'th', 'td'];

    /** Tags removed together with everything inside them. */
    private const DROP = ['script', 'style', 'iframe', 'object', 'embed', 'img', 'svg', 'math', 'form', 'input', 'button', 'textarea', 'select', 'head', 'title', 'meta', 'link'];

    public static function clean(?string $html): string
    {
        $html = trim((string) $html);

        if ($html === '') {
            return '';
        }

        // Plain text (no tags): keep the line breaks as paragraphs.
        if ($html === strip_tags($html)) {
            return implode('', array_map(
                fn (string $line): string => '<p>'.e($line).'</p>',
                array_values(array_filter(array_map('trim', preg_split('/\R/', $html) ?: []), fn ($l) => $l !== '')),
            ));
        }

        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->getElementById('root');

        if ($root === null) {
            return '';
        }

        self::walk($root);

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }

        return trim($out);
    }

    /** True when the text has no words in it (an empty editor gives "<p></p>"). */
    public static function isBlank(?string $html): bool
    {
        return trim(html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8'), " \t\n\r\0\x0B\xC2\xA0") === '';
    }

    private static function walk(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);

                if (in_array($tag, self::DROP, true)) {
                    $node->removeChild($child);

                    continue;
                }

                self::walk($child);

                if (! in_array($tag, self::ALLOWED, true)) {
                    // Keep the text of an unknown tag (span, div, a, ...) but not the tag.
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);

                    continue;
                }

                foreach (iterator_to_array($child->attributes) as $attribute) {
                    $child->removeAttribute($attribute->nodeName);
                }
            } elseif ($child->nodeType === XML_COMMENT_NODE || $child->nodeType === XML_PI_NODE) {
                $node->removeChild($child);
            }
        }
    }
}
