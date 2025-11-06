<?php

namespace App\Http\Controllers;

use App\Mail\OTPVerify;
use App\Mail\ForgotPassword;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

use function Laravel\Prompts\table;

class AuthController extends Controller
{

    public function login(Request $request)
    {

        Cookie::queue(Cookie::forget('token'));
        if (Auth::attempt(["email" => $request->input("email"), "password" => $request->input("password")], $request->input("remember"))) {

            auth()->user()->tokens()->delete();

            $token = $request->user()->createToken("token")->plainTextToken;
            $cookie = cookie('token', $token, 14400, '/',);
            $response = response()->json([
                'user' => $request->user(),
                'token' => $token
            ]);
            $response->withCookie($cookie);
            return $response;
        }


        return response()->json(
            [
                'message' => "Tên tài khoản hoặc mật khẩu không chính xác"
            ],
            401
        );
    }
    public function verifyRegister(Request $request)
    {
        $data = $request->input();
        $email = $data['email'];
        $account = DB::table("users")->where("email", $email)->get();
        if ($account->count()) {
            return response()->json('Email này đã được đăng ký rồi !', 409);
        } else {
            $otp = $this->generateOTP();
            Cache::put('otp_' . $email, $otp, now()->addMinutes(5));
            Mail::to($data['email'])->send(new OTPVerify($otp));

            return response()->json("");
        }
    }
    public function register(Request $request)
    {
        $data = $request->input();
        $email = $data['email'];
        $otp = $data['otp'];
        $dataOTP = $this->verifyOTP($email, $otp);
        if ($dataOTP === true) {
            DB::table("users")->insert(
                array(
                    "name" => $data["name"],
                    "email" => $data["email"],
                    "password" => bcrypt($data["password"]),
                    "avatar" => "https://th.bing.com/th/id/OIP.xyVi_Y3F3YwEIKzQm_j_jQHaHa?rs=1&pid=ImgDetMain",
                    "role" => "person",
                    'email_verified_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now()
                )
            );
            return response()->json('Tạo tài khoản thành công !');
        }
        return $dataOTP;
    }
    public function verifyForgotPassword(Request $request)
    {
        $data = $request->input();
        $email = $data['email'];
        $account = DB::table("users")->where("email", $email)->get();
        if ($account->count()) {
            $otp = $this->generateOTP();
            Cache::put('otp_' . $email, $otp, now()->addMinutes(5));
            Mail::to($data['email'])->send(new OTPVerify($otp));
            return response()->json("");
        } else {
            return response()->json('Email không tồn tại !', 409);
        }
    }
    public function forgotPassword(Request $request)
    {
        $data = $request->input();
        $email = $data['email'];
        $otp = $data['otp'];
        $dataOTP = $this->verifyOTP($email, $otp);
        if ($dataOTP === true) {
            $password = $this->generateRandomPassword(6);
            User::where('email', $email)->update([
                'password' => bcrypt($password)
            ]);
            Mail::to($data['email'])->send(new ForgotPassword($password));
            return response()->json('Mật khẩu của bạn đã được gửi đến ' . $email);
        }
        return $dataOTP;
    }

    public function changePassword(Request $request)
    {

        $oldPassword = $request->input('oldPassword');
        $newPassword = $request->input('newPassword');
        if (strlen($newPassword) >= 6 && $oldPassword) {
            $user = User::find(auth()->user()->id);
            if (Hash::check($oldPassword, $user->password)) {
                $user->update([
                    'password' => Hash::make($newPassword)
                ]);
                auth()->user()->tokens()->delete();
                return response()->json('Đổi mật khẩu thành công. Vui lòng đăng nhập lại !');
            } else {
                return response()->json('Bạn nhập lại mật khẩu cũ bị sai !', 401);
            }
        } else {
            return response()->json("Bạn chưa nhập đủ mật khẩu", 401);
        }
    }
    private function verifyOTP($email, $otp)
    {
        $cachedOtp = Cache::get('otp_' . $email);
        if (!$cachedOtp) {
            return response()->json('OTP đã hết hạn', 410);
        }
        if ($otp === $cachedOtp) {
            Cache::pull('otp_' . $email);
            return true;
        }
        return response()->json('OTP không hợp lệ vui lòng nhập lại !', 401);
    }

    function generateRandomPassword($length = 6)
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomPassword = '';

        for ($i = 0; $i < $length; $i++) {
            $randomPassword .= $characters[random_int(0, $charactersLength - 1)];
        }

        return $randomPassword;
    }
    private function generateOTP($length = 6)
    {
        $otp = '';
        for ($i = 0; $i < $length; $i++) {
            $otp .= mt_rand(0, 9);
        }
        return $otp;
    }
    public function getProfile(Request $request)
    {
        $id = $request->input('id');
        $email = $request->input('email');
        if (!$id && $email == 'this') {
            $id = auth()->user()->id;
            $email = auth()->user()->email;
        }
        $user = DB::table('users')
            ->where([['id', $id], ['email', 'like', '%' . $email  . '%']])
            ->first(['name', 'avatar', 'banner', 'id', 'role']);

        $examples  = DB::table('examples')
            ->join('users', 'examples.user_id', '=', 'users.id')->leftJoin('user_actions', function ($join) {
                $join->on('examples.id', '=', 'user_actions.example_id')
                    ->where('user_actions.user_id', '=', auth('sanctum')->user()->id);
            })->select(
                'examples.*',
                'user_actions.like',
                'user_actions.favorited',
                'users.name as username',
                'users.avatar',
                'users.email',
                'users.role'
            )
            ->where('users.id', '=', $id)
            ->limit(4)->get();
        $user->examples = $examples;
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
    public function logout()
    {

        auth()->user()->tokens()->delete();

        return response()->json("Thanh cong")->withoutCookie(Cookie::forget('token'));
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
