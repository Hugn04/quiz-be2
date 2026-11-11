<?php

namespace App\QuestionTypes;

/**
 * Điền đáp án (trả lời ngắn). Mỗi `answers[].option` là một đáp án được chấp nhận.
 * settings.case_sensitive: phân biệt hoa thường khi so khớp (mặc định không).
 */
class FillBlank extends QuestionType
{
    public function key(): string
    {
        return 'fill_blank';
    }

    public function label(): string
    {
        return 'Điền đáp án';
    }

    public function validate(array $question): ?string
    {
        if (count($this->answers($question)) < 1) {
            return 'cần ít nhất 1 đáp án được chấp nhận';
        }
        if ($this->hasEmptyOption($question)) {
            return 'có đáp án để trống';
        }

        return null;
    }

    public function normalize(array $question): array
    {
        $question['answers'] = array_map(
            fn ($answer) => [...$answer, 'is_correct' => true],
            $this->answers($question),
        );

        return $question;
    }
}
