<?php

namespace App\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * "Vé" bắt đầu làm bài: cấp khi người dùng mở đề để làm (GET /examples/{slug}), gửi kèm khi
 * nộp. Mã hoá bằng APP_KEY → client không sửa được thời điểm bắt đầu. Server dựa vào đó tự
 * đo thời gian làm bài (không tin `duration_seconds` client gửi) và chặn lượt nộp "ảo".
 */
class AttemptToken
{
    /** Vé dùng được trong bao lâu kể từ lúc mở đề. */
    public const TTL_SECONDS = 2 * 24 * 3600;

    /** Thời gian tối thiểu cho mỗi câu đã trả lời (giây) — nhanh hơn là không phải làm thật. */
    public const MIN_SECONDS_PER_ANSWER = 1;

    public static function issue(int $userId, int $exampleId): string
    {
        return Crypt::encryptString(json_encode(['u' => $userId, 'e' => $exampleId, 't' => time()]));
    }

    /** Thời điểm mở đề (unix) nếu vé hợp lệ cho đúng người + đúng đề, ngược lại null. */
    public static function startedAt(?string $token, int $userId, int $exampleId): ?int
    {
        if (!$token) {
            return null;
        }
        try {
            $data = json_decode(Crypt::decryptString($token), true);
        } catch (DecryptException) {
            return null;
        }
        if (!is_array($data) || ($data['u'] ?? null) !== $userId || ($data['e'] ?? null) !== $exampleId) {
            return null;
        }
        $t = (int) ($data['t'] ?? 0);

        return $t > 0 && $t <= time() && time() - $t <= self::TTL_SECONDS ? $t : null;
    }
}
