<?php

declare(strict_types=1);

namespace App\Gpo\Dto;

/**
 * Archive-template d'une GPO livrée par le paquet Debian `sambaedu-gpo`
 * (répertoire `config('sambaedu.gpo.templates_dir')`).
 *
 * Une template = la **source** du contenu SYSVOL d'une GPO (le `GPT.INI`, le
 * `scripts.ini`, le `.bat` d'amorce, les `Registry.pol`/`GptTmpl.inf` + les
 * placeholders `###_…_###`). Sa présence est la condition nécessaire pour
 * qu'une GPO de l'AD soit **publiable** : `import_gpo` extrait l'archive,
 * spécialise les placeholders et pousse le tout sur SYSVOL.
 *
 * Construit par {@see \App\Gpo\Support\GpoTemplateRegistry} en scannant le
 * répertoire des templates. Lecture seule, typé strict.
 */
final readonly class GpoTemplate
{
    public function __construct(
        /** Nom affiché (section `[General] displayName` du `GPT.INI`, ou basename). Clé de résolution avec une GPO de l'AD. */
        public string $displayName,
        /** Nom de l'entrée sur disque transmise à `import_gpo` (ex. `se4_wpkg.zip` ou répertoire `se4_wpkg`). */
        public string $archive,
        /** Numéro de version de la template (section `[General] Version`), NULL si absent. */
        public ?int $version = null,
    ) {}

    /**
     * @return array{displayName:string,archive:string,version:?int}
     */
    public function toArray(): array
    {
        return [
            'displayName' => $this->displayName,
            'archive' => $this->archive,
            'version' => $this->version,
        ];
    }
}
