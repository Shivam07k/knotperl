<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

class Sanitizer
{
    private const ALLOWED = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'ul', 'ol', 'li', 'blockquote', 'a', 'span', 'div', 'hr', 'table', 'thead',
        'tbody', 'tr', 'th', 'td', 'figure', 'figcaption', 'img', 'code', 'pre',
    ];

    private const DENIED = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'link', 'meta', 'svg', 'math'];

    private const ATTRIBUTES = [
        'a' => ['href', 'title', 'target', 'rel', 'class'],
        'img' => ['src', 'alt', 'title', 'width', 'height', 'loading', 'class'],
        '*' => ['class', 'id', 'title'],
    ];

    public function clean(?string $html): string
    {
        $html = (string) $html;
        if (trim($html) === '') {
            return '';
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="kp-sanitize-root">'.$html.'</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->getElementById('kp-sanitize-root');
        if ($root === null) {
            return '';
        }

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $this->render($child);
        }

        return $out;
    }

    private function render(DOMNode $node): string
    {
        if ($node instanceof DOMText) {
            return htmlspecialchars($node->nodeValue ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        if (! $node instanceof DOMElement) {
            return '';
        }

        $tag = strtolower($node->localName);
        if (in_array($tag, self::DENIED, true)) {
            return '';
        }

        $children = '';
        foreach ($node->childNodes as $child) {
            $children .= $this->render($child);
        }

        if (! in_array($tag, self::ALLOWED, true)) {
            // Unwrap unknown tags but keep their text content.
            return $children;
        }

        $attributes = '';
        foreach ($node->attributes as $attribute) {
            $name = strtolower($attribute->localName);
            $allowed = array_merge(self::ATTRIBUTES['*'], self::ATTRIBUTES[$tag] ?? []);
            if (! in_array($name, $allowed, true)) {
                continue;
            }
            $value = trim((string) $attribute->value);
            if (in_array($name, ['href', 'src'], true) && $this->unsafeUrl($value)) {
                continue;
            }
            $attributes .= ' '.$name.'="'.htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"';
        }

        if (in_array($tag, ['br', 'hr', 'img'], true)) {
            return '<'.$tag.$attributes.'>';
        }

        return '<'.$tag.$attributes.'>'.$children.'</'.$tag.'>';
    }

    private function unsafeUrl(string $value): bool
    {
        $value = strtolower(ltrim($value));

        return str_starts_with($value, 'javascript:')
            || str_starts_with($value, 'vbscript:')
            || str_starts_with($value, 'data:');
    }
}
