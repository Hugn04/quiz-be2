<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lượt thi ghi rõ cách kết thúc + khóa chống ghi trùng:
 *  - end_reason: finished (làm hết) / submitted (bấm nộp) / left (bỏ dở: rời trang, tải lại,
 *    làm lại khi chưa xong). Dữ liệu cũ để null.
 *  - client_key: uuid frontend sinh cho mỗi lượt → gửi lại (beacon, lỗi mạng) không tạo dòng mới.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attempts', function (Blueprint $table) {
            $table->string('end_reason', 12)->nullable()->after('duration_seconds');
            $table->uuid('client_key')->nullable()->after('end_reason');
            $table->unique(['user_id', 'client_key']);
        });
    }

    public function down(): void
    {
        Schema::table('attempts', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'client_key']);
            $table->dropColumn(['end_reason', 'client_key']);
        });
    }
};
