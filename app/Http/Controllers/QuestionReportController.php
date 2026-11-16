<?php

namespace App\Http\Controllers;

use App\Models\AdminLog;
use App\Models\Example;
use App\Models\Question;
use App\Models\QuestionReport;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class QuestionReportController extends Controller
{
    /**
     * POST /examples/{example}/reports — người dùng báo lỗi 1 câu.
     * Body { question_id, reason, message? }. Một người chỉ có 1 báo cáo đang mở / câu.
     */
    public function store(Request $request, Example $example)
    {
        abort_unless($example->viewableBy(auth('api')->user()), 404);
        $data = $request->validate([
            'question_id' => ['required', 'integer'],
            'reason' => ['required', Rule::in(QuestionReport::REASONS)],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        $question = Question::query()
            ->whereKey($data['question_id'])
            ->whereHas('part', fn ($q) => $q->where('example_id', $example->id))
            ->first();
        if (!$question) {
            return response()->json(['message' => 'Câu hỏi không thuộc đề này.'], 422);
        }

        $userId = auth('api')->id();
        $duplicate = QuestionReport::where('question_id', $question->id)
            ->where('user_id', $userId)->where('status', 'open')->exists();
        if ($duplicate) {
            return response()->json(['message' => 'Bạn đã báo lỗi câu này, quản trị đang xem xét.'], 409);
        }

        // Số thứ tự câu trong cả đề (theo phần rồi theo vị trí).
        $ordered = $example->questions()->reorder()->orderBy('parts.position')->orderBy('questions.position')
            ->orderBy('questions.id')->pluck('questions.id');
        $position = $ordered->search($question->id);

        $report = QuestionReport::create([
            'example_id' => $example->id,
            'question_id' => $question->id,
            'user_id' => $userId,
            'question_position' => $position === false ? null : $position + 1,
            'question_content' => mb_substr(trim(strip_tags((string) $question->content)), 0, 2000),
            'reason' => $data['reason'],
            'message' => trim((string) ($data['message'] ?? '')) ?: null,
        ]);

        return response()->json(['id' => $report->id, 'message' => 'Đã gửi báo lỗi, cảm ơn bạn!'], 201);
    }

    /** GET /admin/reports — ?status=open|resolved|dismissed ?reason= ?search= ?per_page= */
    public function index(Request $request)
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(QuestionReport::STATUSES)],
            'reason' => ['nullable', Rule::in(QuestionReport::REASONS)],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $reports = QuestionReport::query()
            ->with(['example:id,slug,name,user_id', 'example.user:id,uuid,name', 'user:id,uuid,name,avatar', 'handler:id,uuid,name'])
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($data['reason'] ?? null, fn ($q, $r) => $q->where('reason', $r))
            ->when(trim((string) ($data['search'] ?? '')) !== '', function ($q) use ($data) {
                $term = '%' . addcslashes(trim($data['search']), '%_\\') . '%';
                $q->where(fn ($w) => $w->where('question_content', 'like', $term)
                    ->orWhere('message', 'like', $term)
                    ->orWhereHas('example', fn ($e) => $e->where('name', 'like', $term)));
            })
            ->latest()
            ->orderByDesc('id')
            ->paginate((int) ($data['per_page'] ?? 20));

        return response()->json([
            ...$reports->toArray(),
            'counts' => QuestionReport::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
        ]);
    }

    /** GET /admin/reports/count — số báo lỗi đang mở (hiện trên menu). */
    public function openCount()
    {
        return response()->json(['open' => QuestionReport::where('status', 'open')->count()]);
    }

    /** PATCH /admin/reports/{report} — Body { status: open|resolved|dismissed, note? } */
    public function update(Request $request, QuestionReport $report)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(QuestionReport::STATUSES)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $open = $data['status'] === 'open';
        $report->update([
            'status' => $data['status'],
            'handled_by' => $open ? null : auth('api')->id(),
            'handled_at' => $open ? null : now(),
            'handle_note' => $open ? null : (trim((string) ($data['note'] ?? '')) ?: null),
        ]);

        if (!$open) {
            $example = $report->example;
            AdminLog::record($data['status'] === 'resolved' ? 'report.resolve' : 'report.dismiss', 'report',
                (string) $report->id, $example?->name, array_filter([
                    'question' => $report->question_position,
                    'note' => $report->handle_note,
                ]));
        }

        return response()->json($report->fresh(['example:id,slug,name,user_id', 'example.user:id,uuid,name', 'user:id,uuid,name,avatar', 'handler:id,uuid,name']));
    }
}
