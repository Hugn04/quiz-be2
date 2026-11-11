<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Thay `scores` (1 dòng / user / đề, chỉ giữ điểm cao nhất) bằng `attempts`:
 * mỗi lần nộp bài là 1 dòng → có lịch sử, thời gian làm, số câu đúng / sai, câu trả lời.
 * Bảng xếp hạng = điểm cao nhất của mỗi người, tính từ attempts.
 *
 * Dữ liệu cũ: mỗi dòng `scores` thành 1 lượt thi (chỉ có điểm, các cột chi tiết để trống).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('example_id')->constrained('examples')->cascadeOnDelete();
            $table->decimal('score', 4, 2); // thang 10
            $table->decimal('earned_points', 8, 2)->nullable();
            $table->decimal('total_points', 8, 2)->nullable();
            $table->unsignedSmallInteger('correct_count')->nullable();
            $table->unsignedSmallInteger('wrong_count')->nullable();
            $table->unsignedSmallInteger('unanswered_count')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            // [{ question_id, response, correct }] — để xem lại bài / thống kê theo câu.
            $table->json('answers')->nullable();
            $table->timestamps();

            $table->index(['example_id', 'score']);
            $table->index(['user_id', 'example_id', 'created_at']);
        });

        foreach (DB::table('scores')->orderBy('id')->get() as $row) {
            DB::table('attempts')->insert([
                'user_id' => $row->user_id,
                'example_id' => $row->example_id,
                'score' => max(0, min(10, (float) $row->score)),
                'created_at' => $row->updated_at ?? $row->created_at,
                'updated_at' => $row->updated_at ?? $row->created_at,
            ]);
        }

        Schema::drop('scores');
    }

    /** Rollback: giữ điểm cao nhất của mỗi người như bảng `scores` cũ. */
    public function down(): void
    {
        Schema::create('scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('example_id')->constrained('examples')->onDelete('cascade');
            $table->float('score');
            $table->timestamps();
        });

        $best = DB::table('attempts')
            ->select('user_id', 'example_id', DB::raw('MAX(score) as score'), DB::raw('MIN(created_at) as created_at'), DB::raw('MAX(updated_at) as updated_at'))
            ->groupBy('user_id', 'example_id')
            ->get();
        foreach ($best as $row) {
            DB::table('scores')->insert((array) $row);
        }

        Schema::drop('attempts');
    }
};
