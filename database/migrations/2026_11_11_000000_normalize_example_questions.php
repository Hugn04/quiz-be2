<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chuẩn hóa nội dung đề thi: bỏ cột JSON `questions.question` (cả đề nằm trong 1 chuỗi)
 * → các bảng quan hệ:
 *
 *   examples 1─n parts 1─n questions 1─n answers
 *                              └────1─n question_media
 *
 * - `position` giữ thứ tự hiển thị trong cha.
 * - `questions.type` (single_choice, multiple_choice, true_false...) và `explanation`
 *   để mở rộng sau này mà không phải đổi schema.
 * - Xóa đề / phần / câu → xóa dây chuyền qua khóa ngoại.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Bảng JSON cũ tên `questions` → đổi tên để nhường tên cho bảng câu hỏi mới.
        Schema::rename('questions', 'legacy_question_sets');

        Schema::create('parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('example_id')->constrained('examples')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->index(['example_id', 'position']);
        });

        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('part_id')->constrained('parts')->cascadeOnDelete();
            $table->string('type', 32)->default('single_choice');
            $table->text('content');
            $table->text('explanation')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->index(['part_id', 'position']);
        });

        Schema::create('answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained('questions')->cascadeOnDelete();
            $table->text('content');
            $table->boolean('is_correct')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->index(['question_id', 'position']);
        });

        Schema::create('question_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained('questions')->cascadeOnDelete();
            $table->string('type', 16)->default('image');
            $table->string('url', 2048);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->index(['question_id', 'position']);
        });

        // Chuyển dữ liệu JSON cũ sang bảng mới.
        $now = now();
        $examples = DB::table('examples')
            ->join('legacy_question_sets', 'legacy_question_sets.id', '=', 'examples.question_id')
            ->select('examples.id', 'legacy_question_sets.question')
            ->get();

        foreach ($examples as $row) {
            $parts = json_decode($row->question, true);
            if (is_string($parts)) {
                $parts = json_decode($parts, true); // phòng dữ liệu bị encode 2 lần
            }
            $total = 0;
            foreach (($parts ?: []) as $pi => $part) {
                $partId = DB::table('parts')->insertGetId([
                    'example_id' => $row->id,
                    'name' => (string) ($part['name'] ?? 'Phần ' . ($pi + 1)),
                    'position' => $pi,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                foreach (($part['questions'] ?? []) as $qi => $question) {
                    $questionId = DB::table('questions')->insertGetId([
                        'part_id' => $partId,
                        'content' => (string) ($question['name'] ?? ''),
                        'position' => $qi,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    foreach (($question['answers'] ?? []) as $ai => $answer) {
                        DB::table('answers')->insert([
                            'question_id' => $questionId,
                            'content' => (string) ($answer['option'] ?? ''),
                            'is_correct' => !empty($answer['is_correct']),
                            'position' => $ai,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                    foreach (($question['img'] ?? []) as $mi => $media) {
                        $url = is_array($media) ? ($media['url'] ?? null) : $media;
                        if ($url) {
                            DB::table('question_media')->insert([
                                'question_id' => $questionId,
                                'url' => $url,
                                'position' => $mi,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ]);
                        }
                    }
                    $total++;
                }
            }
            DB::table('examples')->where('id', $row->id)->update(['num_question' => $total]);
        }

        // Bỏ liên kết tới bảng JSON cũ.
        Schema::table('examples', function (Blueprint $table) {
            $table->dropForeign('examples_question_id_foreign');
            $table->dropColumn('question_id');
        });
        Schema::drop('legacy_question_sets');
    }

    /** Dựng lại bảng JSON cũ từ các bảng chuẩn hóa (để rollback). */
    public function down(): void
    {
        Schema::create('legacy_question_sets', function (Blueprint $table) {
            $table->id();
            $table->longText('question');
        });
        Schema::table('examples', function (Blueprint $table) {
            $table->unsignedBigInteger('question_id')->nullable();
        });

        foreach (DB::table('examples')->pluck('id') as $exampleId) {
            $parts = [];
            foreach (DB::table('parts')->where('example_id', $exampleId)->orderBy('position')->get() as $part) {
                $questions = [];
                foreach (DB::table('questions')->where('part_id', $part->id)->orderBy('position')->get() as $q) {
                    $item = [
                        'name' => $q->content,
                        'answers' => DB::table('answers')->where('question_id', $q->id)->orderBy('position')->get()
                            ->map(fn ($a) => ['option' => $a->content, 'is_correct' => (bool) $a->is_correct])
                            ->all(),
                    ];
                    $media = DB::table('question_media')->where('question_id', $q->id)->orderBy('position')->get();
                    if ($media->isNotEmpty()) {
                        $item['img'] = $media->map(fn ($m) => ['url' => $m->url])->all();
                    }
                    $questions[] = $item;
                }
                $parts[] = ['name' => $part->name, 'questions' => $questions];
            }
            $setId = DB::table('legacy_question_sets')->insertGetId([
                'question' => json_encode($parts, JSON_UNESCAPED_UNICODE),
            ]);
            DB::table('examples')->where('id', $exampleId)->update(['question_id' => $setId]);
        }

        Schema::dropIfExists('question_media');
        Schema::dropIfExists('answers');
        Schema::dropIfExists('questions');
        Schema::dropIfExists('parts');
        Schema::rename('legacy_question_sets', 'questions');

        Schema::table('examples', function (Blueprint $table) {
            $table->unsignedBigInteger('question_id')->nullable(false)->change();
            $table->foreign('question_id')->references('id')->on('questions')->cascadeOnDelete();
        });
    }
};
