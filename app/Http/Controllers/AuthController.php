<?php

namespace App\Http\Controllers;

use App\Mail\OTPVerify;
use App\Mail\ForgotPassword;
use App\Models\User;
use Google_Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class AuthController extends Controller
{

    public function login(Request $request)
    {
        Cookie::queue(Cookie::forget(name: 'token'));
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
    public function googleLogin(Request $request)
    {
        $client = new Google_Client(['client_id' => env('GOOGLE_CLIENT_ID')]);

        $payload = $client->verifyIdToken($request->id_token);

        if (!$payload) {
            return response()->json(['message' => 'Token không hợp lệ'], 401);
        }

        $email = $payload['email'];
        $name  = $payload['name'];
        $googleId = $payload['sub'];

        $user = User::where('email', $email)->first();

        if (!$user) {
            $user = User::create([
                "name" => $name,
                "email" => $email,
                "role" => "person",
                'email_verified_at' => now(),
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }
        $user->tokens()->delete();

        $token = $user->createToken('token')->plainTextToken;
        $cookie = cookie('token', $token, 14400, '/',);
        $response = response()->json([
            'user' => $request->user(),
            'token' => $token
        ]);
        $response->withCookie($cookie);
        return $response;
    }
    public function verifyRegister(Request $request)
    {
        $data = $request->input();
        $email = $data['email'];
        $user = User::where('email', $email)->first();
        if ($user && $user->password) {
            return response()->json('Email này đã được đăng ký rồi !', 409);
        } else {
            $otp = $this->generateOTP();
            Cache::put('otp_' . $email, $otp, now()->addMinutes(5));
            Mail::to($email)->send(new OTPVerify($otp));
            return response()->json("Đã gửi email tới " . $email . ". Vui lòng kiểm tra email!");
        }
    }
    public function register(Request $request)
    {
        $data = $request->input();
        $email = $data['email'];
        $otp = $data['otp'];

        $dataOTP = $this->verifyOTP($email, $otp);
        if ($dataOTP === true) {
            $password = bcrypt($data["password"]);
            $name = $data["name"];
            $user = User::where('email', $email)->first();
            if ($user) {
                $user->update([
                    "password" => $password,
                    'email_verified_at' => now(),
                    'updated_at' => now()
                ]);
            } else {
                $user = User::create([
                    "name" => $name,
                    "email" => $email,
                    "password" => $password,
                    "role" => "person",
                    'email_verified_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }
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
    public function auth(Request $request)
    {
        return response()->json($request->user());
    }
    public function logout()
    {

        auth()->user()->tokens()->delete();

        return response()->json("Thanh cong")->withoutCookie(Cookie::forget('token'));
    }
}
