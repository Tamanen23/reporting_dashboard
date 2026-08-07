<?php

use App\Domain\Reports\Contracts\ReportDefinitionRegistry;
use App\Domain\Reports\Models\ReportGeneration;
use App\Domain\Reports\Services\ReportDeletionService;
use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('reports:sync', function (ReportDefinitionRegistry $registry): int {
    $registry->syncManifests();
    $this->info('Report definitions synchronized.');

    return self::SUCCESS;
})->purpose('Synchronize configured report definitions without creating users');

Artisan::command('reports:backfill-dependencies', function (): int {
    $created = 0;
    ReportGeneration::withTrashed()
        ->whereHas('reportDefinition', fn ($query) => $query->where('code', 'overall_performance_dashboard'))
        ->orderBy('id')
        ->chunkById(100, function ($reports) use (&$created): void {
            foreach ($reports as $report) {
                foreach (($report->processing_metadata['dependencies'] ?? []) as $key => $dependency) {
                    $sourceId = $dependency['generation_id'] ?? null;
                    if (! $sourceId) {
                        continue;
                    }
                    $record = $report->dependencies()->firstOrCreate([
                        'depends_on_generation_id' => $sourceId,
                    ], ['dependency_key' => $key]);
                    $created += $record->wasRecentlyCreated ? 1 : 0;
                }
            }
        });
    $this->info("{$created} report dependencies created.");

    return self::SUCCESS;
})->purpose('Backfill dependency records for existing Overall Performance reports');

Artisan::command('reports:purge-expired', function (ReportDeletionService $service): int {
    $count = $service->purgeExpired();
    $this->info("{$count} expired deleted reports permanently purged.");

    return self::SUCCESS;
})->purpose('Permanently purge reports after their recycle-bin retention period');

Schedule::command('reports:purge-expired')->dailyAt('02:30')->withoutOverlapping();

Artisan::command('app:create-admin {--name=} {--email=}', function (): int {
    $name = trim((string) ($this->option('name') ?: $this->ask('Administrator name')));
    $email = trim((string) ($this->option('email') ?: $this->ask('Administrator email')));

    if (User::query()->where('email', $email)->exists()) {
        $this->error('A user with that email address already exists.');

        return self::FAILURE;
    }

    $password = (string) $this->secret('Administrator password');
    $confirmation = (string) $this->secret('Confirm administrator password');
    $validator = Validator::make(
        compact('name', 'email', 'password', 'confirmation'),
        [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', Password::min(12)->mixedCase()->numbers()->symbols()],
            'confirmation' => ['same:password'],
        ],
    );

    if ($validator->fails()) {
        foreach ($validator->errors()->all() as $message) {
            $this->error($message);
        }

        return self::FAILURE;
    }

    User::query()->create([
        'name' => $name,
        'email' => $email,
        'password' => $password,
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);
    $this->info("Administrator {$email} created.");

    return self::SUCCESS;
})->purpose('Interactively create the initial production administrator');
