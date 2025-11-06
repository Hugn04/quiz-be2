<?php

namespace Database\Seeders;

use App\Models\Example;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ExampleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Example::create([
            'name' => 'Backend',
            'count_test' => 0,
            'count_like' => 0,
            'num_question' => 100,
            'sector' => 'Công nghệ thông tin',
            'credits' => 2,
            'user_id' => 1,
            'question_id' => 1,
        ]);
    }
}
