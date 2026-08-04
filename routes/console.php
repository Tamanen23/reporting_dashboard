<?php

use App\Domain\Reports\Contracts\ReportDefinitionRegistry;
use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
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
