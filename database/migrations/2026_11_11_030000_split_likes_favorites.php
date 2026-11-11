<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tách `user_actions` (1 dòng gộp cờ like + favorited) thành 2 bảng quan hệ:
 *   likes(user_id, example_id, created_at)      — có dòng = đang thích
 *   favorites(user_id, example_id, created_at)  — có dòng = đang yêu thích
 *
 * - Khóa chính (user_id, example_id) → DB chặn trùng (bản cũ có thể sinh 2 dòng khi bấm nhanh).
 * - Bỏ thích / bỏ yêu thích = xóa dòng → không còn dòng rác toàn `false`.
 * - `created_at` → sắp xếp "mới yêu thích", thống kê lượt thích theo thời gian.
 * - Tính lại `examples.count_like` từ dữ liệu thật.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['likes', 'favorites'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('example_id')->constrained('examples')->cascadeOnDelete();
                $table->timestamp('created_at')->useCurrent();
                $table->primary(['user_id', 'example_id']);
                $table->index(['example_id', 'created_at']);
            });
        }

        $now = now();
        foreach (DB::table('user_actions')->get() as $row) {
            $pair = ['user_id' => $row->user_id, 'example_id' => $row->example_id, 'created_at' => $now];
            if ($row->like) {
                DB::table('likes')->insertOrIgnore($pair);
            }
            if ($row->favorited) {
                DB::table('favorites')->insertOrIgnore($pair);
            }
        }

        DB::statement('UPDATE examples SET count_like = (SELECT COUNT(*) FROM likes WHERE likes.example_id = examples.id)');

        Schema::drop('user_actions');
    }

    public function down(): void
    {
        Schema::create('user_actions', function (Blueprint $table) {
            $table->id();
            $table->boolean('like')->default(false);
            $table->boolean('favorited')->default(false);
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('example_id')->constrained('examples')->onDelete('cascade');
        });

        $rows = [];
        foreach (DB::table('likes')->get() as $like) {
            $rows["$like->user_id-$like->example_id"] = [
                'user_id' => $like->user_id, 'example_id' => $like->example_id, 'like' => true, 'favorited' => false,
            ];
        }
        foreach (DB::table('favorites')->get() as $fav) {
            $key = "$fav->user_id-$fav->example_id";
            $rows[$key] = ($rows[$key] ?? [
                'user_id' => $fav->user_id, 'example_id' => $fav->example_id, 'like' => false,
            ]) + ['favorited' => true];
            $rows[$key]['favorited'] = true;
        }
        if ($rows) {
            DB::table('user_actions')->insert(array_values($rows));
        }

        Schema::drop('favorites');
        Schema::drop('likes');
    }
};
