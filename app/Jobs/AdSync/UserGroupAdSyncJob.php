<?php

declare(strict_types=1);

namespace App\Jobs\AdSync;

use App\Models\UserGroup;
use App\Services\AdSync\UserGroupAdSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UserGroupAdSyncJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;

    public const ACTION_CREATE = 'create';
    public const ACTION_UPDATE = 'update';
    public const ACTION_DELETE = 'delete';

    public function __construct(
        public int|string $groupIdentifier,
        public string $action,
        public ?string $oldName = null,
        public ?string $groupName = null,
    ) {
    }

    public static function create(int $userGroupId): self
    {
        return new self($userGroupId, self::ACTION_CREATE);
    }

    public static function update(int $userGroupId, string $oldName): self
    {
        return new self($userGroupId, self::ACTION_UPDATE, oldName: $oldName);
    }

    public static function delete(string $groupName): self
    {
        return new self($groupName, self::ACTION_DELETE, groupName: $groupName);
    }

    public function handle(UserGroupAdSyncService $syncService): void
    {
        $result = match ($this->action) {
            self::ACTION_CREATE => $this->handleCreate($syncService),
            self::ACTION_UPDATE => $this->handleUpdate($syncService),
            self::ACTION_DELETE => $this->handleDelete($syncService),
            default => ['success' => false, 'error' => "Action inconnue: {$this->action}"],
        };

        if (!$result['success']) {
            Log::error('[UserGroupAdSyncJob] Échec synchronisation', [
                'action' => $this->action,
                'identifier' => $this->groupIdentifier,
                'error' => $result['error'] ?? 'erreur inconnue',
            ]);

            throw new \RuntimeException((string) ($result['error'] ?? 'Erreur synchronisation AD'));
        }
    }

    /**
     * @return array{success:bool,error:?string}
     */
    private function handleCreate(UserGroupAdSyncService $syncService): array
    {
        $group = $this->findGroup();
        if ($group === null) {
            return ['success' => false, 'error' => 'Groupe introuvable'];
        }

        return $syncService->create($group);
    }

    /**
     * @return array{success:bool,error:?string}
     */
    private function handleUpdate(UserGroupAdSyncService $syncService): array
    {
        $group = $this->findGroup();
        if ($group === null) {
            return ['success' => false, 'error' => 'Groupe introuvable'];
        }

        $oldName = (string) ($this->oldName ?? '');
        if ($oldName === '') {
            return ['success' => false, 'error' => 'Paramètre old_name manquant'];
        }

        return $syncService->update($group, $oldName);
    }

    /**
     * @return array{success:bool,error:?string}
     */
    private function handleDelete(UserGroupAdSyncService $syncService): array
    {
        $name = (string) ($this->groupName ?? (is_string($this->groupIdentifier) ? $this->groupIdentifier : ''));

        if ($name === '') {
            return ['success' => false, 'error' => 'Nom du groupe manquant'];
        }

        return $syncService->delete($name);
    }

    private function findGroup(): ?UserGroup
    {
        if (!is_int($this->groupIdentifier)) {
            return null;
        }

        return UserGroup::query()->find($this->groupIdentifier);
    }
}
