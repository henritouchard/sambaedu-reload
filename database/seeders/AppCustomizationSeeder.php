<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AppKind;
use App\Models\AppCustomization;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\WorkstationGroup;
use Illuminate\Database\Seeder;

/**
 * Seeder fixtures — story 4.8. Idempotent (updateOrCreate sur clé composite).
 *
 * Crée :
 *   - Default étab Firefox avec Homepage + 1 bookmark
 *   - Default étab Thunderbird avec Proxy custom
 *   - Override WorkstationGroup Firefox (premier groupe trouvé)
 *   - Override User Firefox (premier user trouvé)
 */
class AppCustomizationSeeder extends Seeder
{
    public function run(): void
    {
        // Default étab Firefox
        AppCustomization::updateOrCreate(
            [
                'app_kind' => AppKind::Firefox->value,
                'customizable_type' => null,
                'customizable_id' => null,
                'is_default' => true,
            ],
            [
                'policies_json' => [
                    'policies' => [
                        'Homepage' => [
                            'URL' => 'https://samba-edu.fr/',
                            'Locked' => false,
                            'StartPage' => 'homepage',
                        ],
                        'Bookmarks' => [
                            [
                                'Title' => 'SambaEdu',
                                'URL' => 'https://samba-edu.fr/',
                                'Folder' => 'Outils',
                            ],
                        ],
                    ],
                ],
            ],
        );

        // Default étab Thunderbird — proxy manuel de démonstration
        AppCustomization::updateOrCreate(
            [
                'app_kind' => AppKind::Thunderbird->value,
                'customizable_type' => null,
                'customizable_id' => null,
                'is_default' => true,
            ],
            [
                'policies_json' => [
                    'policies' => [
                        'Proxy' => [
                            'Mode' => 'manual',
                            'HTTPProxy' => 'http://proxy.local:3128',
                        ],
                    ],
                ],
            ],
        );

        // Override WorkstationGroup Firefox (premier trouvé)
        $wg = WorkstationGroup::query()->first();
        if ($wg !== null) {
            AppCustomization::updateOrCreate(
                [
                    'app_kind' => AppKind::Firefox->value,
                    'customizable_type' => WorkstationGroup::class,
                    'customizable_id' => $wg->getKey(),
                ],
                [
                    'policies_json' => [
                        'policies' => [
                            'Homepage' => [
                                'URL' => 'https://intranet.' . ($wg->name ?? 'salle') . '.local/',
                            ],
                        ],
                    ],
                    'is_default' => false,
                ],
            );
        }

        // Override User Firefox (premier trouvé)
        $user = User::query()->first();
        if ($user !== null) {
            AppCustomization::updateOrCreate(
                [
                    'app_kind' => AppKind::Firefox->value,
                    'customizable_type' => User::class,
                    'customizable_id' => $user->getKey(),
                ],
                [
                    'policies_json' => [
                        'policies' => [
                            'Bookmarks' => [
                                [
                                    'Title' => 'Mon ENT',
                                    'URL' => 'https://ent.example.fr/',
                                    'Folder' => 'Perso',
                                ],
                            ],
                        ],
                    ],
                    'is_default' => false,
                ],
            );
        }
    }
}
