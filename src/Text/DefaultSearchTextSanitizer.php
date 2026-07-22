<?php

namespace Zarbinco\PersianSearch\Text;

use Zarbinco\PersianSearch\Contracts\SearchTextSanitizer;
use Zarbinco\PersianSearch\Exceptions\InvalidSearchTextException;

final class DefaultSearchTextSanitizer implements SearchTextSanitizer
{
    public function sanitize(string $value, string $locale): string
    {
        if (preg_match('//u', $value) !== 1) {
            throw InvalidSearchTextException::invalidUtf8();
        }

        $text = html_entity_decode($value, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
        $text = $this->removeNonContentBlocks($text);
        $text = $this->preserveBlockBoundaries($text);
        $text = strip_tags($text);
        $text = str_replace(["\u{200B}", "\u{200C}", "\u{200D}"], ' ', $text);
        $text = $this->convertWhitespace($text);
        $text = (string) preg_replace(
            '/[\x{0000}-\x{0008}\x{000E}-\x{001F}\x{007F}-\x{0084}\x{0086}-\x{009F}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u',
            '',
            $text,
        );

        return trim((string) preg_replace('/ +/u', ' ', $text));
    }

    private function removeNonContentBlocks(string $value): string
    {
        return (string) preg_replace(
            '~<\s*(script|style|noscript|template)\b[^>]*>.*?(?:<\s*/\s*\1\s*>|$)~isu',
            ' ',
            $value,
        );
    }

    private function preserveBlockBoundaries(string $value): string
    {
        return (string) preg_replace(
            '~<\s*/?\s*(?:br|p|div|li|h[1-6]|td|th|tr|table|section|article|header|footer|blockquote|pre)\b[^>]*>~iu',
            ' ',
            $value,
        );
    }

    private function convertWhitespace(string $value): string
    {
        return (string) preg_replace('/[\p{Z}\x{0009}-\x{000D}\x{0085}]+/u', ' ', $value);
    }
}
