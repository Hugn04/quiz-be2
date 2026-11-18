<?php

namespace App\Http\Requests;

use App\QuestionTypes\QuestionTypes;
use App\Rules\NoProfanity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validate body tạo / sửa đề (POST /examples, PUT /examples/{slug}).
 * Sửa đề: chỉ chủ đề mới được (kiểm tra trước validate → người khác nhận 403).
 */
class ExampleRequest extends FormRequest
{
    /** Tổng số câu tối đa của 1 đề. */
    public const MAX_QUESTIONS = 5000;

    public function authorize(): bool
    {
        $example = $this->route('example');
        if (!$example || $example->user_id == $this->user()?->id) {
            return true;
        }

        // Trang quản trị (/admin/examples/*): administrator / manager sửa được mọi đề.
        return $this->is('api/admin/*') && (bool) $this->user()?->isStaff();
    }

    protected function failedAuthorization(): void
    {
        throw new \Illuminate\Http\Exceptions\HttpResponseException(
            response()->json('Bạn không có quyền sửa đề này', 403),
        );
    }

    /** Frontend cũ gửi `question` dạng chuỗi JSON → decode cho đồng nhất. */
    protected function prepareForValidation(): void
    {
        $question = $this->input('question');
        if (is_string($question)) {
            $decoded = json_decode($question, true);
            $this->merge(['question' => is_array($decoded) ? $decoded : $question]);
        }
    }

    public function rules(): array
    {
        // Chặn từ tục ở mọi ô chữ người dùng nhập (tên đề, tên phần, câu hỏi, đáp án, giải thích).
        // Administrator / manager tạo, sửa đề → không kiểm tra.
        $clean = $this->user()?->isStaff() ? [] : [new NoProfanity()];

        return [
            'name' => ['required', 'string', 'max:255', ...$clean],
            'credits' => ['required', 'integer', 'min:0', 'max:100'],
            'sector' => ['required', 'string', 'exists:sectors,slug'],
            'image' => ['sometimes', 'nullable', 'string', 'max:2048', 'url:http,https'],

            'question' => ['required', 'array', 'min:1', 'max:100'],
            'question.*.name' => ['required', 'string', 'max:255', ...$clean],
            'question.*.questions' => ['required', 'array', 'min:1', 'max:2000'],
            'question.*.questions.*.name' => ['required', 'string', 'max:20000', ...$clean],
            'question.*.questions.*.type' => ['sometimes', 'nullable', 'string', 'in:' . implode(',', QuestionTypes::keys())],
            'question.*.questions.*.explanation' => ['sometimes', 'nullable', 'string', 'max:20000', ...$clean],
            'question.*.questions.*.points' => ['sometimes', 'nullable', 'numeric', 'min:0.25', 'max:100'],
            'question.*.questions.*.settings' => ['sometimes', 'nullable', 'array'],
            'question.*.questions.*.settings.case_sensitive' => ['sometimes', 'boolean'],
            'question.*.questions.*.img' => ['sometimes', 'nullable', 'array', 'max:10'],
            'question.*.questions.*.img.*.url' => ['required', 'string', 'max:2048'],
            // Số đáp án tối thiểu khác nhau theo dạng → kiểm tra trong after().
            'question.*.questions.*.answers' => ['required', 'array', 'min:1', 'max:50'],
            'question.*.questions.*.answers.*.option' => ['required', 'string', 'max:5000', ...$clean],
            'question.*.questions.*.answers.*.is_correct' => ['sometimes', 'boolean'],
            'question.*.questions.*.answers.*.meta' => ['sometimes', 'nullable', 'array'],
            'question.*.questions.*.answers.*.meta.match' => ['sometimes', 'nullable', 'string', 'max:5000', ...$clean],
        ];
    }

    /** Kiểm tra riêng theo từng dạng câu (App\QuestionTypes). */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }
                $total = collect($this->input('question', []))->sum(fn ($p) => is_array($p['questions'] ?? null) ? count($p['questions']) : 0);
                if ($total > self::MAX_QUESTIONS) {
                    $validator->errors()->add('question', 'Đề tối đa ' . self::MAX_QUESTIONS . ' câu — hãy tách thành nhiều đề.');

                    return;
                }
                foreach ($this->input('question', []) as $pi => $part) {
                    foreach ($part['questions'] ?? [] as $qi => $question) {
                        $type = QuestionTypes::get($question['type'] ?? null);
                        if ($error = $type->validate($question)) {
                            $validator->errors()->add(
                                "question.$pi.questions.$qi",
                                sprintf('Phần %d, câu %d (%s): %s.', $pi + 1, $qi + 1, $type->label(), $error),
                            );
                        }
                    }
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'required' => ':attribute là bắt buộc.',
            'string' => ':attribute phải là chuỗi.',
            'integer' => ':attribute phải là số nguyên.',
            'boolean' => ':attribute phải là đúng hoặc sai.',
            'array' => ':attribute không đúng định dạng.',
            'exists' => ':attribute không tồn tại.',
            'in' => ':attribute không hợp lệ.',
            'min' => [
                'array' => ':attribute phải có ít nhất :min mục.',
                'numeric' => ':attribute tối thiểu là :min.',
                'string' => ':attribute tối thiểu :min ký tự.',
            ],
            'max' => [
                'array' => ':attribute tối đa :max mục.',
                'numeric' => ':attribute tối đa là :max.',
                'string' => ':attribute tối đa :max ký tự.',
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'tên đề',
            'credits' => 'số tín chỉ',
            'sector' => 'ngành',
            'question' => 'nội dung đề',
            'question.*.name' => 'tên phần',
            'question.*.questions' => 'danh sách câu hỏi',
            'question.*.questions.*.name' => 'nội dung câu hỏi',
            'question.*.questions.*.type' => 'dạng câu hỏi',
            'question.*.questions.*.points' => 'điểm của câu',
            'question.*.questions.*.explanation' => 'giải thích',
            'question.*.questions.*.settings.case_sensitive' => 'tùy chọn phân biệt hoa thường',
            'question.*.questions.*.answers.*.meta.match' => 'vế phải',
            'question.*.questions.*.answers' => 'đáp án',
            'question.*.questions.*.answers.*.option' => 'nội dung đáp án',
            'question.*.questions.*.answers.*.is_correct' => 'đáp án đúng',
            'question.*.questions.*.img.*.url' => 'đường dẫn ảnh',
        ];
    }
}
