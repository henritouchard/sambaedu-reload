<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\NativeApplication;
use Illuminate\Database\Seeder;

/**
 * Story 27.11 — Seeder du référentiel CURÉ des applications natives Win32
 * (D-Henri n°2). Source 2 du dropdown du composer d'associations.
 *
 * Catalogue figé, curé MANUELLEMENT : les built-ins Windows dont le ProgId
 * canonique est connu et toujours présent. **UWP modernes EXCLUES** (ProgId
 * `AppX…` ingérables). Chaque entrée → le {@see \App\Services\Agent\Resolvers\AssociationResolver}
 * émet son `progid` avec `source=native`, toujours applicable (piège n°7).
 *
 * IDEMPOTENT : `updateOrCreate` par `key` déterministe (rejouable, zéro doublon),
 * câblé dans {@see DatabaseSeeder} (iso `FileAssociationSeeder`/`ShortcutSeeder`).
 *
 * Les `assoc_types` bornent le ProgId canonique à ses extensions DÉCLARÉES
 * (piège n°2 : un ProgId est par (app × type de contenu) — le Bloc-notes gère
 * `.txt`, PAS `.png`). Le `executable` sert le fallback générique : le SERVEUR n'en
 * consomme que le BASENAME (`Applications\<exe>`) ; le chemin complet n'est ni transmis
 * au payload ni consommé par l'agent — le poste le re-résout (AC6/AC7).
 */
class NativeApplicationSeeder extends Seeder
{
    /**
     * Référentiel curé des built-ins Win32. ProgId canoniques connus de Windows.
     *
     * @return list<array{label:string,progid:string,executable:string,assoc_types:list<string>,icon_url:?string}>
     */
    private function catalog(): array
    {
        // RÈGLE DE CURATION : on ne curé qu'un built-in dont SOIT (a) le ProgId
        // canonique est fiablement présent sur les Windows ciblés (ex. `txtfile`,
        // `Paint.Picture`), SOIT (b) le basename d'exe produit un générique
        // `Applications\<exe> "%1"` RÉELLEMENT fonctionnel (l'exe ouvre le fichier
        // passé en `%1`). Contre-exemple écarté : la « Visionneuse de photos
        // Windows » dont l'exe est `rundll32.exe` → générique `Applications\rundll32.exe`
        // / `"rundll32.exe" "%1"` qui N'OUVRE PAS l'image (il manque l'argument
        // `shell32.dll,ImageView_Fullscreen`) → exclue.
        //
        // Les apps absentes des Windows récents (Visionneuse de photos désactivée
        // depuis Win10 1607 ; WordPad RETIRÉ en Win11 24H2) relèvent d'une DÉCISION
        // DE CURATION PRODUIT (laissée à Henri), pas d'une règle technique.
        return [
            [
                'label' => 'Bloc-notes (Notepad)',
                'progid' => 'txtfile',
                'executable' => '%SystemRoot%\\system32\\notepad.exe',
                'assoc_types' => ['.txt'],
                'icon_url' => null,
            ],
            [
                'label' => 'Paint',
                'progid' => 'Paint.Picture',
                'executable' => '%SystemRoot%\\system32\\mspaint.exe',
                'assoc_types' => ['.bmp', '.png', '.jpg', '.jpeg', '.gif'],
                'icon_url' => null,
            ],
            [
                // NOTE CURATION : WordPad est RETIRÉ de Windows 11 24H2+ (curation
                // produit à trancher par Henri — conservé pour l'instant, pas retiré).
                'label' => 'WordPad',
                'progid' => 'WordPad.Document.1',
                'executable' => '%ProgramFiles%\\Windows NT\\Accessories\\wordpad.exe',
                'assoc_types' => ['.rtf', '.txt'],
                'icon_url' => null,
            ],
        ];
    }

    public function run(): void
    {
        $count = 0;
        foreach ($this->catalog() as $row) {
            NativeApplication::query()->updateOrCreate(
                ['key' => self::keyFor($row['label'], $row['progid'])],
                [
                    'label' => $row['label'],
                    'progid' => $row['progid'],
                    'executable' => $row['executable'],
                    'assoc_types' => $row['assoc_types'],
                    'icon_url' => $row['icon_url'] ?? null,
                ],
            );
            $count++;
        }

        if ($this->command !== null) {
            $this->command->info($count . ' applications natives curées seedées (built-ins Win32, UWP exclues).');
        }
    }

    /**
     * Clé déterministe d'upsert (slug `(label, progid)`) — idempotence du seeder.
     */
    public static function keyFor(string $label, string $progid): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '_', strtolower($label . '_' . $progid));

        return trim((string) $slug, '_');
    }
}
