<?php

namespace App\Http\Controllers;

use App\Models\Example;
use App\Models\Sector;
use App\Models\UserAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ExampleController extends Controller
{
    /** GET /examples — danh sách đề (công khai), lọc + phân trang qua query string. */
    public function index(Request $request)
    {
        $perPage = $request->query('per_page', 9);
        $result = Example::filter($request->all())->withAll()->paginate($perPage);

        return response()->json($result);
    }

    /** GET /users/me/examples — đề do tôi tạo. */
    public function mine(Request $request)
    {
        $perPage = $request->query('per_page', 9);
        $examples = Example::where('user_id', auth()->id())
            ->filter($request->all())
            ->withAll()
            ->paginate($perPage);

        return response()->json($examples);
    }

    /** GET /users/me/favorites — đề tôi đã đánh dấu yêu thích. */
    public function favorites(Request $request)
    {
        $perPage = $request->query('per_page', 9);
        $examples = Example::filter($request->all())->myFavorite()->paginate($perPage);

        return response()->json($examples);
    }

    /**
     * GET /examples/{example} — chi tiết đề kèm bộ câu hỏi.
     * (Gộp getQuestion + getMyQuestion cũ; quyền sửa được kiểm tra ở PUT.)
     */
    public function show(Example $example)
    {
        $data = Example::withAll()->with('question')->findOrFail($example->id)->toArray();
        if (isset($data['question']['question'])) {
            $data['question'] = $data['question']['question'];
        }

        return response()->json($data);
    }

    /** POST /examples — tạo đề. */
    public function store(Request $request)
    {
        $data = $request->input();

        $example = DB::transaction(function () use ($data) {
            $questionId = DB::table('questions')->insertGetId([
                'question' => $data['question'],
            ]);
            $exampleId = DB::table('examples')->insertGetId([
                'name' => $data['name'],
                'slug' => Example::uniqueSlug($data['name']),
                'count_test' => 0,
                'count_like' => 0,
                'num_question' => $data['count_question'],
                'credits' => $data['credits'],
                'image' => $data['image'] ?? null,
                'sector_id' => $this->sectorId($data['sector'] ?? null),
                'question_id' => $questionId,
                'user_id' => auth()->id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return Example::withAll()->findOrFail($exampleId);
        });

        return response()->json($example, 201);
    }

    /** PUT /examples/{example} — sửa đề của tôi. */
    public function update(Request $request, Example $example)
    {
        if (!$this->isOwner($example)) {
            return response()->json('Bạn không có quyền sửa đề này', 403);
        }

        $example->update([
            'num_question' => $request->input('count_question'),
            'name' => $request->input('name'),
            'credits' => $request->input('credits'),
            'image' => $request->input('image'),
            'sector_id' => $this->sectorId($request->input('sector')),
        ]);
        $example->load(['user', 'question']);
        if ($example->question) {
            $example->question->update([
                'question' => $request->input('question'),
            ]);
        }

        return response()->json($example);
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
        $questionId = $example->question_id;
        $example->delete();
        DB::table('questions')->where('id', $questionId)->delete();

        return response()->noContent();
    }

    /** PUT /examples/{example}/like — body { like: bool }. */
    public function like(Request $request, Example $example)
    {
        $request->validate(['like' => 'required|boolean']);
        $like = $request->boolean('like');

        $action = $this->userAction($example);
        // Chỉ đổi bộ đếm khi trạng thái thực sự thay đổi (tránh like 2 lần bị +2).
        if ((bool) $action->like !== $like) {
            $like ? $example->increment('count_like') : $example->decrement('count_like');
            $action->like = $like;
            $action->save();
        }

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

        $action = $this->userAction($example);
        $action->favorited = $favorited;
        $action->save();

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

    /** Body gửi `sector` là slug ngành → đổi sang id để lưu khóa ngoại. */
    private function sectorId(?string $slug): ?int
    {
        return $slug ? Sector::where('slug', $slug)->value('id') : null;
    }

    private function isOwner(Example $example): bool
    {
        return auth()->id() == $example->user_id;
    }

    /** Bản ghi like/favorite của user hiện tại với đề (tạo mới nếu chưa có). */
    private function userAction(Example $example): UserAction
    {
        return UserAction::firstOrNew(
            ['user_id' => auth()->id(), 'example_id' => $example->id],
            ['like' => false, 'favorited' => false],
        );
    }
}

//https://studio-gw.eduquiz.io.vn/quiz-chanel/public/api/v1/exams/13995/sections?bizapp=EDUQUIZ&is_question_shuffle=0&is_answer_shuffle=0&allow_access_exam=true
