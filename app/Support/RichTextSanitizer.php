<?php

declare(strict_types=1);

namespace QuizMyClass\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use RuntimeException;

final class RichTextSanitizer
{
    private const ALLOWED = [
        'p' => [],
        'br' => [],
        'strong' => [],
        'b' => [],
        'em' => [],
        'i' => [],
        'u' => [],
        'ul' => [],
        'ol' => [],
        'li' => [],
        'a' => ['href'],
        'img' => ['src', 'alt'],
        'span' => ['style'],
    ];

    private const DROP_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math'];

    public function clean(string $html, bool $allowEmpty = false): string
    {
        if (strlen($html) > 20000) {
            throw new RuntimeException('Rich text is too long.');
        }
        if (trim(strip_tags($html)) === '' && !str_contains(strtolower($html), '<img')) {
            if ($allowEmpty) {
                return '';
            }
            throw new RuntimeException('A question prompt is required.');
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $loaded = $document->loadHTML(
            '<?xml encoding="utf-8" ?><div id="qmc-rich-text-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            throw new RuntimeException('Rich text could not be parsed.');
        }

        $root = $document->getElementsByTagName('div')->item(0);
        if (!$root instanceof DOMElement) {
            throw new RuntimeException('Rich text could not be parsed.');
        }

        $clean = '';
        foreach ($root->childNodes as $child) {
            $clean .= $this->renderNode($child);
        }
        $clean = trim(preg_replace('/\s{2,}/u', ' ', $clean) ?? $clean);

        if (!$allowEmpty && trim(strip_tags($clean)) === '' && !str_contains(strtolower($clean), '<img')) {
            throw new RuntimeException('A question prompt is required.');
        }
        return $clean;
    }

    private function renderNode(DOMNode $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            return htmlspecialchars($node->textContent, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        if (!$node instanceof DOMElement) {
            return '';
        }

        $tag = strtolower($node->tagName);
        if (in_array($tag, self::DROP_CONTENT, true)) {
            return '';
        }

        $children = '';
        foreach ($node->childNodes as $child) {
            $children .= $this->renderNode($child);
        }
        if (!array_key_exists($tag, self::ALLOWED)) {
            return $children;
        }

        $attributes = $this->attributes($node, $tag);
        if ($tag === 'br') {
            return '<br>';
        }
        if ($tag === 'img') {
            if (!isset($attributes['src'])) {
                return '';
            }
            $alt = $attributes['alt'] ?? '';
            return '<img src="' . $this->attribute($attributes['src'])
                . '" alt="' . $this->attribute($alt) . '">';
        }

        return '<' . $tag
            . $this->renderAttributes($attributes)
            . '>' . $children . '</' . $tag . '>';
    }

    /** @return array<string, string> */
    private function attributes(DOMElement $element, string $tag): array
    {
        $allowed = self::ALLOWED[$tag];
        $attributes = [];
        foreach ($allowed as $name) {
            if (!$element->hasAttribute($name)) {
                continue;
            }
            $value = trim($element->getAttribute($name));
            if ($name === 'href' || $name === 'src') {
                $url = filter_var($value, FILTER_VALIDATE_URL);
                if ($url === false || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
                    continue;
                }
                $value = $url;
            } elseif ($name === 'style') {
                $safeStyles = [];
                foreach (preg_split('/;/', $value) ?: [] as $declaration) {
                    $parts = explode(':', trim($declaration), 2);
                    if (count($parts) !== 2) {
                        continue;
                    }
                    $property = strtolower(trim($parts[0]));
                    $propertyValue = trim($parts[1]);
                    if ($property === 'color'
                        && preg_match('/^(#[0-9a-f]{3,8}|(?:red|blue|green|orange|purple|pink|brown|black|gray|grey|white|yellow|cyan|magenta))$/i', $propertyValue) === 1
                    ) {
                        $safeStyles[] = 'color: ' . $propertyValue;
                    } elseif ($property === 'font-size'
                        && preg_match('/^([1-9]|[1-6][0-9]|7[0-2])px$/i', $propertyValue, $matches) === 1
                    ) {
                        $safeStyles[] = 'font-size: ' . $matches[1] . 'px';
                    } elseif ($property === 'font-style'
                        && in_array(strtolower($propertyValue), ['normal', 'italic', 'oblique'], true)
                    ) {
                        $safeStyles[] = 'font-style: ' . strtolower($propertyValue);
                    }
                }
                if ($safeStyles === []) {
                    continue;
                }
                $value = implode('; ', $safeStyles);
            } elseif ($name === 'alt') {
                $value = substr($value, 0, 300);
            }
            $attributes[$name] = $value;
        }
        if ($tag === 'a' && isset($attributes['href'])) {
            $attributes['rel'] = 'noopener noreferrer';
            $attributes['target'] = '_blank';
        }
        return $attributes;
    }

    /** @param array<string, string> $attributes */
    private function renderAttributes(array $attributes): string
    {
        $rendered = '';
        foreach ($attributes as $name => $value) {
            $rendered .= ' ' . $name . '="' . $this->attribute($value) . '"';
        }
        return $rendered;
    }

    private function attribute(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

