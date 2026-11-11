<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExampleRequest;
use App\Models\Example;
use App\Models\Sector;
use App\Services\ExampleContent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ExampleController extends Controller
{
    public function __construct(private ExampleContent $content)
    {
    }

    /** GET /examples — danh sách đề (công khai), lọc + phân trang qua query string. */
    public function index(Request $request)
    {
        $perPage = $request->query('per_page', 15);
        $result = Example::filter($request->all())->withAll()->paginate($perPage);

        return response()->json($result);
    }

    /** GET /users/me/examples — đề do tôi tạo. */
    public function mine(Request $request)
    {
        $perPage = $request->query('per_page', 15);
        $examples = Example::where('user_id', auth()->id())
            ->filter($request->all())
            ->withAll()
            ->paginate($perPage);

        return response()->json($examples);
    }

    /** GET /users/me/favorites — đề tôi đã đánh dấu yêu thích. */
    public function favorites(Request $request)
    {
        $perPage = $request->query('per_page', 15);
        $examples = Example::filter($request->all())->myFavorite()->paginate($perPage);

        return response()->json($examples);
    }

    /**
     * GET /examples/{example} — chi tiết đề kèm nội dung (phần → câu hỏi → đáp án).
     * Quyền sửa được kiểm tra ở PUT.
     */
    public function show(Example $example)
    {
        return response()->json($this->detail($example));
    }

    /** POST /examples — tạo đề. `num_question` do server tự đếm. */
    public function store(ExampleRequest $request)
    {
        $data = $request->validated();

        $example = DB::transaction(function () use ($data) {
            $example = new Example();
            $example->forceFill([
                'name' => $data['name'],
                'count_test' => 0,
                'count_like' => 0,
                'num_question' => 0,
                'credits' => $data['credits'],
                'image' => $data['image'] ?? null,
                'sector_id' => $this->sectorId($data['sector']),
                'user_id' => auth()->id(),
            ])->save(); // slug tự sinh (HasSlug)
            $this->content->sync($example, $data['question']);

            return $example;
        });

        return response()->json($this->detail($example), 201);
    }

    /** PUT /examples/{example} — sửa đề của tôi (ghi đè toàn bộ nội dung). */
    public function update(ExampleRequest $request, Example $example)
    {
        if (!$this->isOwner($example)) {
            return response()->json('Bạn không có quyền sửa đề này', 403);
        }
        $data = $request->validated();

        DB::transaction(function () use ($example, $data) {
            $example->forceFill([
                'name' => $data['name'],
                'credits' => $data['credits'],
                'sector_id' => $this->sectorId($data['sector']),
            ]);
            // Chỉ đổi ảnh khi client gửi trường `image` (bản cũ luôn ghi đè → mất ảnh).
            if (array_key_exists('image', $data)) {
                $example->image = $data['image'];
            }
            $example->save();
            $this->content->sync($example, $data['question']);
        });

        return response()->json($this->detail($example->fresh()));
    }

    /** DELETE /examples/{example} — xóa đề của tôi (kèm ảnh + bộ câu hỏi). */
    public function destroy(Example $example)
    {
        if (!$this->isOwner($example)) {
            return response()->json('Bạn không có quyền xóa đề này', 403);
        }

        $publicId = $example->image ? pathinfo(basename($example->image), PATHINFO_FILENAME) : null;
        if ($publicId) {
            cloudinary()->destroy('img_quiz/' . $publicId);
        }
        // Phần / câu hỏi / đáp án / media xóa theo khóa ngoại (cascade).
        $example->delete();

        return response()->noContent();
    }

    /**
     * PUT /examples/{example}/like — body { like: bool }.
     * Khóa chính (user_id, example_id) chống trùng; chỉ đổi bộ đếm khi thật sự thêm / xóa dòng.
     */
    public function like(Request $request, Example $example)
    {
        $request->validate(['like' => 'required|boolean']);
        $like = $request->boolean('like');
        $pair = ['user_id' => auth()->id(), 'example_id' => $example->id];

        DB::transaction(function () use ($like, $pair, $example) {
            if ($like) {
                if (DB::table('likes')->insertOrIgnore($pair + ['created_at' => now()])) {
                    $example->increment('count_like');
                }
            } elseif (DB::table('likes')->where($pair)->delete()) {
                $example->decrement('count_like');
            }
        });

        return response()->json([
            'count_like' => $example->fresh()->count_like,
            'like' => $like,
        ]);
    }

    /** PUT /examples/{example}/favorite — body { favorited: bool }. */
    public function favorite(Request $request, Example $example)
    {
        $request->validate(['favorited' => 'required|boolean']);
        $favorited = $request->boolean('favorited');
        $pair = ['user_id' => auth()->id(), 'example_id' => $example->id];

        if ($favorited) {
            DB::table('favorites')->insertOrIgnore($pair + ['created_at' => now()]);
        } else {
            DB::table('favorites')->where($pair)->delete();
        }

        return response()->json(['favorited' => $favorited]);
    }

    /** GET /eduquiz-exams/{tag}?type=EDUQUIZ — lấy đề từ EduQuiz (chỉ admin). */
    public function importFromEduquiz(Request $request, string $tag)
    {
        if (auth()->user()->role != 'admin') {
            return response()->json("Bạn không có quyền này !", 403);
        }

        $result = [];
        try {
            $type = $request->input('type');
            $response = Http::withHeaders([
                'Authorization' => 'Bearer 892475|ZdHRPg6gPE3LsJXuLeLIoTtetrwlEZkH9ZLEdxGG',
                'Accept' => 'application/json',
            ])->get('https://studio-api-07.eduquiz.io.vn/quiz-chanel/public/api/v1/exams/' . $tag . '/sections?bizapp=' . $type . '&is_question_shuffle=0&is_answer_shuffle=0&allow_access_exam=true');
            if (!$response->successful()) {
                return response()->json("Không lấy được đề từ EduQuiz", 502);
            }
            $data = $response['data'];
            foreach ($data as $part) {
                $objParts = ['name' => $part['name'], 'questions' => []];
                foreach ($part['questions'] as $question) {
                    $objQuestion = ['name' => $question['name'], 'img' => $question['files'], 'answers' => []];
                    foreach ($question['answers'] as $answer) {
                        $objQuestion['answers'][] = ['option' => $answer['option'], 'is_correct' => $answer['is_correct']];
                    }
                    $objParts['questions'][] = $objQuestion;
                }
                $result[] = $objParts;
            }

            return response()->json($result);
        } catch (\Throwable $th) {
            return response()->json("Đề thi bị lỗi", 400);
        }
    }

    /** Chi tiết đề + nội dung theo định dạng API (`question`: danh sách phần). */
    private function detail(Example $example): array
    {
        $example = Example::withAll()->with(ExampleContent::RELATIONS)->findOrFail($example->id);
        $data = $example->toArray();
        unset($data['parts']);
        $data['question'] = $this->content->toArray($example);

        return $data;
    }

    /** Body gửi `sector` là slug ngành → đổi sang id để lưu khóa ngoại. */
    private function sectorId(?string $slug): ?int
    {
        return $slug ? Sector::where('slug', $slug)->value('id') : null;
    }

    private function isOwner(Example $example): bool
    {
        return auth()->id() == $example->user_id;
    }

}

//https://studio-gw.eduquiz.io.vn/quiz-chanel/public/api/v1/exams/13995/sections?bizapp=EDUQUIZ&is_question_shuffle=0&is_answer_shuffle=0&allow_access_exam=true
