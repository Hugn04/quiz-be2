<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kiểm duyệt người dùng (tách khỏi role):
 *  - banned_at / ban_reason: tài khoản bị khóa (không đăng nhập / dùng API được).
 *  - can_create_exams: false = không được tạo đề thi mới.
 * Role cũ `ban` → role person + banned_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('banned_at')->nullable()->after('role');
            $table->string('ban_reason', 255)->nullable()->after('banned_at');
            $table->boolean('can_create_exams')->default(true)->after('ban_reason');
        });

        DB::table('users')->where('role', 'ban')->update(['role' => 'person', 'banned_at' => now()]);
    }

    public function down(): void
    {
        DB::table('users')->whereNotNull('banned_at')->update(['role' => 'ban']);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['banned_at', 'ban_reason', 'can_create_exams']);
        });
    }
};
