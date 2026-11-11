<?php

namespace App\QuestionTypes;

/** Trắc nghiệm nhiều đáp án: đúng khi chọn đủ và không thừa. */
class MultipleChoice extends SingleChoice
{
    public function key(): string
    {
        return 'multiple_choice';
    }

    public function label(): string
    {
        return 'Nhiều đáp án';
    }
}
