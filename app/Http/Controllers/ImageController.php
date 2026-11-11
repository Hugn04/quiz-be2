<?php

namespace App\Http\Controllers;

use App\Services\CloudinaryImages;
use Illuminate\Http\Request;

class ImageController extends Controller
{
    /**
     * POST /images — upload ảnh lên Cloudinary (multipart: `image`), trả về `url`.
     * Không xóa ảnh ở đây: ảnh cũ do server tự xóa khi đề / người dùng lưu ảnh mới
     * (xem CloudinaryImages). Trường `id` của bản cũ bị bỏ qua.
     */
    public function store(Request $request, CloudinaryImages $images)
    {
        $request->validate([
            'image' => ['required', 'image', 'max:10240'],
        ]);

        return response()->json(['url' => $images->upload($request->file('image'))], 201);
    }
}
