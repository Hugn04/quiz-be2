<?php

namespace App\QuestionTypes;

/** Trắc nghiệm một đáp án (mặc định). Dữ liệu cũ có thể có nhiều đáp án đúng → chấp nhận ≥ 1. */
class SingleChoice extends QuestionType
{
    public function key(): string
    {
        return 'single_choice';
    }

    public function label(): string
    {
        return 'Một đáp án';
    }

    public function validate(array $question): ?string
    {
        if (count($this->answers($question)) < 2) {
            return 'cần ít nhất 2 đáp án';
        }
        if ($this->hasEmptyOption($question)) {
            return 'có đáp án để trống';
        }
        if ($this->correctCount($question) < 1) {
            return 'chưa chọn đáp án đúng';
        }

        return null;
    }
}
