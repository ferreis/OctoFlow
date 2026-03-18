<?php

namespace App\Task;

use App\Entity\User;

interface LocalTaskSyncInterface
{
    /**
     * @return array{synced: int, failed: int, skipped: int}
     */
    public function syncPendingTasks(User $user): array;
}
