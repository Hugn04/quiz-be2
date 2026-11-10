<?php

namespace App\Http\Controllers;

use App\Models\Example;
use App\Models\Score;
use App\Models\User;
use Illuminate\Http\Request;

class ScoreController extends Controller
{
    /**
     * POST /examples/{example}/scores — ghi điểm sau khi làm bài (giữ điểm cao nhất).
     * Frontend gửi bằng navigator.sendBeacon (body JSON dạng text) → tự giải mã body thô.
     */
    public function store(Request $request, Example $example)
    {
        $data = json_decode($request->getContent(), true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            $data = $request->all();
        }
        if (!isset($data['score'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'No valid input data provided',
            ], 422);
        }

        $example->increment('count_test');
        $score = $data['score'];
        $existingScore = Score::where('user_id', auth()->id())
            ->where('example_id', $example->id)
            ->first();

        if ($existingScore) {
            if ($score > $existingScore->score) {
                $existingScore->update(['score' => $score]);
            }
            return response()->json($existingScore);
        }

        $newScore = Score::create([
            'user_id' => auth()->id(),
            'example_id' => $example->id,
            'score' => $score,
        ]);

        return response()->json($newScore, 201);
    }

    /** GET /examples/{example}/scores — top 10 điểm + điểm/thứ hạng của tôi. */
    public function index(Example $example)
    {
        $scores = Score::with('user:id,slug,name,avatar,email,role')
            ->where('example_id', $example->id)
            ->orderBy('score', 'desc')
            ->limit(10)
            ->get();

        $userScore = Score::with('user')
            ->where('example_id', $example->id)
            ->where('user_id', auth()->id())
            ->first();
        if (!$userScore) {
            $userScore = (object) [
                'user' => User::select('slug', 'name', 'avatar', 'role')->find(auth()->id()),
            ];
        } else {
            $userScore->ranking = Score::where('example_id', $example->id)
                ->where('score', '>', $userScore->score)
                ->count() + 1;
        }

        return response()->json([
            'scores' => $scores,
            'user_score' => $userScore,
        ]);
    }
}
