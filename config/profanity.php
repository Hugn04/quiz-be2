<?php

/**
 * Từ ngữ tục tĩu bị chặn khi tạo / sửa / nhập đề (App\Services\ProfanityFilter).
 *
 * - Khớp theo TỪ (không khớp khi nằm trong từ khác: "lồn" không chặn "lồng"), không phân biệt
 *   hoa thường, bỏ qua dấu cách / dấu chấm / gạch xen giữa các chữ ("l.ồ.n", "đ ị t").
 * - Viết KHÔNG dấu dễ trùng từ thường ("lon" = lon nước, "buoi" = buổi) → chỉ đưa vào danh sách
 *   không dấu những cụm / viết tắt không gây nhầm.
 * Thêm từ: ghi chữ thường, có dấu đầy đủ.
 */
return [
    'words' => [
        // Tiếng Việt (có dấu)
        // ("súc vật", "điếm" bỏ ra: trùng từ thường / lỗi gõ "điểm" trong đề thật.)
        'lồn', 'lìn', 'buồi', 'cặc', 'cặk', 'địt', 'đụ', 'đéo', 'đếch', 'đĩ', 'cứt',
        'đm', 'đmm', 'đcm', 'đkm', 'đjt', 'vãi lồn', 'vãi cặc', 'vãi đái', 'óc chó', 'chó đẻ',
        'mẹ mày', 'con mẹ mày', 'đồ chó', 'thằng chó', 'con chó cái',

        // Không dấu — chỉ cụm / viết tắt ít nhầm
        'dit me', 'dit con me', 'dit cu', 'du ma', 'du me', 'deo me', 'vai lon', 'vai cac',
        'dcm', 'dmm', 'dkm', 'vcl', 'vkl', 'vcc', 'clgt', 'cmm', 'loz', 'lozz', 'cak', 'oc cho',

        // Tiếng Anh
        'fuck', 'fucking', 'fucker', 'motherfucker', 'shit', 'bitch', 'cunt', 'pussy', 'asshole',
    ],
];
