<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Thêm cột `slug` (duy nhất) cho examples, users, sectors và sinh slug cho dữ liệu cũ.
 * URL/API dùng slug thay cho id; khóa ngoại trong DB vẫn là id.
 */
return new class extends Migration
{
    /** bảng => hàm lấy chuỗi nguồn để sinh slug */
    private function sources(): array
    {
        return [
            'examples' => fn ($row) => $row->name,
            'sectors' => fn ($row) => $row->name,
            // Phần trước @ của email (vd hung2k4hytq@gmail.com → hung2k4hytq)
            'users' => fn ($row) => Str::before((string) $row->email, '@') ?: (string) $row->name,
        ];
    }

    public function up(): void
    {
        foreach ($this->sources() as $table => $source) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('slug')->nullable()->after('id');
            });

            $used = ['me', 'this'];
            DB::table($table)->orderBy('id')->get()->each(function ($row) use ($table, $source, &$used) {
                $base = Str::slug($source($row)) ?: 'item';
                $slug = $base;
                $i = 2;
                while (in_array($slug, $used, true)) {
                    $slug = $base . '-' . $i++;
                }
                $used[] = $slug;
                DB::table($table)->where('id', $row->id)->update(['slug' => $slug]);
            });

            Schema::table($table, function (Blueprint $t) {
                $t->string('slug')->nullable(false)->change();
                $t->unique('slug');
            });
        }
    }

    public function down(): void
    {
        foreach (array_keys($this->sources()) as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropUnique($table . '_slug_unique');
                $t->dropColumn('slug');
            });
        }
    }
};
