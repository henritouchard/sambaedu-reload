# Domaine App Customizations — personnalisation applicative extensible

_Dernière mise à jour : 2026-04-21 (story 4-8)._

Ce document décrit le module qui permet de personnaliser les politiques d'applications Firefox/Thunderbird (et futures) par scope hiérarchique (établissement → WorkstationGroup → UserGroup → User), avec deux modes de consommation par les postes clients : endpoints HTTP iso-contrat legacy (`/gpo/firefox_out.php`, `/gpo/thunderbird_out.php`) + endpoints canoniques `/api/policies/{kind}/{id}`.

## Vue d'ensemble

```
┌──────────────┐    ┌──────────────┐    ┌──────────────────────┐
│ AppKind enum │───▶│  Adapter     │───▶│ Livewire form SFC    │
└──────┬───────┘    │  (par app)   │    │ (par app)            │
       │            └──────┬───────┘    └──────────────────────┘
       │                   │
       ▼                   ▼
┌──────────────┐    ┌──────────────────────────────────────────┐
│  Registry    │    │ AppCustomizationService                  │
│  (auto)      │    │   resolvePoliciesForMachine()            │
└──────────────┘    │   savePolicies()                         │
                    │   exportAllToFs()                        │
                    └──────────────────────────────────────────┘
```

