<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExampleRequest;
use App\Models\Example;
use App\Models\Sector;
use App\Services\CloudinaryImages;
use App\Services\ExampleContent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExampleController extends Controller
{
    public function __construct(
        private ExampleContent $content,
        private CloudinaryImages $images,
    ) {
    }

    /**
     * GET /sitemap/examples — danh sách gọn cho sitemap.xml: đề công khai có ≥ 5 câu
     * (bỏ đề nháp / đề rác ít câu), chỉ slug + thời điểm sửa + ảnh bìa.
     */
    public function sitemap()
    {
        return response()->json(
            Example::visible()->where('num_question', '>=', 5)
                ->orderByDesc('updated_at')
                ->get(['slug', 'updated_at', 'image'])
                ->each->setAppends([]) // bỏ user_action (tốn truy vấn, sitemap không cần)
        );
    }

    /** GET /examples — danh sách đề (công khai), lọc + phân trang qua query string. */
    public function index(Request $request)
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);
        $result = Example::filter($request->all())->visible()->withAll()->paginate($perPage);

        return response()->json($result);
    }

    /** GET /users/me/examples — đề do tôi tạo. */
    public function mine(Request $request)
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);
        $examples = Example::where('user_id', auth()->id())
            ->filter($request->all())
            ->withAll()
            ->paginate($perPage);

        return response()->json($examples);
    }

    /** GET /users/me/favorites — đề tôi đã đánh dấu yêu thích. */
    public function favorites(Request $request)
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);
        $examples = Example::filter($request->all())->myFavorite()->paginate($perPage);

        return response()->json($examples);
    }

    /**
     * GET /users/me/done-examples — đề tôi đã làm (còn công khai), đề làm gần nhất lên đầu.
     * Nhận các bộ lọc như danh sách đề (search, sector, số câu) nhưng KHÔNG nhận orderBy/sort.
     */
    public function done(Request $request)
    {
        $id = auth()->id();
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        $examples = Example::filter($request->except(['orderBy', 'sort']))
            ->visible()
            ->withAll()
            ->whereHas('attempts', fn ($q) => $q->where('user_id', $id))
            ->withMax(['attempts as my_last_done_at' => fn ($q) => $q->where('user_id', $id)], 'created_at')
            ->reorder()
            ->orderByDesc('my_last_done_at')
            ->orderByDesc('examples.id')
            ->paginate($perPage);

        return response()->json($examples);
    }

    /**
     * GET /examples/{example} — chi tiết đề kèm nội dung (phần → câu hỏi → đáp án).
     * Quyền sửa được kiểm tra ở PUT.
     */
    public function show(Example $example)
    {
        abort_unless($example->viewableBy(auth('api')->user()), 404);
        $data = $this->detail($example);
        // Vé bắt đầu làm bài (gửi kèm khi nộp) → server tự đo thời gian, chống nộp điểm "ảo".
        $data['attempt_token'] = \App\Services\AttemptToken::issue(auth()->id(), $example->id);

        return response()->json($data);
    }

    /** Số câu mẫu trả về ở trang xem trước công khai. */
    private const PREVIEW_SAMPLE = 3;

    /**
     * GET /examples/{example}/preview — CÔNG KHAI (không cần đăng nhập), cho trang xem trước
     * đề + SEO: thông tin đề, cấu trúc phần (tên + số câu) và vài câu hỏi mẫu.
     * KHÔNG trả toàn bộ câu hỏi / đáp án (muốn làm bài phải đăng nhập → GET /examples/{example}).
     * Đã đăng nhập thì có thêm trạng thái thích / yêu thích (user_action).
     */
    public function preview(Example $example)
    {
        abort_unless($example->viewableBy(auth('api')->user()), 404);
        $example = Example::withAll()->findOrFail($example->id);
        $parts = $this->content->toArray($example);

        $data = $example->toArray();
        unset($data['parts']);
        $data['parts'] = array_map(fn ($part) => [
            'name' => $part['name'],
            'questions_count' => count($part['questions']),
        ], $parts);
        $data['sample'] = array_slice(
            array_merge(...array_map(fn ($part) => $part['questions'], $parts ?: [[]])),
            0,
            self::PREVIEW_SAMPLE,
        );

        return response()->json($data);
    }

    /** POST /examples — tạo đề. `num_question` do server tự đếm. */
    public function store(ExampleRequest $request)
    {
        if (!auth()->user()->canCreateExams()) {
            return response()->json([
                'message' => 'Tài khoản của bạn đã bị hạn chế tạo đề thi.',
                'code' => 'cannot_create_exams',
            ], 403);
        }
        $data = $request->validated();
        $status = $this->statusAfterSave();

        $example = DB::transaction(function () use ($data, $status) {
            $example = new Example();
            $example->forceFill([
                'status' => $status,
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
        $this->content->update($example, $request->validated());

        // Bật duyệt đề: người dùng thường sửa đề → gửi duyệt lại (đề bị từ chối sửa xong = gửi lại).
        $status = $this->statusAfterSave();
        if ($status !== $example->status) {
            $example->forceFill(['status' => $status, 'reviewed_by' => null, 'reviewed_at' => null])->saveQuietly();
        }

        return response()->json($this->detail($example->fresh()));
    }

    /**
     * Trạng thái đề sau khi tạo / sửa: bật duyệt đề + người dùng thường → chờ duyệt;
     * còn lại (tắt duyệt, administrator / manager) → được duyệt luôn.
     */
    private function statusAfterSave(): string
    {
        $needsReview = app(\App\Services\SiteSettings::class)->examApprovalRequired()
            && !auth()->user()->isStaff();

        return $needsReview ? Example::STATUS_PENDING : Example::STATUS_APPROVED;
    }

    /** DELETE /examples/{example} — xóa đề của tôi (kèm ảnh + bộ câu hỏi). */
    public function destroy(Example $example)
    {
        if (!$this->isOwner($example)) {
            return response()->json('Bạn không có quyền xóa đề này', 403);
        }

        $this->content->destroy($example);

        return response()->noContent();
    }

    /**
     * PUT /examples/{example}/like — body { like: bool }.
     * Khóa chính (user_id, example_id) chống trùng; chỉ đổi bộ đếm khi thật sự thêm / xóa dòng.
     */
    public function like(Request $request, Example $example)
    {
        abort_unless($example->viewableBy(auth('api')->user()), 404);
        $request->validate(['like' => 'required|boolean']);
        $like = $request->boolean('like');
        $pair = ['user_id' => auth()->id(), 'example_id' => $example->id];

        // Đếm lượt thích không tính là sửa đề (giữ nguyên updated_at).
        $example->timestamps = false;
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
        abort_unless($example->viewableBy(auth('api')->user()), 404);
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

    /** Chi tiết đề + nội dung theo định dạng API (`question`: danh sách phần). */
    private function detail(Example $example): array
    {
        return $this->content->detail($example);
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

