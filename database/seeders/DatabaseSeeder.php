<?php

namespace Database\Seeders;

use App\Domain\Reports\Contracts\ReportDefinitionRegistry;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        if (app()->environment('local')) {
            User::query()->updateOrCreate(
                ['email' => 'test@example.com'],
                ['name' => 'Test User', 'password' => 'password'],
            );
        }

        app(ReportDefinitionRegistry::class)->syncManifests();
    }
}
