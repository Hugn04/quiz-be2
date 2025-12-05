<?php

namespace Database\Seeders;

use App\Models\Example;
use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();
        // $this->call(UserSeeder::class);
        // $this->call(QuestionSeeder::class);
        $this->call(SectorSeeder::class);
        // $this->call(ExampleSeeder::class);
        // $this->call(UserActionSeeder::class);
    }
}
