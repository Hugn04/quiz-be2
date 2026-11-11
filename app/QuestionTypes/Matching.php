<?php

namespace App\QuestionTypes;

/** Ghép cặp: `option` = vế trái, `meta.match` = vế phải đúng của nó. */
class Matching extends QuestionType
{
    public function key(): string
    {
        return 'matching';
    }

    public function label(): string
    {
        return 'Ghép cặp';
    }

    public function validate(array $question): ?string
    {
        $answers = $this->answers($question);
        if (count($answers) < 2) {
            return 'cần ít nhất 2 cặp để ghép';
        }
        if ($this->hasEmptyOption($question)) {
            return 'có vế trái để trống';
        }
        foreach ($answers as $answer) {
            if (trim((string) ($answer['meta']['match'] ?? '')) === '') {
                return 'có cặp chưa nhập vế phải';
            }
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
