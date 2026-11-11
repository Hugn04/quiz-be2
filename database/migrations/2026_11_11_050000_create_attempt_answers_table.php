<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Lịch sử làm bài chi tiết: tách `attempts.answers` (json) thành bảng `attempt_answers`,
 * mỗi câu của lượt thi 1 dòng (kể cả câu chưa làm).
 *
 * Vì sao cần snapshot câu hỏi: sửa đề (ExampleContent::sync) xóa và tạo lại toàn bộ câu
 * hỏi → `question_id` cũ mất. Mỗi dòng giữ bản chụp câu hỏi lúc nộp (`question`) và câu
 * trả lời quy ra id đáp án (`response`) → xem lại bài đúng như lúc làm, kể cả khi đề đã
 * sửa / xóa. `question_id` vẫn giữ (null khi câu bị xóa) để thống kê theo câu.
 *
 * `attempts`:
 *  - `ulid`: mã công khai cho URL (không lộ id tăng dần).
 *  - `example_id` nullable + nullOnDelete, thêm `example_name`: xóa đề không mất lịch sử.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attempt_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->constrained('attempts')->cascadeOnDelete();
            $table->foreignId('question_id')->nullable()->constrained('questions')->nullOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('part_name')->nullable();
            // { type, name, explanation, points, settings, img: [{url}],
            //   answers: [{ id, option, is_correct, meta }] }
            $table->json('question');
            // single / true_false / multiple: [answer_id]; ordering: [answer_id theo thứ tự xếp];
            // matching: [[left_id, right_id|null]]; fill_blank: "chuỗi". null = chưa làm.
            $table->json('response')->nullable();
            $table->boolean('is_correct')->nullable(); // null = chưa làm
            $table->decimal('points', 8, 2)->default(1);
            $table->decimal('earned_points', 8, 2)->default(0);

            $table->unique(['attempt_id', 'position']);
            $table->index(['question_id', 'is_correct']);
        });

        Schema::table('attempts', function (Blueprint $table) {
            $table->ulid('ulid')->nullable()->after('id');
            $table->string('example_name')->nullable()->after('example_id');
        });

        foreach (DB::table('attempts')->orderBy('id')->get() as $attempt) {
            DB::table('attempts')->where('id', $attempt->id)->update([
                'ulid' => strtolower((string) Str::ulid()),
                'example_name' => DB::table('examples')->where('id', $attempt->example_id)->value('name'),
            ]);
            $this->migrateAnswers($attempt);
        }

        Schema::table('attempts', function (Blueprint $table) {
            $table->unique('ulid');
            $table->dropForeign(['example_id']);
        });
        Schema::table('attempts', function (Blueprint $table) {
            $table->ulid('ulid')->nullable(false)->change();
            $table->unsignedBigInteger('example_id')->nullable()->change();
            $table->foreign('example_id')->references('id')->on('examples')->nullOnDelete();
            $table->dropColumn('answers');
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Dữ liệu cũ: chụp câu hỏi hiện tại của đề; câu trả lời cũ là chỉ số đáp án (không biết
     * thứ tự đã xáo) → quy theo thứ tự đáp án trong DB (gần đúng nhất có thể).
     */
    private function migrateAnswers(object $attempt): void
    {
        $old = collect(json_decode($attempt->answers ?? '[]', true) ?: [])->keyBy('question_id');
        if ($old->isEmpty()) {
            return;
        }

        $questions = DB::table('questions')
            ->join('parts', 'parts.id', '=', 'questions.part_id')
            ->where('parts.example_id', $attempt->example_id)
            ->orderBy('parts.position')->orderBy('questions.position')
            ->get(['questions.*', 'parts.name as part_name']);

        $rows = [];
        foreach ($questions->values() as $position => $q) {
            $answers = DB::table('answers')->where('question_id', $q->id)->orderBy('position')->get();
            $ids = $answers->pluck('id')->all();
            $given = $old->get($q->id);
            $correct = $given === null ? null : (bool) ($given['correct'] ?? false);
            $points = (float) $q->points;

            $rows[] = [
                'attempt_id' => $attempt->id,
                'question_id' => $q->id,
                'position' => $position,
                'part_name' => $q->part_name,
                'question' => json_encode([
                    'type' => $q->type,
                    'name' => $q->content,
                    'explanation' => $q->explanation,
                    'points' => $points,
                    'settings' => json_decode($q->settings ?? 'null', true),
                    'img' => DB::table('question_media')->where('question_id', $q->id)
                        ->orderBy('position')->get(['url'])->map(fn ($m) => ['url' => $m->url])->all(),
                    'answers' => $answers->map(fn ($a) => [
                        'id' => $a->id,
                        'option' => $a->content,
                        'is_correct' => (bool) $a->is_correct,
                        'meta' => json_decode($a->meta ?? 'null', true),
                    ])->all(),
                ], JSON_UNESCAPED_UNICODE),
                'response' => $given === null ? null : json_encode(
                    $this->toIds($q->type, $given['response'] ?? null, $ids),
                    JSON_UNESCAPED_UNICODE,
                ),
                'is_correct' => $correct,
                'points' => $points,
                'earned_points' => $correct ? $points : 0,
            ];
        }

        DB::table('attempt_answers')->insert($rows);
    }

    private function toIds(string $type, mixed $response, array $ids): mixed
    {
        $id = fn ($i) => is_int($i) && $i >= 0 ? ($ids[$i] ?? null) : null;

        return match (true) {
            $type === 'fill_blank' => is_string($response) ? $response : null,
            $type === 'matching' => is_array($response)
                ? collect($response)->map(fn ($right, $left) => [$id($left), $id($right)])->values()->all()
                : null,
            is_array($response) => array_values(array_filter(array_map($id, $response), fn ($v) => $v !== null)),
            default => array_values(array_filter([$id($response)])),
        };
    }

    public function down(): void
    {
        Schema::table('attempts', function (Blueprint $table) {
            $table->json('answers')->nullable()->after('duration_seconds');
        });

        foreach (DB::table('attempts')->pluck('id') as $id) {
            $answers = DB::table('attempt_answers')
                ->where('attempt_id', $id)->whereNotNull('is_correct')->orderBy('position')
                ->get()
                ->map(fn ($row) => [
                    'question_id' => $row->question_id,
                    'response' => json_decode($row->response ?? 'null', true),
                    'correct' => (bool) $row->is_correct,
                ]);
            DB::table('attempts')->where('id', $id)->update(['answers' => json_encode($answers)]);
        }

        Schema::drop('attempt_answers');
        // Lượt thi của đề đã xóa không quay lại được ràng buộc cascade cũ → bỏ đi.
        DB::table('attempts')->whereNull('example_id')->delete();

        Schema::table('attempts', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'created_at']);
            $table->dropForeign(['example_id']);
            $table->dropUnique(['ulid']);
        });
        Schema::table('attempts', function (Blueprint $table) {
            $table->unsignedBigInteger('example_id')->nullable(false)->change();
            $table->foreign('example_id')->references('id')->on('examples')->cascadeOnDelete();
            $table->dropColumn(['ulid', 'example_name']);
        });
    }
};
