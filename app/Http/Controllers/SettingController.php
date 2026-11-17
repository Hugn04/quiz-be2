<?php

namespace App\Http\Controllers;

use App\Services\CloudinaryImages;
use App\Services\SiteSettings;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function __construct(private SiteSettings $settings)
    {
    }

    /** GET /settings — cấu hình giao diện (công khai, frontend dùng cho toàn site). */
    public function show()
    {
        return response()->json($this->settings->appearance());
    }

    /**
     * PUT /admin/settings — sửa cấu hình giao diện (chỉ administrator). Gửi trường nào sửa
     * trường đó; null = về mặc định. Ảnh phải là ảnh đã upload lên Cloudinary của app
     * (POST /images) để không trỏ ra trang ngoài.
     */
    public function update(Request $request, CloudinaryImages $images)
    {
        $imageRule = ['sometimes', 'nullable', 'string', 'max:2048', function ($attribute, $value, $fail) use ($images) {
            if ($value !== null && $value !== '' && $images->publicId($value) === null) {
                $fail('Ảnh phải được tải lên qua trang quản trị.');
            }
        }];

        $data = $request->validate([
            'primary_color' => ['sometimes', 'nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'logo' => $imageRule,
            'default_card' => $imageRule,
            'default_avatar' => $imageRule,
            'default_banner' => $imageRule,
            'og_image' => $imageRule,
            // 'none' = không dùng khung; null = khung mặc định; còn lại = ảnh tự tải lên.
            'avatar_frame' => ['sometimes', 'nullable', 'string', 'max:2048', function ($attribute, $value, $fail) use ($images) {
                if ($value !== null && $value !== '' && $value !== SiteSettings::NO_FRAME && $images->publicId($value) === null) {
                    $fail('Ảnh phải được tải lên qua trang quản trị.');
                }
            }],
            'site_name' => ['sometimes', 'nullable', 'string', 'max:60'],
            'site_description' => ['sometimes', 'nullable', 'string', 'max:300'],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:120'],
            'facebook_url' => ['sometimes', 'nullable', 'url:https', 'max:255'],
            'youtube_url' => ['sometimes', 'nullable', 'url:https', 'max:255'],
            'tiktok_url' => ['sometimes', 'nullable', 'url:https', 'max:255'],
            'announcement' => ['sometimes', 'nullable', 'array'],
            'announcement.enabled' => ['required_with:announcement', 'boolean'],
            'announcement.message' => ['required_if:announcement.enabled,true', 'nullable', 'string', 'max:300'],
            'announcement.link' => ['nullable', 'string', 'max:255', 'regex:~^(https://|/(?!/))~'],
            'announcement.tone' => ['nullable', 'in:info,success,warning,danger'],
            'announcement.starts_at' => ['nullable', 'date'],
            'announcement.ends_at' => ['nullable', 'date', 'after:announcement.starts_at'],
            'exam_approval' => ['sometimes', 'boolean'],
        ], [
            'primary_color.regex' => 'Màu phải có dạng #rrggbb.',
            '*.url' => 'Link phải bắt đầu bằng https://',
            'contact_email.email' => 'Email liên hệ không hợp lệ.',
            'announcement.message.required_if' => 'Nhập nội dung thông báo.',
            'announcement.link.regex' => 'Link phải bắt đầu bằng https:// hoặc /',
            'announcement.ends_at.after' => 'Thời gian kết thúc phải sau thời gian bắt đầu.',
        ]);

        if (array_key_exists('announcement', $data) && is_array($data['announcement'])) {
            $a = $data['announcement'];
            $data['announcement'] = [
                'enabled' => (bool) $a['enabled'],
                'message' => trim((string) ($a['message'] ?? '')) ?: null,
                'link' => ($a['link'] ?? null) ?: null,
                'tone' => $a['tone'] ?? 'info',
                'starts_at' => ($a['starts_at'] ?? null) ? \Illuminate\Support\Carbon::parse($a['starts_at'])->toIso8601String() : null,
                'ends_at' => ($a['ends_at'] ?? null) ? \Illuminate\Support\Carbon::parse($a['ends_at'])->toIso8601String() : null,
                // id đổi mỗi lần sửa → người đã tắt thông báo cũ vẫn thấy thông báo mới.
                'id' => substr(md5(json_encode($a) . microtime()), 0, 10),
            ];
        }
        foreach (['site_name', 'site_description'] as $field) {
            if (array_key_exists($field, $data) && is_string($data[$field])) {
                $data[$field] = trim($data[$field]);
            }
        }

        if ($data === []) {
            return response()->json(['message' => 'Không có thay đổi nào.'], 422);
        }

        $before = $this->settings->appearance();
        $after = $this->settings->updateAppearance($data);
        $changed = array_keys(array_filter($data, fn ($v, $k) => ($before[$k] ?? null) !== ($after[$k] ?? null), ARRAY_FILTER_USE_BOTH));
        if ($changed) {
            \App\Models\AdminLog::record('settings.update', 'settings', null, 'Giao diện', ['fields' => $changed]);
        }

        return response()->json($after);
    }
}
