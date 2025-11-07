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
        $user = User::where([['id', $id], ['email', 'like', '%' . $email  . '%']])->first();
        $user = $user->load([
            'examples' => function ($q) {
                $q->limit(4); // hoặc ->take(4)
            },
            'examples.userAction'
        ]);
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
