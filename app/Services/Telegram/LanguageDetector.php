<?php

namespace App\Services\Telegram;

use App\Enums\Language;

/**
 * Picks the reply language for a message (PRD §19: the bot answers in the language the user writes).
 *
 * Deterministic and dependency-free: count common Indonesian vs English function words. A language
 * wins only with at least 2 hits and a lead of at least 1; otherwise the user's default applies.
 * Because it looks only at the stored text, the acknowledgement and the later confirmation always
 * agree without storing anything extra.
 */
final class LanguageDetector
{
    private const INDONESIAN = [
        'yang', 'dan', 'di', 'ke', 'dari', 'untuk', 'dengan', 'sudah', 'belum', 'sedang', 'saya', 'aku', 'kami',
        'hari', 'ini', 'itu', 'tidak', 'masih', 'lagi', 'tadi', 'kemarin', 'besok', 'selesai', 'kerja', 'mengerjakan',
        'perbaiki', 'ganti', 'sudah', 'jadi', 'karena', 'atau', 'tapi', 'sama', 'nya', 'bisa', 'akan', 'udah', 'gak',
        'nggak', 'minggu', 'pagi', 'siang', 'sore', 'malam', 'tinggal', 'menunggu', 'sekarang', 'lalu', 'kemudian',
    ];

    private const ENGLISH = [
        'the', 'and', 'is', 'to', 'of', 'for', 'with', 'was', 'were', 'done', 'fixed', 'today', 'yesterday', 'tomorrow',
        'i', 'we', 'have', 'has', 'had', 'this', 'that', 'not', 'still', 'again', 'finished', 'worked', 'working',
        'on', 'in', 'at', 'from', 'but', 'because', 'or', 'will', 'can', 'waiting', 'now', 'then', 'after', 'before',
        'morning', 'afternoon', 'evening', 'week', 'it', 'my', 'our',
    ];

    public function detect(string $text, Language $default): Language
    {
        $words = preg_split('/[^\p{L}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $id = count(array_filter($words, static fn (string $w): bool => in_array($w, self::INDONESIAN, true)));
        $en = count(array_filter($words, static fn (string $w): bool => in_array($w, self::ENGLISH, true)));

        if ($id >= 2 && $id > $en) {
            return Language::Indonesian;
        }

        if ($en >= 2 && $en > $id) {
            return Language::English;
        }

        return $default;
    }
}
