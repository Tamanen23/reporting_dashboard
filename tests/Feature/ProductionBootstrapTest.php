<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Reports\Models\ReportDefinition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class ProductionBootstrapTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_definitions_sync_without_creating_a_user(): void
    {
        $this->artisan('reports:sync')->assertSuccessful();

        self::assertSame(5, ReportDefinition::query()->count());
        self::assertSame(0, User::query()->count());
    }

    public function test_database_seeder_does_not_create_development_user_in_testing(): void
    {
        $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

        self::assertFalse(User::query()->where('email', 'test@example.com')->exists());
        self::assertSame(5, ReportDefinition::query()->count());
    }

    public function test_initial_administrator_is_created_interactively(): void
    {
        $password = 'Strong!Reporting2026';

        $this->artisan('app:create-admin', [
            '--name' => 'Reporting Administrator',
            '--email' => 'admin@example.com',
        ])
            ->expectsQuestion('Administrator password', $password)
            ->expectsQuestion('Confirm administrator password', $password)
            ->assertSuccessful();

        $user = User::query()->where('email', 'admin@example.com')->firstOrFail();
        self::assertSame('Reporting Administrator', $user->name);
        self::assertTrue($user->is_admin);
        self::assertTrue(Hash::check($password, $user->password));
    }
}
