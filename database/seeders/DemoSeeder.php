<?php

namespace Database\Seeders;

use App\Models\Example;
use App\Models\Question;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Dữ liệu demo để thử phân trang / bộ lọc: 6 user demo, thêm ngành, ~45 đề (kèm phần, câu, đáp án).
 *
 *   php artisan db:seed --class=DemoSeeder
 *
 * Mọi đề demo thuộc user email `demo*@hugnquiz.test` → xoá user demo là xoá dây chuyền đề:
 *   User::where('email', 'like', 'demo%@hugnquiz.test')->delete();
 */
class DemoSeeder extends Seeder
{
    private const SECTORS = [
        'Công nghệ thông tin', 'Quản trị kinh doanh', 'Kế toán', 'Tài chính ngân hàng',
        'Ngôn ngữ Anh', 'Kinh tế', 'Luật kinh tế', 'Marketing', 'Du lịch', 'Kiến trúc',
        'Điện tử viễn thông', 'Thương mại điện tử', 'Logistics', 'Thiết kế đồ hoạ',
    ];

    /** [tên đề, ngành, tín chỉ] */
    private const SUBJECTS = [
        ['Cấu trúc dữ liệu và giải thuật', 'Công nghệ thông tin', 3],
        ['Lập trình hướng đối tượng', 'Công nghệ thông tin', 3],
        ['Cơ sở dữ liệu', 'Công nghệ thông tin', 3],
        ['Mạng máy tính', 'Công nghệ thông tin', 3],
        ['Hệ điều hành', 'Công nghệ thông tin', 3],
        ['Lập trình Web', 'Công nghệ thông tin', 3],
        ['Trí tuệ nhân tạo', 'Công nghệ thông tin', 3],
        ['An toàn thông tin', 'Công nghệ thông tin', 2],
        ['Kiểm thử phần mềm', 'Công nghệ thông tin', 2],
        ['Phân tích thiết kế hệ thống', 'Công nghệ thông tin', 3],
        ['Quản trị học', 'Quản trị kinh doanh', 3],
        ['Quản trị nhân lực', 'Quản trị kinh doanh', 3],
        ['Quản trị chiến lược', 'Quản trị kinh doanh', 3],
        ['Hành vi tổ chức', 'Quản trị kinh doanh', 2],
        ['Nguyên lý kế toán', 'Kế toán', 3],
        ['Kế toán tài chính 1', 'Kế toán', 3],
        ['Kiểm toán căn bản', 'Kế toán', 3],
        ['Thuế', 'Kế toán', 2],
        ['Tài chính doanh nghiệp', 'Tài chính ngân hàng', 3],
        ['Tiền tệ ngân hàng', 'Tài chính ngân hàng', 3],
        ['Thị trường chứng khoán', 'Tài chính ngân hàng', 2],
        ['Tiếng Anh cơ bản 1', 'Ngôn ngữ Anh', 3],
        ['Tiếng Anh chuyên ngành', 'Ngôn ngữ Anh', 2],
        ['Ngữ pháp tiếng Anh nâng cao', 'Ngôn ngữ Anh', 3],
        ['Kinh tế vi mô', 'Kinh tế', 3],
        ['Kinh tế vĩ mô', 'Kinh tế', 3],
        ['Kinh tế lượng', 'Kinh tế', 3],
        ['Pháp luật đại cương', 'Luật kinh tế', 2],
        ['Luật doanh nghiệp', 'Luật kinh tế', 3],
        ['Marketing căn bản', 'Marketing', 3],
        ['Nghiên cứu thị trường', 'Marketing', 3],
        ['Digital Marketing', 'Marketing', 2],
        ['Tổng quan du lịch', 'Du lịch', 2],
        ['Quản trị lữ hành', 'Du lịch', 3],
        ['Lịch sử kiến trúc', 'Kiến trúc', 2],
        ['Vẽ kỹ thuật', 'Kiến trúc', 2],
        ['Kỹ thuật điện tử số', 'Điện tử viễn thông', 3],
        ['Xử lý tín hiệu số', 'Điện tử viễn thông', 3],
        ['Thương mại điện tử căn bản', 'Thương mại điện tử', 3],
        ['Thanh toán điện tử', 'Thương mại điện tử', 2],
        ['Quản trị chuỗi cung ứng', 'Logistics', 3],
        ['Vận tải đa phương thức', 'Logistics', 2],
        ['Nguyên lý thiết kế đồ hoạ', 'Thiết kế đồ hoạ', 3],
        ['Triết học Mác - Lênin', 'Kinh tế', 3],
        ['Tư tưởng Hồ Chí Minh', 'Kinh tế', 2],
    ];

    private const NAMES = [
        'Trần Minh Anh', 'Lê Thu Hà', 'Phạm Quốc Bảo', 'Hoàng Gia Linh', 'Vũ Đức Thắng', 'Đỗ Ngọc Mai',
    ];

    public function run(): void
    {
        DB::transaction(function () {
            $users = collect(self::NAMES)->map(fn ($name, $i) => User::firstOrCreate(
                ['email' => 'demo' . ($i + 1) . '@hugnquiz.test'],
                [
                    'name' => $name,
                    'role' => 'person',
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                ],
            ));

            $sectors = collect(self::SECTORS)->mapWithKeys(function ($name) {
                $sector = Sector::firstOrNew(['name' => $name]);
                if (! $sector->exists) {
                    // Bảng sectors không có created_at / updated_at.
                    $sector->timestamps = false;
                    $sector->save();
                }

                return [$name => $sector];
            });

            foreach (self::SUBJECTS as $i => [$name, $sectorName, $credits]) {
                $this->createExample(
                    $name,
                    $sectors[$sectorName]->id,
                    $credits,
                    $users[$i % $users->count()]->id,
                    now()->subDays(random_int(0, 180))->subMinutes(random_int(0, 1440)),
                );
            }
        });
    }

    private function createExample(string $name, int $sectorId, int $credits, int $userId, $createdAt): void
    {
        $example = new Example();
        // user_id / credits không nằm trong $fillable.
        $example->forceFill([
            'name' => $name,
            'sector_id' => $sectorId,
            'credits' => $credits,
            'user_id' => $userId,
            'count_test' => random_int(0, 500),
            'count_like' => random_int(0, 80),
            'num_question' => 0,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();

        $total = 0;
        $partCount = random_int(1, 3);
        for ($p = 0; $p < $partCount; $p++) {
            $part = $example->parts()->create(['name' => 'Phần ' . ($p + 1), 'position' => $p]);
            $questionCount = random_int(4, 12);
            for ($q = 0; $q < $questionCount; $q++) {
                $total++;
                $question = $part->questions()->create([
                    'type' => Question::TYPE_SINGLE_CHOICE,
                    'content' => "Câu {$total}: Nội dung câu hỏi mẫu của môn {$name}?",
                    'position' => $q,
                ]);
                $correct = random_int(0, 3);
                foreach (['A', 'B', 'C', 'D'] as $a => $label) {
                    $question->answers()->create([
                        'content' => "Phương án {$label}",
                        'is_correct' => $a === $correct,
                        'position' => $a,
                    ]);
                }
            }
        }

        $example->forceFill(['num_question' => $total])->save();
    }
}
