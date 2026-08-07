<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Reports\Models\ReportGeneration;
use App\Models\User;

final class ReportGenerationPolicy
{
    public function view(User $user, ReportGeneration $generation): bool
    {
        return $user->is_admin || $generation->user_id === $user->id;
    }

    public function delete(User $user, ReportGeneration $generation): bool
    {
        return $this->view($user, $generation);
    }

    public function restore(User $user, ReportGeneration $generation): bool
    {
        return $this->view($user, $generation);
    }

    public function cascadeDelete(User $user, ReportGeneration $generation): bool
    {
        return $user->is_admin;
    }
}
