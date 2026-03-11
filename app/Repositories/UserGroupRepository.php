<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\UserGroup;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class UserGroupRepository
{
    public function paginate(?string $search, int $perPage = 20): LengthAwarePaginator
    {
        $query = UserGroup::query()->withCount('users');

        $term = trim((string) $search);
        if (mb_strlen($term) >= 2) {
            $normalized = '%' . mb_strtolower($term) . '%';

            $query->where(function (Builder $builder) use ($normalized): void {
                $builder
                    ->whereRaw("LOWER(COALESCE(name, '')) LIKE ?", [$normalized])
                    ->orWhereRaw("LOWER(COALESCE(display_name, '')) LIKE ?", [$normalized])
                    ->orWhereRaw("LOWER(COALESCE(type, '')) LIKE ?", [$normalized]);
            });
        }

        return $query
            ->orderByRaw("COALESCE(display_name, '')")
            ->orderBy('name')
            ->paginate($perPage, pageName: 'groupsPage');
    }

    public function findById(int $id): ?UserGroup
    {
        return UserGroup::query()->with('users')->find($id);
    }

    public function findByName(string $name): ?UserGroup
    {
        return UserGroup::query()->where('name', $name)->first();
    }

    public function create(array $data): UserGroup
    {
        return UserGroup::query()->create($data);
    }

    public function update(UserGroup $group, array $data): bool
    {
        return $group->update($data);
    }

    public function delete(UserGroup $group): bool
    {
        $group->users()->detach();

        return $group->delete();
    }
}
