<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Định danh công khai của người dùng = UUID (link trang cá nhân /profile/@<uuid>).
 * Thay cho slug (đang sinh từ phần trước @ của email → để lộ email khi hiện công khai).
 * Cột `slug` giữ lại nhưng không trả ra API nữa (User::$hidden).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id');
        });

        foreach (DB::table('users')->whereNull('uuid')->pluck('id') as $id) {
            DB::table('users')->where('id', $id)->update(['uuid' => (string) Str::uuid()]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->uuid('uuid')->nullable(false)->change();
            $table->unique('uuid');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->dropColumn('uuid');
        });
    }
};
