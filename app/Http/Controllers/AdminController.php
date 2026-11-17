<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExampleRequest;
use App\Models\AdminLog;
use App\Models\Attempt;
use App\Models\Example;
use App\Models\User;
use App\Services\ExampleContent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * API trang quản trị — /admin/*.
 *  - administrator + manager: số liệu tổng quan, quản lý (xem / sửa / xóa) MỌI đề thi.
 *  - administrator + manager: danh sách người dùng, khóa / mở khóa, cho / chặn tạo đề
 *    (manager chỉ với người dùng thường — xem User::canModerate).
 *  - chỉ administrator: cấp role.
 * Quyền manager với đề của người khác CHỈ có ở đây (API /examples/* vẫn chỉ cho chủ đề).
 */
class AdminController extends Controller
{
    public function __construct(private ExampleContent $content)
    {
    }

    /** GET /admin/stats — số liệu tổng quan. */
    public function stats()
    {
        $monthStart = now()->startOfMonth();

        $topMonth = Attempt::query()
            ->where('created_at', '>=', $monthStart)
            ->whereNotNull('example_id')
            ->groupBy('example_id')
            ->select('example_id', DB::raw('COUNT(*) as month_attempts'))
            ->orderByDesc('month_attempts')
            ->limit(5)
            ->pluck('month_attempts', 'example_id');
        $topExamples = Example::whereIn('id', $topMonth->keys())->get(['id', 'slug', 'name', 'num_question'])
            ->map(fn ($e) => [
                'slug' => $e->slug,
                'name' => $e->name,
                'num_question' => $e->num_question,
                'month_attempts' => (int) $topMonth[$e->id],
            ])
            ->sortByDesc('month_attempts')
            ->values();

        return response()->json([
            'users' => User::count(),
            'online' => User::online()->count(),
            'users_by_role' => User::query()->selectRaw('role, COUNT(*) as total')->groupBy('role')->pluck('total', 'role'),
            'new_users_month' => User::where('created_at', '>=', $monthStart)->count(),
            'examples' => Example::count(),
            'new_examples_month' => Example::where('created_at', '>=', $monthStart)->count(),
            'questions' => DB::table('questions')->count(),
            'attempts' => Attempt::count(),
            'attempts_month' => Attempt::where('created_at', '>=', $monthStart)->count(),
            'top_examples_month' => $topExamples,
        ]);
    }

