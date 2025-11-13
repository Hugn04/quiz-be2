<?php

namespace App\Http\Controllers;

use App\Models\Example;
use App\Models\UserAction;
use Cloudinary\Cloudinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ExampleController extends Controller
{
    public function createExample(Request $request)
    {
        $data = $request->input();

        $questionId = DB::table('questions')->insertGetId([
            'question' => $data['question'],
        ]);
        DB::table('examples')->insert([
            'name' => $data['name'],
            'count_test' => 0,
            'count_like' => 0,
            'num_question' => $data['count_question'],
            'credits' => $data['credits'],
            'sector_id' => $data['sector'],
            'question_id' => $questionId,
            'user_id' => auth()->user()->id,
            'created_at' => now(),
            'updated_at' => now()

        ]);
        return $data;
    }
    public function updateMyExample(Request $request)
    {

        $id = $request->input("id");
        if (!$this->isMyExample(id: $id)) {
            return response()->json("Bạn không có quyên vào đề này", 403);
        }
        $sector = $request->input("sector");
        $credits = $request->input("credits");
        $image = $request->input("image");
        Log::debug($image);
        $name = $request->input("name");
        $question = $request->input('question');
        $count_question = $request->input("count_question");

        $example = Example::with(['user', 'question'])->findOrFail($id);

        $example->update([
            'num_question' => $count_question,
            'name' => $name,
            'credits' => $credits,
            'image' => $image,
            'sector_id' => $sector,
        ]);
        if ($example->question) {
            $example->question->update([
                'question' => $question,
            ]);
        }
        return response()->json($example);
    }
    public function deleteExapmle(Request $request)
    {
        $id = $request->input("id");
        if (!$this->isMyExample(id: $id)) {
            return response()->json("Bạn không có quyên xóa đề này", 403);
        }

        $example = DB::table('examples')
            ->where('id', $id);
        $url = $example->get('image')[0]->image;
        $public_id = pathinfo(basename($url), PATHINFO_FILENAME);
        if ($public_id) {
            cloudinary()->destroy('img_quiz/' . $public_id);
        }
        $idQuestion = $example->get()[0]->question_id;
        $example->delete();
        DB::table('questions')->where('id', $idQuestion)->delete(); //->delete();

        return response()->json('Bạn đã xóa thành công');
    }
    public function getAllExample(Request $request)
    {
        $perPage = $request->query('per_page', 9);
        $result = Example::filter($request->all())->withAll()
            ->paginate($perPage);

        return response()->json($result);
    }
    public function getQuestion(Request $request)
    {
        $id = $request->input('id');
        $result = Example::where('id', $id)->with(["userAction", "user", "question"])->first();
        $data = $result->toArray();
        if (isset($data['question']['question'])) {
            $data['question'] = $data['question']['question'];
        }
        return response()->json($data);
    }
    private function isMyExample($id)
    {
        $userId = Example::where('id', $id)->first('user_id')->user_id;
        if (auth()->user()->id != $userId) {
            return false;
        }
        return true;
    }
    public function getMyQuestion(Request $request)
    {
        $idExample = $request->input('id');
        if (!$this->isMyExample($idExample)) {
            return response()->json("Bạn không có quyên vào đề này", 403);
        }
        $question = Example::where("id", $idExample)->withAll()->with('question')->first();
        $question = $question->toArray();
        if (isset($question['question']['question'])) {
            $question['question'] = $question['question']['question'];
        }
        return response()->json($question);
    }
    public function getMyExample(Request $request)
    {
        $perPage = $request->query('per_page', 9);
        $id = auth()->user()->id;
        $examples = Example::where("user_id", $id)->filter($request->all())->withAll()
            ->paginate($perPage);
        return response()->json($examples);
    }
    public function getMyFavorite(Request $request)
    {
        $perPage = $request->query('per_page', 9);
        $query = $request->input('query');
        $id = auth()->user()->id;


        $examples = DB::table('user_actions')
            ->join('examples', 'examples.id', '=', 'user_actions.example_id')
            ->join('users', 'users.id', '=', 'examples.user_id')
            ->select('examples.*', 'users.email', 'users.avatar', 'users.role', 'user_actions.like', 'user_actions.favorited', 'users.name as username')
            ->where([['user_actions.favorited', '=', true], ['examples.name', 'like', '%' . $query  . '%'], ['user_actions.user_id', $id]])
            ->paginate(9);
        return response()->json($examples);
    }



    public function uploadImage(Request $request)
    {
        $id = $request->input('id');
        $image = $request->file('image');
        $uploadedFileUrl = '';
        if ($image) {
            $file = $image->getRealPath();
            $uploadedFileUrl = cloudinary()->upload($file, [
                'folder' => env('CLOUDINARY_FOLDER', 'test')
            ])->getSecurePath();
        }
        if ($id) {
            $response = cloudinary()->destroy(env('CLOUDINARY_FOLDER', 'test') . '/' . $id,);
        }

        return response()->json([
            'url' => $uploadedFileUrl,
        ]);
    }


    public function updateLike(Request $request)
    {
        $request->validate([
            'example_id' => 'required|exists:examples,id',
            'like' => 'required|boolean'
        ]);

        $existingAction = UserAction::where([['user_id', auth()->user()->id], ['example_id', $request->example_id]])
            ->first();
        $example = DB::table('examples')->where('id', $request->example_id);
        if ($request->like) {
            $example->increment('count_like');
        } else {
            $example->decrement('count_like');
        }


        if ($existingAction) {
            $existingAction->update([
                "like" => $request->like,
            ]);
        } else {

            UserAction::create([
                'user_id' => auth()->user()->id,
                'example_id' => $request->example_id,
                'like' => $request->like,
                'favorited' => false
            ]);
        }

        return response()->json([
            'count_like' => $example->first('count_like')->count_like,
            'like' => $request->like
        ], 201);
    }

    public function updateFavorite(Request $request)
    {
        $request->validate([
            'example_id' => 'required|exists:examples,id',
            'favorited' => 'required|boolean'
        ]);

        $existingAction = UserAction::where([['user_id', auth()->user()->id], ['example_id', $request->example_id]])
            ->first();
        if ($existingAction) {
            $existingAction->update([
                "favorited" => $request->favorited,
            ]);
        } else {
            UserAction::create([
                'user_id' => auth()->user()->id,
                'example_id' => $request->example_id,
                'like' => false,
                'favorited' => $request->favorited,
            ]);
        }


        return response()->json($request->favorited, 201);
    }

    public function getExampleFromQuiz(Request $request)
    {
        if (auth()->user()->role != 'admin') {
            return response()->json("Bạn không có quyền này !", 400);
        }

        $result = [];
        try {
            $params = $request->input('tagExample');
            $type = $request->input('type');
            $response = Http::withHeaders([
                'Authorization' => 'Bearer 892475|ZdHRPg6gPE3LsJXuLeLIoTtetrwlEZkH9ZLEdxGG',
                'Accept' => 'application/json',
            ])->get('https://studio-gw-01.eduquiz.io.vn/quiz-chanel/public/api/v1/exams/' . $params . '/sections?bizapp=' . $type . '&is_question_shuffle=0&is_answer_shuffle=0&allow_access_exam=true');
            $data = $response['data'];
            foreach ($data as $key => $part) {
                $objParts = ['name' => $part['name'], 'questions' => []];
                $questions = $part['questions'];
                foreach ($questions as $key => $question) {

                    $objQuestion = ['name' => $question['name'], 'img' => $question['files'], 'answers' => []];
                    $answers = $question['answers'];
                    foreach ($answers as $key => $answer) {
                        $objAnswer = ['option' => $answer['option'], 'is_correct' => $answer['is_correct']];
                        array_push($objQuestion['answers'], $objAnswer);
                    }
                    array_push($objParts['questions'], $objQuestion);
                }
                array_push($result, $objParts);
            }
            if ($response->successful()) {
                return response()->json($result);
            } else {
                return response("lỗi http");
            }
        } catch (\Throwable $th) {
            return response("Đề thi bị lỗi", 400);
        }
    }
}

//https://studio-gw.eduquiz.io.vn/quiz-chanel/public/api/v1/exams/13995/sections?bizapp=EDUQUIZ&is_question_shuffle=0&is_answer_shuffle=0&allow_access_exam=true
//https://studio-gw.eduquiz.io.vn/quiz-chanel/public/api/v1/exams/13995/sections?bizapp=EDUQUIZ&is_question_shuffle=0&is_answer_shuffle=0&allow_access_exam=true
