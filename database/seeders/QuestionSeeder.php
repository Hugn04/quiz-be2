<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class QuestionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('questions')->insert([
            'question' => '[{"name":"Phần 1","questions":[{"name":"Câu 1","answers":[{"option":"a","is_correct":false},{"option":"b","is_correct":false},{"option":"c","is_correct":true},{"option":"d","is_correct":false}]},{"name":"Câu 2","answers":[{"option":"a","is_correct":false},{"option":"b","is_correct":false},{"option":"c","is_correct":false},{"option":"d","is_correct":true}]}]}]'
        ]);
    }
}
