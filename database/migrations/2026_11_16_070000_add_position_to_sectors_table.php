<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Thứ tự hiển thị ngành học (quản trị kéo lên / xuống). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sectors', function (Blueprint $table) {
            $table->unsignedInteger('position')->default(0)->index();
        });
        foreach (DB::table('sectors')->orderBy('name')->pluck('id') as $i => $id) {
            DB::table('sectors')->where('id', $id)->update(['position' => $i + 1]);
        }
    }

    public function down(): void
    {
        Schema::table('sectors', function (Blueprint $table) {
            $table->dropIndex(['position']);
            $table->dropColumn('position');
        });
    }
};
