<?php

declare(strict_types=1);

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

class HtmlAman
{
    private const TAG_DIIZINKAN = [
        'p', 'br', 'strong', 'b', 'em', 'i', 's', 'del', 'code', 'pre', 'blockquote',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'hr',
    ];

    private const TAG_DIBUANG = [
        'script', 'style', 'iframe', 'object', 'embed', 'link', 'meta', 'svg', 'math', 'form',
        'input', 'textarea', 'button', 'select', 'noscript', 'template', 'head', 'title', 'base',
    ];

    private const POLA_TAG = '/<\/?[a-z][^>]*>/i';

    public static function bersihkan(?string $konten): ?string
    {
        $konten = trim((string) $konten);
        if ($konten === '') {
            return null;
        }

        if (!preg_match(self::POLA_TAG, $konten)) {
            return $konten;
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $sebelumnya = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><body>' . $konten . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($sebelumnya);

        $body = $dom->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return null;
        }

        self::saring($body);

        $hasil = '';
        foreach ($body->childNodes as $anak) {
            $hasil .= $dom->saveHTML($anak);
        }
        $hasil = trim($hasil);

        return self::kosong($hasil) ? null : $hasil;
    }

    public static function untukTampilan(?string $konten): string
    {
        $konten = trim((string) $konten);
        if ($konten === '') {
            return '';
        }

        if (!preg_match(self::POLA_TAG, $konten)) {
            return nl2br(htmlspecialchars($konten, ENT_QUOTES, 'UTF-8'));
        }

        return self::bersihkan($konten) ?? '';
    }

    private static function saring(DOMNode $induk): void
    {
        foreach (iterator_to_array($induk->childNodes) as $anak) {
            if ($anak instanceof DOMElement) {
                $tag = strtolower($anak->tagName);

                if (in_array($tag, self::TAG_DIBUANG, true)) {
                    $induk->removeChild($anak);
                    continue;
                }

                self::saring($anak);

                if (in_array($tag, self::TAG_DIIZINKAN, true)) {
                    while ($anak->attributes->length > 0) {
                        $anak->removeAttributeNode($anak->attributes->item(0));
                    }
                    continue;
                }

                while ($anak->firstChild) {
                    $induk->insertBefore($anak->firstChild, $anak);
                }
                $induk->removeChild($anak);
            } elseif ($anak->nodeType !== XML_TEXT_NODE) {
                $induk->removeChild($anak);
            }
        }
    }

    private static function kosong(string $html): bool
    {
        if (stripos($html, '<hr') !== false) {
            return false;
        }

        $teks = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(str_replace("\u{00A0}", ' ', $teks)) === '';
    }
}
