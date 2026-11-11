<?php

namespace App\QuestionTypes;

/** Danh sách dạng câu hỏi được hỗ trợ (đăng ký dạng mới ở đây). */
class QuestionTypes
{
    public const DEFAULT = 'single_choice';

    /** @return array<string, QuestionType> */
    public static function all(): array
    {
        static $types = null;

        return $types ??= collect([
            new SingleChoice(),
            new MultipleChoice(),
            new TrueFalse(),
            new FillBlank(),
            new Ordering(),
            new Matching(),
        ])->keyBy(fn (QuestionType $type) => $type->key())->all();
    }

    /** @return string[] */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function get(?string $key): QuestionType
    {
        return self::all()[$key ?? self::DEFAULT] ?? self::all()[self::DEFAULT];
    }
}
