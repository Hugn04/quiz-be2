<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `token_version` được nhúng vào JWT (claim `ver`). Tăng giá trị này → mọi JWT cũ của user
 * (kể cả trên thiết bị khác) hết hiệu lực — dùng khi đổi / đặt lại mật khẩu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('token_version')->default(0)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('token_version');
        });
    }
};
