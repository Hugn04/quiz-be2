<?php

namespace App\Services;

use Normalizer;

/**
 * Phát hiện từ tục (danh sách ở config/profanity.php) trong chữ người dùng nhập.
 * Khớp theo từ, không phân biệt hoa thường, chấp nhận ký tự chen giữa các chữ ("l.ồ.n").
 */
class ProfanityFilter
{
    /** Ký tự có thể chen giữa các chữ để lách: khoảng trắng, . _ - * + ~ | / \ ' " ` */
    private const GAP = '[\s._\-*+~|\/\\\\\'"`]*';

    private ?string $pattern = null;

    /** Từ tục đầu tiên tìm thấy (đã che bớt, vd "l*n"), hoặc null nếu sạch. */
    public function find(?string $text): ?string
    {
        if ($text === null || trim($text) === '' || !($pattern = $this->pattern())) {
            return null;
        }
        if (!preg_match($pattern, $this->normalize($text), $m)) {
            return null;
        }

        return $this->mask(preg_replace('/' . self::GAP . '/u', '', $m[0]));
    }

    public function contains(?string $text): bool
    {
        return $this->find($text) !== null;
    }

    /** Bỏ thẻ HTML / entity, chuẩn hoá Unicode (NFC) + chữ thường. */
    private function normalize(string $text): string
    {
        $text = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], ' ', $text)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (class_exists(Normalizer::class)) {
            $text = Normalizer::normalize($text, Normalizer::FORM_C) ?: $text;
        }

        return mb_strtolower($text, 'UTF-8');
    }

    /** Một regex cho cả danh sách: mỗi từ = các chữ nối bằng GAP, hai đầu là ranh giới từ. */
    private function pattern(): ?string
    {
        if ($this->pattern !== null) {
            return $this->pattern ?: null;
        }
        $words = array_unique(array_filter(array_map(
            fn ($w) => mb_strtolower(trim((string) $w), 'UTF-8'),
            (array) config('profanity.words', []),
        )));
        // Cụm dài trước để khớp trọn ("vãi lồn" trước "lồn").
        usort($words, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        $alternatives = array_map(function (string $word) {
            $chars = preg_split('//u', preg_replace('/\s+/u', '', $word), -1, PREG_SPLIT_NO_EMPTY);

            return implode(self::GAP, array_map(fn ($c) => preg_quote($c, '/'), $chars));
        }, $words);

        $this->pattern = $alternatives
            ? '/(?<![\p{L}\p{N}])(?:' . implode('|', $alternatives) . ')(?![\p{L}\p{N}])/u'
            : '';

        return $this->pattern ?: null;
    }

    /** "lồn" → "l*n", "fuck" → "f**k" (để báo lỗi mà không in nguyên từ tục). */
    private function mask(string $word): string
    {
        $len = mb_strlen($word);
        if ($len <= 2) {
            return mb_substr($word, 0, 1) . '*';
        }

        return mb_substr($word, 0, 1) . str_repeat('*', $len - 2) . mb_substr($word, -1);
    }
}
