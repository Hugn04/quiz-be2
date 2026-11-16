<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ẩn đề (thay vì xóa) + ghim đề lên trang chủ.
 *  - hidden_at / hidden_reason: đề vi phạm, chỉ tác giả và quản trị còn thấy.
 *  - pinned_at: đề được ghim lên đầu "Đề nổi bật".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('examples', function (Blueprint $table) {
            $table->timestamp('hidden_at')->nullable()->index();
            $table->string('hidden_reason', 255)->nullable();
            $table->timestamp('pinned_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('examples', function (Blueprint $table) {
            $table->dropIndex(['hidden_at']);
            $table->dropIndex(['pinned_at']);
            $table->dropColumn(['hidden_at', 'hidden_reason', 'pinned_at']);
        });
    }
};
