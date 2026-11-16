<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Người dùng báo lỗi một câu hỏi (sai đáp án, lỗi chính tả...). Sửa đề tạo lại toàn bộ
 * câu hỏi → `question_id` có thể mất, nên giữ bản chụp nội dung + vị trí câu lúc báo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('example_id')->constrained('examples')->cascadeOnDelete();
            $table->foreignId('question_id')->nullable()->constrained('questions')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('question_position')->nullable(); // số thứ tự câu (1-based)
            $table->text('question_content')->nullable();
            $table->string('reason', 30); // wrong_answer | typo | unclear | image | other
            $table->string('message', 1000)->nullable();
            $table->string('status', 20)->default('open'); // open | resolved | dismissed
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->string('handle_note', 500)->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_reports');
    }
};
