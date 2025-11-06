<?php

namespace App\Http\Controllers;

use App\Mail\OTPVerify;
use App\Mail\ForgotPassword;
use App\Models\Example;
use App\Models\User;
use Google_Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class UserController extends Controller
{
    public function getProfile(Request $request)
    {
        $id = $request->input('id');
        $email = $request->input('email');
        if (!$id && $email == 'this') {
            $id = auth()->user()->id;
            $email = auth()->user()->email;
        }
        // $user = User::where([['id', $id], ['email', 'like', '%' . $email  . '%']])
        //     ->first(['name', 'avatar', 'banner', 'id', 'role']);

        // $examples  = DB::table('examples')
        //     ->join('users', 'examples.user_id', '=', 'users.id')->leftJoin('user_actions', function ($join) {
        //         $join->on('examples.id', '=', 'user_actions.example_id')
        //             ->where('user_actions.user_id', '=', auth('sanctum')->user()->id);
        //     })->select(
        //         'examples.*',
        //         'user_actions.like',
        //         'user_actions.favorited',
        //         'users.name as username',
        //         'users.avatar',
        //         'users.email',
        //         'users.role'
        //     )
        //     ->where('users.id', '=', $id)
        //     ->limit(4)->get();
        // $user = $user->examples()
        // $user = User::with(['examples.userAction'])
        //     ->where('id', $id)
        //     ->first();
        // $ex = Example::query()
        //     ->with('user')
        //     ->leftJoin('user_actions', function ($join) {
        //         $join->on('examples.id', '=', 'user_actions.example_id')
        //             ->where('user_actions.user_id', auth('sanctum')->id());
        //     })
        //     ->select('examples.*', 'user_actions.*') // hoặc custom field cụ thể
        //     ->get();
        $user = Example::with(['user', 'userAction'])
            ->limit(4)
            ->get();
        if ($user) {
            return response()->json($user);
        } else {
            return response()->json('Không có người dùng này', 404);
        }
    }
    public function updateProfile(Request $request)
    {
        $data = $request->input();
        $user = DB::table('users')->where('id', auth()->user()->id)->update($data);
        return response()->json($user);
    }
    public function auth(Request $request)
    {
        return response()->json($request->user());
    }

    public function getUsers(Request $request)
    {
        // Lấy danh sách ID từ request
        $ids = $request->input('ids');

        if (!is_array($ids) || empty($ids)) {
            return response()->json(['message' => 'Invalid request'], 400);
        }


        $users = User::whereIn('id', $ids)->select('id', 'name', 'avatar')->get();

        return response()->json($users);
    }
}
