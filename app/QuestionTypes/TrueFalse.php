<?php

namespace App\QuestionTypes;

/** Đúng / Sai: đúng 2 lựa chọn, 1 đáp án đúng. */
class TrueFalse extends QuestionType
{
    public function key(): string
    {
        return 'true_false';
    }

    public function label(): string
    {
        return 'Đúng / Sai';
    }

    public function validate(array $question): ?string
    {
        if (count($this->answers($question)) !== 2) {
            return 'câu Đúng / Sai phải có đúng 2 lựa chọn';
        }
        if ($this->hasEmptyOption($question)) {
            return 'có lựa chọn để trống';
        }
        if ($this->correctCount($question) !== 1) {
            return 'câu Đúng / Sai phải có đúng 1 đáp án đúng';
        }

        return null;
    }
}
