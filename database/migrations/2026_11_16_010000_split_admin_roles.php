<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tách role `admin` cũ:
 *  - tài khoản có email = ADMINISTRATOR_EMAIL → `administrator` (duy nhất);
 *  - các admin còn lại → `manager`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->where('role', 'admin')->update(['role' => 'manager']);

        $email = trim((string) config('auth.administrator_email'));
        if ($email !== '') {
            DB::table('users')->where('role', 'administrator')->update(['role' => 'manager']);
            DB::table('users')->whereRaw('LOWER(email) = ?', [strtolower($email)])
                ->update(['role' => 'administrator']);
        }
    }

    public function down(): void
    {
        DB::table('users')->whereIn('role', ['administrator', 'manager'])->update(['role' => 'admin']);
    }
};
