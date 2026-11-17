<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Duyệt đề: khi bật "Duyệt đề trước khi đăng" (cài đặt site), đề người dùng thường tạo / sửa
 * ở trạng thái `pending` cho tới khi administrator / manager duyệt. Đề cũ = `approved`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('examples', function (Blueprint $table) {
            $table->string('status', 16)->default('approved')->after('pinned_at')->index();
            $table->foreignId('reviewed_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->string('review_note', 255)->nullable()->after('reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('examples', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['status', 'reviewed_at', 'review_note']);
        });
    }
};
