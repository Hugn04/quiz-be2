<?php

namespace App\Http\Controllers;

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

        // Nếu dữ liệu là JSON hợp lệ
        if (json_last_error() === JSON_ERROR_NONE) {
            // Dữ liệu đã được giải mã thành công, tiếp tục với dữ liệu này
        } else {
            // Nếu không phải là JSON hợp lệ, kiểm tra xem dữ liệu có phải là chuỗi JSON đã stringify không
            $data = json_decode(json_encode($rawData), true); // Giải mã lại chuỗi JSON

            // Kiểm tra lại lần nữa nếu dữ liệu có hợp lệ không
            if (json_last_error() !== JSON_ERROR_NONE) {
                // Nếu không phải là JSON hợp lệ cả hai lần, trả về lỗi
                return response()->json([
                    'message' => 'Invalid JSON received',
                    'raw_data' => $rawData
                ], 400); // Mã lỗi 400 cho trường hợp lỗi
            }
        }
        $existingScore = Score::where('user_id', auth()->user()->id)
            ->where('example_id', $data['example_id'])
            ->first();

        if ($existingScore) {
            // Nếu điểm mới cao hơn, cập nhật
            if ($data['score'] > $existingScore->score) {
                $existingScore->update(['score' => $data['score']]);
            }
            return response()->json($existingScore, 200);
        }

        // Nếu chưa có, tạo mới
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

        // Lấy ra 10 điểm gần nhất của example (có thể sắp xếp theo thời gian hoặc theo điểm tùy theo yêu cầu)
        $scores = Score::with('user:id,name,avatar,email,role')
            ->where('example_id', $request->example_id)
            ->orderBy('score', 'desc')
            ->limit(10)
            ->get();

        // Lấy ra điểm của user với example đó
        $userScore = Score::with('user:id,name,avatar,role')
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
