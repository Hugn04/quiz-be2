<?php

namespace App\QuestionTypes;

/** Sắp xếp: `answers` lưu theo THỨ TỰ ĐÚNG; client xáo trộn khi làm bài. */
class Ordering extends QuestionType
{
    public function key(): string
    {
        return 'ordering';
    }

    public function label(): string
    {
        return 'Sắp xếp';
    }

    public function validate(array $question): ?string
    {
        if (count($this->answers($question)) < 2) {
            return 'cần ít nhất 2 mục để sắp xếp';
        }
        if ($this->hasEmptyOption($question)) {
            return 'có mục để trống';
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
