<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\AdSync\UserGroupAdSyncJob;
use App\Models\UserGroup;

class UserGroupObserver
{
    public static bool $syncEnabled = true;

    public static function disableSync(): void
    {
        self::$syncEnabled = false;
    }

    public static function enableSync(): void
    {
        self::$syncEnabled = true;
    }

    public function created(UserGroup $group): void
    {
        if (!self::$syncEnabled) {
            return;
        }

        dispatch(UserGroupAdSyncJob::create($group->id));
    }

    public function updated(UserGroup $group): void
    {
        if (!self::$syncEnabled) {
            return;
        }

        if ($group->wasChanged(['name', 'display_name', 'type'])) {
            $oldName = (string) $group->getOriginal('name');
            dispatch(UserGroupAdSyncJob::update($group->id, $oldName));
        }
    }

    public function deleting(UserGroup $group): void
    {
        if (!self::$syncEnabled) {
            return;
        }

        dispatch(UserGroupAdSyncJob::delete($group->name));
    }
}
