<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trường chung cho nhiều dạng câu hỏi (xem app/QuestionTypes):
 * - questions.points   : điểm của câu (mặc định 1) → điểm bài = 10 × đạt / tổng.
 * - questions.settings : cấu hình riêng từng dạng (vd fill_blank.case_sensitive).
 * - answers.meta       : dữ liệu riêng của đáp án (vd matching: { "match": "vế phải" }).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->decimal('points', 6, 2)->default(1)->after('explanation');
            $table->json('settings')->nullable()->after('points');
        });
        Schema::table('answers', function (Blueprint $table) {
            $table->json('meta')->nullable()->after('is_correct');
        });
    }

    public function down(): void
    {
        Schema::table('answers', function (Blueprint $table) {
            $table->dropColumn('meta');
        });
        Schema::table('questions', function (Blueprint $table) {
            $table->dropColumn(['points', 'settings']);
        });
    }
};
