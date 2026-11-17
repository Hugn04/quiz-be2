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
            // Không nhận SVG (có thể chứa script) — chỉ ảnh bitmap thông dụng.
            'image' => ['required', 'file', 'mimes:jpg,jpeg,png,gif,webp', 'mimetypes:image/jpeg,image/png,image/gif,image/webp', 'max:10240'],
        ], [
            'image.mimes' => 'Chỉ nhận ảnh JPG, PNG, GIF hoặc WEBP.',
            'image.mimetypes' => 'Chỉ nhận ảnh JPG, PNG, GIF hoặc WEBP.',
        ]);

        return response()->json(['url' => $images->upload($request->file('image'))], 201);
    }
}
