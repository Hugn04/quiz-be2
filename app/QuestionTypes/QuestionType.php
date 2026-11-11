<?php

namespace App\QuestionTypes;

/**
 * Một dạng câu hỏi. Mỗi dạng tự định nghĩa:
 * - validate(): kiểm tra cấu trúc câu (sau rule chung của ExampleRequest).
 * - normalize(): chuẩn hóa dữ liệu trước khi lưu (vd fill_blank: mọi đáp án đều đúng).
 *
 * Câu hỏi dạng mảng như API: [type, name, explanation, points, settings,
 *   answers => [[option, is_correct, meta]]]
 *
 * Thêm dạng mới: tạo class kế thừa, rồi đăng ký trong QuestionTypes::all().
 */
abstract class QuestionType
{
    /** Giá trị cột `questions.type`. */
    abstract public function key(): string;

    /** Tên hiển thị (tiếng Việt). */
    abstract public function label(): string;

    /** Lỗi (tiếng Việt) nếu câu không hợp lệ, null nếu hợp lệ. */
    abstract public function validate(array $question): ?string;

    public function normalize(array $question): array
    {
        return $question;
    }

    protected function answers(array $question): array
    {
        return array_values($question['answers'] ?? []);
    }

    protected function correctCount(array $question): int
    {
        return count(array_filter($this->answers($question), fn ($a) => !empty($a['is_correct'])));
    }

    protected function hasEmptyOption(array $question): bool
    {
        foreach ($this->answers($question) as $answer) {
            if (trim((string) ($answer['option'] ?? '')) === '') {
                return true;
            }
        }

        return false;
    }
}
