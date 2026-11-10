<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ImageController extends Controller
{
    /**
     * POST /images — upload ảnh lên Cloudinary (multipart: `image`).
     * Gửi kèm `id` (public id ảnh cũ) để xóa ảnh cũ khi thay ảnh.
     */
    public function store(Request $request)
    {
        $folder = env('CLOUDINARY_FOLDER', 'test');
        $image = $request->file('image');
        $url = '';
        if ($image) {
            $url = cloudinary()->upload($image->getRealPath(), [
                'folder' => $folder,
            ])->getSecurePath();
        }
        if ($id = $request->input('id')) {
            cloudinary()->destroy($folder . '/' . $id);
        }

        return response()->json(['url' => $url], $image ? 201 : 200);
    }
}
