<?php

namespace App\Rules;

use App\Services\ProfanityFilter;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Chặn nội dung có từ tục (danh sách ở config/profanity.php). */
class NoProfanity implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value)) {
            return;
        }
        if ($word = app(ProfanityFilter::class)->find($value)) {
            $fail(self::where($attribute) . ":Attribute chứa từ ngữ không phù hợp (\"$word\"). Vui lòng sửa lại.");
        }
    }

    /** "question.0.questions.3.answers.1.option" → "Phần 1, câu 4: " (để biết sửa ở đâu). */
    private static function where(string $attribute): string
    {
        if (preg_match('/^question\.(\d+)(?:\.questions\.(\d+))?/', $attribute, $m)) {
            return 'Phần ' . ($m[1] + 1) . (isset($m[2]) ? ', câu ' . ($m[2] + 1) : '') . ': ';
        }

        return '';
    }
}
