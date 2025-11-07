<?php

namespace App\Http\Controllers;

use App\Models\Example;
use App\Models\Score;
use App\Models\User;
use Illuminate\Http\Request;

class ScoreController extends Controller
{
    public function updateScore(Request $request)
    {
        $rawData = $request->getContent();

        // Kiểm tra và giải mã dữ liệu
        $data = json_decode($rawData, true);
        if (json_last_error() !== JSON_ERROR_NONE || $data === null) {
            $data = $request->all();
        }

        if (empty($data)) {
            return response()->json([
                'status' => 'error',
                'message' => 'No valid input data provided'
            ], 400);
        }
        $example_id = $data['example_id'];
        Example::where('id', $example_id)->first()->increment('count_test');
        $existingScore = Score::where('user_id', auth()->user()->id)
            ->where('example_id', $data['example_id'])
            ->first();
        if ($existingScore) {
            $score = $data['score'];
            // Nếu điểm mới cao hơn, cập nhật
            if ($score > $existingScore->score) {
                $existingScore->update(['score' => $score]);
            }
            return response()->json($existingScore, 200);
        }
        $newScore = Score::create(array_merge(
            $data,
            ['user_id' => auth()->user()->id]
        ));

        return response()->json($newScore, 201);
    }

    public function getScores(Request $request)
    {
        // Validate dữ liệu truyền vào
        $request->validate([
            'example_id' => 'required|exists:examples,id',
        ]);
        // return response()->json([
        //     'scores'     => $scores,
        // ], 200);
        // Lấy ra 10 điểm gần nhất của example (có thể sắp xếp theo thời gian hoặc theo điểm tùy theo yêu cầu)
        $scores = Score::with('user:id,name,avatar,email,role')
            ->where('example_id', $request->example_id)
            ->orderBy('score', 'desc')
            ->limit(10)
            ->get();

        // Lấy ra điểm của user với example đó
        $userScore = Score::with('user')
            ->where('example_id', $request->example_id)
            ->where('user_id', auth()->user()->id)
            ->first();
        if (!$userScore) {
            $user = User::select('name', 'avatar', 'role')->find(auth()->user()->id);
            $userScore = (object)[
                'user' => $user
            ];
        } else {
            $ranking = Score::where('example_id', $request->example_id)
                ->where('score', '>', $userScore->score)
                ->count() + 1;

            // Gắn thứ hạng vào đối tượng userScore
            $userScore->ranking = $ranking;
        }
        // Trả về dữ liệu dưới dạng JSON
        return response()->json([
            'scores'     => $scores,
            'user_score' => $userScore,
        ], 200);
    }
}