- **`AppKind` enum** — liste des apps supportées (valeurs `firefox`, `thunderbird`, …)
- **`AppPolicyAdapter` interface** — contrat à implémenter pour chaque app
- **`AppPolicyRegistry`** — singleton qui résout l'adapter pour un `AppKind` via le container Laravel (auto-découverte via `AppKind::cases()`)
- **`AppCustomizationService`** — logique de résolution hiérarchique + persistence + export FS
- **`AppPolicyController`** — endpoints HTTP publics (pas d'auth, rate limit `throttle:300,1`)

## Structure sur disque

```
app/
├── Enums/
│   └── AppKind.php                              ← ajouter un case par nouvelle app
├── Services/AppCustomization/
│   ├── Contracts/
│   │   ├── AppPolicyAdapter.php                 ← contrat à implémenter
│   │   └── AppContextRepository.php
│   ├── Support/AtomicFileWriter.php
│   ├── Firefox/                                 ← sous-dossier par app
│   │   ├── FirefoxPolicyAdapter.php
│   │   ├── FirefoxAddonResolver.php             (spécifique Firefox — API AMO)
│   │   ├── FirefoxAddonDiscovery.php            (dispatcher AMO vs XPI)
│   │   └── FirefoxExtensionResolver.php         (XPI custom fallback)
│   ├── Thunderbird/
│   │   └── ThunderbirdPolicyAdapter.php
│   ├── AppCustomizationService.php
│   ├── AppPolicyRegistry.php
│   └── ApcuAppContextRepository.php
resources/views/components/organisms/
├── app-customize-modal.blade.php                 (générique, délègue au form)
├── firefox/customize-form.blade.php
└── thunderbird/customize-form.blade.php
```

## HOWTO — Ajouter une nouvelle app customisable

Exemple : rendre **LibreOffice** paramétrable (registry.xcu / bootstraprc).

### 1. Ajouter un case dans l'enum `AppKind`

```php
// app/Enums/AppKind.php
enum AppKind: string
{
    case Firefox = 'firefox';
    case Thunderbird = 'thunderbird';
    case LibreOffice = 'libreoffice';          // ← nouveau

    public function label(): string
    {
        return match ($this) {
            self::Firefox => 'Firefox',
            self::Thunderbird => 'Thunderbird',
            self::LibreOffice => 'LibreOffice',  // ← nouveau
        };
    }

    public function adapterClass(): string
    {
        return match ($this) {
            self::Firefox => \App\Services\AppCustomization\Firefox\FirefoxPolicyAdapter::class,
            self::Thunderbird => \App\Services\AppCustomization\Thunderbird\ThunderbirdPolicyAdapter::class,
            self::LibreOffice => \App\Services\AppCustomization\LibreOffice\LibreOfficePolicyAdapter::class,  // ← nouveau
        };
    }
}
```

### 2. Créer le sous-dossier et l'adapter

```bash
mkdir -p app/Services/AppCustomization/LibreOffice
```

```php
// app/Services/AppCustomization/LibreOffice/LibreOfficePolicyAdapter.php
<?php

declare(strict_types=1);

namespace App\Services\AppCustomization\LibreOffice;

use App\Services\AppCustomization\Contracts\AppPolicyAdapter;
use App\Services\AppCustomization\Support\AtomicFileWriter;

class LibreOfficePolicyAdapter implements AppPolicyAdapter
{
    /** Clés racines éditables via l'UI admin. */
    private const WHITELISTED_KEYS = ['LinguisticDefaults', 'Updater', 'Security'];

    public function getTemplate(): array
    {
        $paths = (array) config('app-customizations.template_paths.libreoffice', [
            '/usr/share/sambaedu/applications/libreoffice/default.json',
            storage_path('app/app-customizations/libreoffice/template.json'),
        ]);
        foreach ($paths as $path) {
            if (is_file($path) && ($raw = @file_get_contents($path)) !== false) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }
        return ['policies' => []];
    }

    public function applyAuto(array $template, array $systemConfig): array
    {
        $json = $template;
        $json['policies'] = (array) ($json['policies'] ?? []);

        // Ex : injecter langue par défaut depuis $systemConfig['locale']
        $json['policies']['LinguisticDefaults'] = [
            'UILocale' => $systemConfig['locale'] ?? 'fr-FR',
        ];
        return $json;
    }

    public function mergeOverrides(array $base, array $overrides): array
    {
        return array_replace_recursive($base, $overrides);
    }

    public function renderFormComponent(): string
    {
        return 'components::organisms.libreoffice.customize-form';
    }

    public function exportToFs(array $policies, string $path): bool
    {
        $json = json_encode($policies, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return $json !== false && AtomicFileWriter::write($path, $json);
    }

    public function validatePolicies(array $policies): array
    {
        $errors = [];
        $maxSize = (int) config('app-customizations.max_policies_size', 262144);
        $encoded = json_encode($policies);
        if ($encoded !== false && strlen($encoded) > $maxSize) {
            $errors['policies_size'] = sprintf('Taille dépassée (%d > %d octets).', strlen($encoded), $maxSize);
        }
        return $errors;
    }

    public function stripNonWhitelistedOverrides(array $policies): array
    {
        $clean = ['policies' => []];
        foreach (self::WHITELISTED_KEYS as $key) {
            if (array_key_exists($key, (array) ($policies['policies'] ?? []))) {
                $clean['policies'][$key] = $policies['policies'][$key];
            }
        }
        return $clean;
    }
}
```

### 3. Créer le composant Livewire SFC

```bash
mkdir -p resources/views/components/organisms/libreoffice
```

```blade
{{-- resources/views/components/organisms/libreoffice/customize-form.blade.php --}}
<?php

use App\Components\Traits\WithToasts;
use App\Enums\AppKind;
use App\Services\AppCustomization\AppCustomizationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

new class extends Component {
    use WithToasts;

    private const ALLOWED_SCOPE_TYPES = [
        \App\Models\User::class,
        \App\Models\UserGroup::class,
        \App\Models\WorkstationGroup::class,
    ];

    public string $appKind = 'libreoffice';
    public ?string $scopeType = null;
    public ?int $scopeId = null;
    public string $uiLocale = 'fr-FR';

    public function mount(string $appKind, ?string $scopeType = null, ?int $scopeId = null): void
    {
        $this->appKind = $appKind;
        $this->scopeType = $scopeType;
        $this->scopeId = $scopeId;
        // Charger l'override existant...
    }

    public function save(): void
    {
        if (! Gate::allows('app.customize')) {
            $this->toastAccessDenied();
            return;
        }

        $policies = ['policies' => ['LinguisticDefaults' => ['UILocale' => $this->uiLocale]]];

        try {
            app(AppCustomizationService::class)->savePolicies(
                AppKind::from($this->appKind),
                $this->resolveScope(),
                $policies,
                Auth::user(),
            );
            $this->toastSuccess('Personnalisation LibreOffice enregistrée.');
            $this->dispatch('customization-saved', appKind: $this->appKind);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    private function resolveScope(): ?Model
    {
        if ($this->scopeType === null || $this->scopeId === null) return null;
        if (! in_array($this->scopeType, self::ALLOWED_SCOPE_TYPES, true)) {
            throw new \InvalidArgumentException('Type de scope non autorisé.');
        }
        $cls = $this->scopeType;
        return $cls::query()->find($this->scopeId);
    }
};
?>

<div class="space-y-6">
    <section>
        <label class="label"><span class="label-text text-xs">Locale UI</span></label>
        <select wire:model="uiLocale" class="select select-bordered select-sm w-full">
            <option value="fr-FR">Français</option>
            <option value="en-US">English</option>
        </select>
    </section>
    <div class="modal-action">
        <button type="button" class="btn btn-primary btn-sm" wire:click="save">Enregistrer</button>
    </div>
</div>
```

### 4. Placer le template système (optionnel mais recommandé)

Sur la VM :
```bash
ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50 "mkdir -p /usr/share/sambaedu/applications/libreoffice && cat > /usr/share/sambaedu/applications/libreoffice/default.json"
```
Contenu minimal :
```json
{
  "policies": {}
}
```

Alternative (fallback dev) : `storage/app/app-customizations/libreoffice/template.json`. Un chemin `config/app-customizations.php` peut être ajouté mais le fallback par défaut suffit généralement :

```php
// config/app-customizations.php — optionnel, seulement si chemin non standard
'template_paths' => [
    'libreoffice' => [
        env('APP_CUSTOMIZATIONS_LIBREOFFICE_TEMPLATE', '/usr/share/sambaedu/applications/libreoffice/default.json'),
        storage_path('app/app-customizations/libreoffice/template.json'),
    ],
],
```

### 5. Créer les tests

```bash
mkdir -p tests/Unit/Services/AppCustomization/LibreOffice
```

Test minimum (pattern `FirefoxPolicyAdapterTest`) :
```php
// tests/Unit/Services/AppCustomization/LibreOffice/LibreOfficePolicyAdapterTest.php
<?php

namespace Tests\Unit\Services\AppCustomization\LibreOffice;

use App\Services\AppCustomization\LibreOffice\LibreOfficePolicyAdapter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LibreOfficePolicyAdapterTest extends TestCase
{
    #[Test]
    public function render_form_component_returns_libreoffice_form(): void
    {
        $adapter = app(LibreOfficePolicyAdapter::class);
        $this->assertSame('components::organisms.libreoffice.customize-form', $adapter->renderFormComponent());
    }

    #[Test]
    public function strip_keeps_only_whitelisted_keys(): void
    {
        $adapter = app(LibreOfficePolicyAdapter::class);
        $clean = $adapter->stripNonWhitelistedOverrides([
            'policies' => [
                'LinguisticDefaults' => ['UILocale' => 'fr-FR'],
                'EvilKey' => 'attack',
            ],
        ]);
        $this->assertArrayHasKey('LinguisticDefaults', $clean['policies']);
        $this->assertArrayNotHasKey('EvilKey', $clean['policies']);
    }
}
```

Tester aussi que le Registry résout correctement :
```php
// Pattern : AppPolicyRegistryTest
$adapter = $this->registry()->resolve(AppKind::LibreOffice);
$this->assertInstanceOf(LibreOfficePolicyAdapter::class, $adapter);
```

### 6. (Optionnel) Endpoint legacy iso-contrat

Si les postes clients attendent un endpoint `/gpo/libreoffice_out.php` style legacy, ajouter dans `routes/web.php` **avant le catchall** :

```php
Route::match(['GET', 'POST'], 'gpo/libreoffice_out.php', [AppPolicyController::class, 'legacyLibreOfficeOut'])
    ->middleware('throttle:300,1')
    ->name('app-policy.libreoffice.legacy');
```

Et dans `AppPolicyController` :
```php
public function legacyLibreOfficeOut(Request $request): Response|JsonResponse
{
    return $this->resolve($request, AppKind::LibreOffice, (string) $request->input('os', 'linux'));
}
```

La route canonique `/api/policies/libreoffice/{id}` marche automatiquement (le controller `canonical()` fait `AppKind::tryFrom(strtolower($kind))`).

### 7. C'est tout

Le `AppPolicyRegistry` auto-découvre via `AppKind::cases()`. Aucune modification requise dans :
- `AppCustomizationService`
- `AppPolicyRegistry`
- `app-customize-modal.blade.php` (délègue via `renderFormComponent()`)
- `AppCustomizationServiceProvider`

Pour afficher un bouton "Paramétrer" dans la page application `/app/parc-settings/applications/{id}` : il suffit que l'`Application::app_id` stocké en DB corresponde exactement au `value` de l'enum (ex: `libreoffice` en minuscules). La détection est automatique via `AppKind::tryFrom(strtolower($application->app_id))`.

## Résolution hiérarchique — rappel

```
Template système (level 1)
  │
  ▼
applyAuto (proxy/DNS/locale, level 2)
  │
  ▼
Default étab (level 3 — AppCustomization NULL/NULL is_default=true)
  │
  ▼
WorkstationGroup (level 4 — salle ou parc AD)
  │
  ▼
UserGroups AD (level 5 — whereIn sur memberof)
  │
  ▼
User (level 6 — override individuel) ← priorité maximale
```

`AppCustomizationService::resolvePoliciesForMachine()` garantit ≤ 4 queries DB (template + auto = 0 query, default = 1, WG = 1, userGroups = 1 whereIn, user = 1).

## Résolution depuis un endpoint HTTP

```
Poste client → HTTP GET /gpo/{kind}_out.php?id=<md5>&os=linux
                       │
                       ▼
         AppPolicyController::legacy{Kind}Out
                       │
                       ▼
         AppContextRepository::findById($id)  ← lit APCu `apps.$id`
                       │
                       ▼
         WorkstationGroup (salle) + User (login) résolus en DB
                       │
                       ▼
         AppCustomizationService::resolvePoliciesForMachine()
                       │
                       ▼
         JSON 200 Content-Type: application/json;charset=utf-8
```

Id vide → body strictement vide 200 (fidèle `exit()` legacy).
Id invalide (pas md5) → 400.
Id valide mais contexte APCu expiré → 404.

## Export FS (rollback legacy)

Par défaut `export_fs_on_save=true` (env `APP_CUSTOMIZATIONS_EXPORT_FS`). Chaque save :
1. Écrit en DB (source de vérité)
2. Écrit atomiquement `/etc/sambaedu/applications/{kind}/<key>.json` (`temp + rename`)

`<key>` est déduit du scope :
- `default` si NULL/NULL/is_default=true
- `<user->login>` si scope User
- `<owner->name>` si scope UserGroup ou WorkstationGroup

Cette double écriture permet de désactiver les routes Laravel (mv `.legacy`) et que le legacy PHP retrouve ses fichiers habituels. Rollback en ≤ 30s.

## Commandes utiles

```bash
# Migration (une fois, après déploiement)
ssh -i ~/.ssh/id_se4fs_vm root@192.168.122.50 "cd /var/www/sambaedu-reload/laravel && php artisan migrate"

# Importer les overrides legacy existants en DB
php artisan apps:import-customizations-from-legacy --dry-run
php artisan apps:import-customizations-from-legacy                # toutes apps
php artisan apps:import-customizations-from-legacy --kind=firefox # ciblé

# Tests ciblés
php artisan test --filter='AppCustomization|AppKind|AppPolicy|FirefoxPolicy|ThunderbirdPolicy|FirefoxExtension|FirefoxAddon'

# Export manuel FS (après import DB par exemple)
# TODO : envelopper AppCustomizationService::exportAllToFs() dans une commande artisan `apps:export-customizations-to-fs`
```

## Contrat d'interface `AppPolicyAdapter`

| Méthode | Appelée par | Rôle |
|---|---|---|
| `getTemplate()` | `resolvePoliciesForMachine` level 1 | Charge le template système `/usr/share/sambaedu/applications/{kind}/default.json` |
| `applyAuto(array $template, array $sysConfig)` | level 2 | Injecte proxy/DNS/locale (valeurs calculées dynamiquement) |
| `mergeOverrides(array $base, array $overrides)` | levels 3→6 | Fusion récursive (typiquement `array_replace_recursive`) |
| `renderFormComponent()` | `app-customize-modal` | Nom du composant Livewire à afficher dans la modale |
| `exportToFs(array $policies, string $path)` | `savePolicies` si `export_fs_on_save=true` | Écriture atomique FS |
| `validatePolicies(array $policies)` | `savePolicies` avant persist | Validation structure (taille, types) |
| `stripNonWhitelistedOverrides(array $policies)` | `savePolicies` avant validate | Supprime les clés non éditables (sécurité stricte) |

## Sécurité

- **Permission** : `app.customize` (Spatie, mappée sur rôle `ComputerAdmin`). Vérifiée dans les forms Livewire, le controller, la policy.
- **Scope allowlist Livewire** : `ALLOWED_SCOPE_TYPES` dans chaque form prévient l'injection de FQN arbitraire via Livewire public property.
- **Rate limiting endpoints publics** : `throttle:300,1` par IP (5/s — suffisant pour 300 postes simultanés derrière NAT).
- **DNS rebinding guard** : `FirefoxExtensionResolver` refuse les IPs privées/loopback/réservées après résolution DNS. Activable/désactivable via `APP_CUSTOMIZATIONS_FIREFOX_EXT_DNS_GUARD` (défaut: on).
- **SSRF XPI** : `FirefoxExtensionResolver` limite HTTPS strict + allowlist domaines + timeout 5s + taille max 10 Mo + sandbox ZipArchive (`getFromName('manifest.json')` uniquement).
- **API AMO** : `FirefoxAddonResolver` — URL fixe `addons.mozilla.org/api/v5/`, pas de surface SSRF, pas besoin du DNS guard.

## Références

- Story 4-8 : [`_bmad-output/implementation-artifacts/4-8-personnalisation-apps-extensible.md`]
- Code review 4-8 : [`_bmad-output/codeReviews/4-8.md`]
- Flow récupération Firefox : [`firefox.md`] (racine projet)
- Pattern polymorphe origine : [`docs/domains/parc.md`] (story 4-7 wallpapers a inspiré le pattern)
- Sources legacy (lecture seule) : `sambaedu/includes/firefox.inc.php`, `sambaedu/gpo/{gestion_apps,firefox,firefox_out,thunderbird_out}.php`