    /**
     * GET /admin/examples — mọi đề thi (phân trang).
     * ?search= (tên đề / tên tác giả) ?sector=<slug> ?orderBy= ?sort= ?per_page= (≤ 50).
     */
    public function examples(Request $request)
    {
        $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'state' => ['nullable', Rule::in(['visible', 'hidden', 'pinned', 'pending', 'rejected'])],
        ]);

        $examples = Example::filter($request->all())
            ->when($request->query('state') === 'visible', fn ($q) => $q->visible())
            ->when($request->query('state') === 'hidden', fn ($q) => $q->whereNotNull('hidden_at'))
            ->when($request->query('state') === 'pinned', fn ($q) => $q->whereNotNull('pinned_at'))
            ->when($request->query('state') === 'pending', fn ($q) => $q->where('status', Example::STATUS_PENDING))
            ->when($request->query('state') === 'rejected', fn ($q) => $q->where('status', Example::STATUS_REJECTED))
            ->with(['user', 'sector', 'reviewer:id,name'])
            ->paginate((int) $request->query('per_page', 20));

        return response()->json($examples);
    }

    /** GET /admin/examples/{example} — chi tiết đầy đủ để sửa. */
    public function showExample(Example $example)
    {
        return response()->json($this->content->detail($example));
    }

    /** PUT /admin/examples/{example} — sửa đề bất kỳ (cùng định dạng với PUT /examples/{example}). */
    public function updateExample(ExampleRequest $request, Example $example)
    {
        $this->content->update($example, $request->validated());
        AdminLog::record('exam.update', 'exam', $example->slug, $example->name, [
            'author' => $example->user?->name,
        ]);

        return response()->json($this->content->detail($example->fresh()));
    }

    /** DELETE /admin/examples/{example} — xóa đề bất kỳ (kèm ảnh, bộ câu hỏi). */
    public function destroyExample(Example $example)
    {
        AdminLog::record('exam.delete', 'exam', $example->slug, $example->name, [
            'author' => $example->user?->name,
            'questions' => $example->num_question,
        ]);
        $this->content->destroy($example);

        return response()->noContent();
    }

    /**
     * PATCH /admin/examples/{example}/visibility — ẩn / hiện đề. Body { hidden: bool, reason?: string }.
     * Đề ẩn: biến mất khỏi danh sách, trang chủ, sitemap; chỉ tác giả và quản trị còn mở được.
     */
    public function updateVisibility(Request $request, Example $example)
    {
        $data = $request->validate([
            'hidden' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        $wasHidden = $example->isHidden();
        $example->forceFill([
            'hidden_at' => $data['hidden'] ? ($example->hidden_at ?? now()) : null,
            'hidden_reason' => $data['hidden'] ? (trim((string) ($data['reason'] ?? '')) ?: null) : null,
            // Đề bị ẩn thì không còn ghim.
            'pinned_at' => $data['hidden'] ? null : $example->pinned_at,
        ])->saveQuietly();

        if ($wasHidden !== $example->isHidden()) {
            AdminLog::record($example->isHidden() ? 'exam.hide' : 'exam.unhide', 'exam', $example->slug, $example->name,
                $example->isHidden() && $example->hidden_reason ? ['reason' => $example->hidden_reason] : []);
        }

        return response()->json($example->only(['slug', 'hidden_at', 'hidden_reason', 'pinned_at']));
    }

    /** PATCH /admin/examples/{example}/pin — ghim / bỏ ghim lên trang chủ. Body { pinned: bool }. */
    public function updatePin(Request $request, Example $example)
    {
        $data = $request->validate(['pinned' => ['required', 'boolean']]);
        if ($data['pinned'] && !$example->isPublic()) {
            return response()->json(['message' => 'Chỉ ghim được đề đang công khai (không bị ẩn, đã duyệt).'], 422);
        }
        $wasPinned = $example->pinned_at !== null;
        $example->forceFill(['pinned_at' => $data['pinned'] ? ($example->pinned_at ?? now()) : null])->saveQuietly();

        if ($wasPinned !== ($example->pinned_at !== null)) {
            AdminLog::record($data['pinned'] ? 'exam.pin' : 'exam.unpin', 'exam', $example->slug, $example->name);
        }

        return response()->json($example->only(['slug', 'hidden_at', 'hidden_reason', 'pinned_at']));
    }

    /** GET /admin/examples/review-count — số đề đang chờ duyệt (badge menu quản trị). */
    public function reviewCount()
    {
        return response()->json([
            'pending' => Example::where('status', Example::STATUS_PENDING)->count(),
            'enabled' => app(\App\Services\SiteSettings::class)->examApprovalRequired(),
        ]);
    }

    /**
     * PATCH /admin/examples/{example}/review — duyệt / từ chối đề.
     * Body { action: approve | reject, note?: string } (từ chối nên ghi lý do cho tác giả).
     */
    public function review(Request $request, Example $example)
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['approve', 'reject'])],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $status = $data['action'] === 'approve' ? Example::STATUS_APPROVED : Example::STATUS_REJECTED;
        $note = trim((string) ($data['note'] ?? '')) ?: null;

        $example->forceFill([
            'status' => $status,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
            'review_note' => $note,
            // Đề bị từ chối thì không còn ghim.
            'pinned_at' => $status === Example::STATUS_APPROVED ? $example->pinned_at : null,
        ])->saveQuietly();

        AdminLog::record($status === Example::STATUS_APPROVED ? 'exam.approve' : 'exam.reject', 'exam', $example->slug, $example->name,
            array_filter(['author' => $example->user?->name, 'reason' => $note]));

        return response()->json($example->only(['slug', 'status', 'reviewed_at', 'review_note', 'hidden_at', 'pinned_at']));
    }

    /** GET /admin/online — người đang online (hoạt động trong 5 phút), mới nhất trước. */
    public function online()
    {
        $users = User::online()->orderByDesc('last_seen_at')->limit(50)
            ->get(['uuid', 'name', 'avatar', 'role', 'last_seen_at']);

        return response()->json([
            'minutes' => User::ONLINE_MINUTES,
            'total' => User::online()->count(),
            // Hoạt động trong 24 giờ qua (để so sánh).
            'today' => User::where('last_seen_at', '>=', now()->subDay())->count(),
            'users' => $users,
        ]);
    }

    /** Kiểu sắp xếp danh sách người dùng → [cột / biểu thức, chiều]. */
    private const USER_SORTS = [
        'role' => null, // administrator → manager → người mới nhất (mặc định)
        'newest' => ['created_at', 'desc'],
        'oldest' => ['created_at', 'asc'],
        'name' => ['name', 'asc'],
        'exams' => ['examples_count', 'desc'],
        'attempts' => ['attempts_count', 'desc'],
        'last_seen' => ['last_seen_at', 'desc'],
    ];

    /**
     * GET /admin/users — người dùng (administrator + manager), phân trang.
     *  ?search=   tên / email
     *  ?role=     administrator | manager | person
     *  ?status=   active | banned | restricted
     *  ?activity= has_exams | no_exams | has_attempts | inactive
     *  ?joined=   7d | 30d | 90d | year
     *  ?sort=     role | newest | oldest | name | exams | attempts
     *  ?per_page= ≤ 50
     * Kèm `facets`: số người theo role / trạng thái (toàn hệ thống) để hiện trên bộ lọc.
     */
    public function users(Request $request)
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'role' => ['nullable', Rule::in([User::ROLE_ADMINISTRATOR, User::ROLE_MANAGER, User::ROLE_PERSON])],
            'status' => ['nullable', Rule::in(['active', 'banned', 'restricted'])],
            'activity' => ['nullable', Rule::in(['online', 'has_exams', 'no_exams', 'has_attempts', 'inactive'])],
            'joined' => ['nullable', Rule::in(['7d', '30d', '90d', 'year'])],
            'online' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(array_keys(self::USER_SORTS))],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = User::query()
            ->when(trim((string) ($data['search'] ?? '')) !== '', function ($q) use ($data) {
                $term = '%' . addcslashes(trim($data['search']), '%_\\') . '%';
                $q->where(fn ($w) => $w->where('name', 'like', $term)->orWhere('email', 'like', $term));
            })
            ->when($data['role'] ?? null, fn ($q, $role) => $q->where('role', $role))
            ->when($data['status'] ?? null, fn ($q, $status) => match ($status) {
                'active' => $q->whereNull('banned_at'),
                'banned' => $q->whereNotNull('banned_at'),
                'restricted' => $q->where('can_create_exams', false),
            })
            ->when($data['activity'] ?? null, fn ($q, $activity) => match ($activity) {
                'online' => $q->online(),
                'has_exams' => $q->has('examples'),
                'no_exams' => $q->doesntHave('examples'),
                'has_attempts' => $q->has('attempts'),
                'inactive' => $q->doesntHave('examples')->doesntHave('attempts'),
            })
            ->when($data['joined'] ?? null, fn ($q, $joined) => $q->where('created_at', '>=', match ($joined) {
                '7d' => now()->subDays(7),
                '30d' => now()->subDays(30),
                '90d' => now()->subDays(90),
                'year' => now()->startOfYear(),
            }))
            ->when($request->boolean('online'), fn ($q) => $q->online())
            ->withCount(['examples', 'attempts']);

        $sort = self::USER_SORTS[$data['sort'] ?? 'role'];
        if ($sort === null) {
            $query->orderByRaw("FIELD(role, 'manager', 'administrator') DESC")->latest();
        } else {
            $query->orderBy($sort[0], $sort[1]);
        }
        $users = $query->orderByDesc('id')->paginate((int) ($data['per_page'] ?? 20));

        $me = auth()->user();
        $users->getCollection()->transform(fn (User $user) => [
            'uuid' => $user->uuid,
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => $user->avatar,
            'role' => $user->role,
            'examples_count' => $user->examples_count,
            'attempts_count' => $user->attempts_count,
            'created_at' => $user->created_at,
            'last_seen_at' => $user->last_seen_at,
            'online' => $user->isOnline(),
            'banned' => $user->isBanned(),
            'banned_at' => $user->banned_at,
            'ban_reason' => $user->ban_reason,
            'can_create_exams' => $user->canCreateExams(),
            // Người đang xem có được thao tác với dòng này không (để giao diện ẩn / hiện nút).
            'can_moderate' => $me->canModerate($user),
            'can_change_role' => $me->isAdministrator() && !$user->isAdministrator(),
        ]);

        return response()->json([
            ...$users->toArray(),
            'facets' => [
                'total' => User::count(),
                'online' => User::online()->count(),
                'roles' => User::query()->selectRaw('role, COUNT(*) as total')->groupBy('role')->pluck('total', 'role'),
                'status' => [
                    'active' => User::whereNull('banned_at')->count(),
                    'banned' => User::whereNotNull('banned_at')->count(),
                    'restricted' => User::where('can_create_exams', false)->count(),
                ],
            ],
        ]);
    }

    /**
     * PATCH /admin/users/{user}/role — cấp role (chỉ administrator).
     * Chỉ đổi giữa manager / person; không đụng được tới administrator (kể cả chính mình).
     */
    public function updateRole(Request $request, User $user)
    {
        $data = $request->validate([
            'role' => ['required', 'string', Rule::in(User::ASSIGNABLE_ROLES)],
        ]);

        if ($user->isAdministrator()) {
            return response()->json(['message' => 'Không thể đổi role của administrator.'], 422);
        }

        $old = $user->role;
        $user->forceFill(['role' => $data['role']])->save();
        if ($old !== $user->role) {
            AdminLog::record('user.role', 'user', $user->uuid, $user->name, ['from' => $old, 'to' => $user->role]);
        }

        return response()->json([
            'uuid' => $user->uuid,
            'role' => $user->role,
        ]);
    }

    /**
     * PATCH /admin/users/{user}/status — khóa / mở khóa, cho / chặn tạo đề.
     * Body (tùy chọn từng trường): { banned: bool, reason: string|null, can_create_exams: bool }.
     * Khóa → thu hồi mọi phiên đăng nhập ngay.
     */
    public function updateStatus(Request $request, User $user)
    {
        $data = $request->validate([
            'banned' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:255'],
            'can_create_exams' => ['sometimes', 'boolean'],
        ]);
        if (!array_key_exists('banned', $data) && !array_key_exists('can_create_exams', $data)) {
            return response()->json(['message' => 'Không có thay đổi nào.'], 422);
        }
        if (!auth()->user()->canModerate($user)) {
            return response()->json(['message' => 'Bạn không có quyền thao tác với tài khoản này.'], 403);
        }

        $wasBanned = $user->isBanned();
        $couldCreate = $user->canCreateExams();
        if (array_key_exists('banned', $data)) {
            $user->forceFill([
                'banned_at' => $data['banned'] ? ($user->banned_at ?? now()) : null,
                'ban_reason' => $data['banned'] ? (trim((string) ($data['reason'] ?? '')) ?: null) : null,
            ]);
        }
        if (array_key_exists('can_create_exams', $data)) {
            $user->can_create_exams = $data['can_create_exams'];
        }
        $user->save();

        // Vừa bị khóa → đá khỏi mọi thiết bị.
        if (!$wasBanned && $user->isBanned()) {
            $user->revokeTokens();
        }

        if ($wasBanned !== $user->isBanned()) {
            AdminLog::record($user->isBanned() ? 'user.ban' : 'user.unban', 'user', $user->uuid, $user->name,
                $user->isBanned() && $user->ban_reason ? ['reason' => $user->ban_reason] : []);
        }
        if ($couldCreate !== $user->canCreateExams()) {
            AdminLog::record($user->canCreateExams() ? 'user.allow_create' : 'user.block_create', 'user', $user->uuid, $user->name);
        }

        return response()->json([
            'uuid' => $user->uuid,
            'banned' => $user->isBanned(),
            'banned_at' => $user->banned_at,
            'ban_reason' => $user->ban_reason,
            'can_create_exams' => $user->canCreateExams(),
        ]);
    }

    /**
     * GET /admin/logs — nhật ký quản trị (chỉ administrator), mới nhất trước.
     * ?search= (người làm / đối tượng) ?action= (vd user.ban, hoặc nhóm "user." / "exam.")
     * ?actor=<uuid> ?days= (7 | 30 | 90) ?per_page= (≤ 50).
     */
    public function logs(Request $request)
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'action' => ['nullable', 'string', 'max:50'],
            'actor' => ['nullable', 'uuid'],
            'days' => ['nullable', Rule::in(['7', '30', '90'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $logs = AdminLog::query()
            ->with('user:id,uuid,name,avatar,role')
            ->when(trim((string) ($data['search'] ?? '')) !== '', function ($q) use ($data) {
                $term = '%' . addcslashes(trim($data['search']), '%_\\') . '%';
                $q->where(fn ($w) => $w->where('actor_name', 'like', $term)->orWhere('target_label', 'like', $term));
            })
            ->when($data['action'] ?? null, fn ($q, $action) => str_ends_with($action, '.')
                ? $q->where('action', 'like', addcslashes($action, '%_') . '%')
                : $q->where('action', $action))
            ->when($data['actor'] ?? null, fn ($q, $uuid) => $q->whereHas('user', fn ($u) => $u->where('uuid', $uuid)))
            ->when($data['days'] ?? null, fn ($q, $days) => $q->where('created_at', '>=', now()->subDays((int) $days)))
            ->latest('created_at')
            ->orderByDesc('id')
            ->paginate((int) ($data['per_page'] ?? 30));

        $logs->getCollection()->transform(fn (AdminLog $log) => [
            'id' => $log->id,
            'action' => $log->action,
            'target_type' => $log->target_type,
            'target_id' => $log->target_id,
            'target_label' => $log->target_label,
            'meta' => $log->meta,
            'created_at' => $log->created_at,
            'actor' => [
                'uuid' => $log->user?->uuid,
                'name' => $log->user?->name ?? $log->actor_name ?? 'Hệ thống',
                'avatar' => $log->user?->avatar,
                'role' => $log->user?->role ?? $log->actor_role,
            ],
        ]);

        // Danh sách người từng thao tác (cho bộ lọc).
        $actors = AdminLog::query()->whereNotNull('user_id')->distinct()->pluck('user_id');

        return response()->json([
            ...$logs->toArray(),
            'actors' => User::whereIn('id', $actors)->orderBy('name')->get(['uuid', 'name', 'role']),
        ]);
    }
}
