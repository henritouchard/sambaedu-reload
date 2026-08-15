<?php

use App\Components\Traits\WithToasts;
use App\Enums\ActiveCloud;
use App\Enums\CloudAccessPath;
use App\Enums\FileBackendName;
use App\Exceptions\Filesystem\FileLocationException;
use App\Exceptions\Filesystem\FileLocationRefusalException;
use App\Services\FilePolicyService;
use App\Services\Filesystem\FileLocationChangeGuard;
use App\Services\Filesystem\FileLocationOptions;
use App\Services\Filesystem\FileLocationPolicyMirror;
use App\Services\Filesystem\FileLocations;
use App\Services\Filesystem\FileLocationService;
use App\Services\Shortcuts\PortalShortcutIcon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Onglet « Emplacements et cloud » de /admin/settings/files — L'ÉCRAN QUI POSE
 * ENFIN LA QUESTION.
 *
 * ---------------------------------------------------------------------------
 * **CE QU'IL RÉPARE.** L'écran d'avant portait quatre interrupteurs
 * indépendants — répertoire personnel, partages, Nextcloud, OpenCloud — qui
 * disaient ce qui était ALLUMÉ, et jamais OÙ VIVENT LES FICHIERS. Il acceptait
 * parfaitement « Nextcloud actif » ET « répertoire personnel actif » sans que
 * personne ne sache qui détenait le répertoire personnel, et « les deux clouds »
 * par-dessus le marché.
 *
 * Ici, TROIS QUESTIONS, dans cet ordre :
 *  1. quel cloud est configuré — choix EXCLUSIF ({@see ActiveCloud}), avec sa
 *     page de connexion et elle seule ;
 *  2. où vit l'espace personnel, où vit l'espace partagé — deux réglages
 *     d'instance, chacun avec l'effet qu'il produit sur le poste ;
 *  3. les réglages, dont le chemin d'accès au cloud ({@see CloudAccessPath}).
 *
 * **Aucun public, aucune ligne, aucun rang, aucune précédence** : ce sont deux
 * réglages d'instance, et rien d'autre. Et il n'existe **aucune troisième
 * valeur « aucun »** pour un emplacement — un espace vit quelque part.
 * ---------------------------------------------------------------------------
 *
 * **`files.locations` est la SOURCE, `files.policy` en est le MIROIR DÉRIVÉ**
 * ({@see FileLocationPolicyMirror}), écrit dans le même geste : les quatre
 * booléens historiques ont encore des lecteurs vivants, et cesser de les écrire
 * éteindrait la chaîne cloud.
 *
 * **L'ENREGISTREMENT DES BLOCS 1 ET 2 EST UN GESTE EXPLICITE**, contrairement à
 * tout le reste de cet écran. Deux raisons, et elles sont de fond :
 *  - la décision est une COMBINAISON ({@see FileLocations::make()} porte sur les
 *    trois valeurs ensemble) — auto-enregistrer le premier clic reviendrait à
 *    refuser une transition légitime qui se fait en deux gestes ;
 *  - la soumission peut être REFUSÉE (posabilité, garde de données) — un
 *    `wire:model.live` qui refuse un clic sur deux, en remettant la valeur
 *    d'avant, est une UI qui se bat contre l'utilisateur.
 * Le bloc 3 et les blocs de connexion, eux, gardent l'auto-enregistrement :
 * leurs réglages sont indépendants et ne sont jamais refusés.
 *
 * **AUCUNE ÉCRITURE AVANT QU'AUCUN REFUS NE SUBSISTE** — même forme que la
 * sonde-garde de la page de connexion : refusé ⇒ RIEN n'est persisté, et la
 * décision précédente reste en vigueur.
 *
 * Composant enfant (nested) — double garde `server.admin` au montage ET à
 * chaque écriture, et **racine stable** : aucune condition au premier niveau du
 * gabarit, sous peine d'erreur au re-rendu du parent.
 */
new class extends Component {
    use WithToasts;

    /** Littéral figé — la reprise n'a pas été jouée, et l'écran ne devine pas. */
    public const ADOPTION_NOTICE = 'Les emplacements n\'ont pas encore été repris depuis les réglages '
        .'historiques. Jouez `php artisan files:adopt-locations` sur le serveur, puis rechargez cette page.';

    /** Littéral figé — le chemin d'accès est enregistré, il n'a pas encore d'effet. */
    public const ACCESS_PATH_HONESTY = 'La pose du client de synchronisation sur les postes est livrée par '
        .'un chantier séparé. D\'ici là, cette position est enregistrée mais seul l\'accès par le navigateur '
        .'est effectivement posé.';

    /** Littéral figé — la précision qui évite le contresens sous « Espace personnel ». */
    public const PERSONAL_SHARE_STILL_SERVED = 'Le partage personnel du serveur de fichiers reste en service '
        .'pour l\'agent (Bureau, raccourcis, profils applicatifs) : seuls les fichiers de l\'utilisateur '
        .'changent d\'endroit.';

    /**
     * Littéral figé — LA FENÊTRE SE REFERME, et l'écran le dit AVANT le clic.
     *
     * La garde de données ({@see FileLocationChangeGuard}) ne constate que ce que
     * la base porte : sur une instance qui reprend un serveur de fichiers déjà en
     * service, elle est aveugle jusqu'au premier import d'annuaire. Le dire ici,
     * à côté du bouton, est la seule honnêteté disponible — l'écran ne sait pas
     * détecter cet existant, et la story lui interdit d'aller le chercher.
     */
    public const CHOICE_FREEZES_ONCE_ACCOUNTS_EXIST = 'Ce choix se fige dès que l\'instance porte un compte '
        .'ou un groupe : déplacer un espace peuplé suppose de déménager les données, ce que le chantier '
        .'« Epic 64 — la bascule d\'autorité » livrera. Tranchez-le avant le premier import d\'annuaire.';

    /**
     * Littéral figé — LE RICOCHET, expliqué AVANT le refus.
     *
     * Changer le cloud actif alors qu'un espace y vit oblige à déplacer cet
     * espace : la soumission retombe sous la garde de données et se fait refuser
     * avec le motif de l'espace, jamais avec celui du cloud. Sans cette phrase,
     * l'administrateur clique « Aucun cloud » et lit un refus qui parle d'autre
     * chose que de son geste.
     */
    public const RICOCHET_NOTICE = 'Un espace vit actuellement sur ce cloud : changer de position ici le '
        .'déplacerait, et ce déplacement est refusé tant que l\'espace porte des données. Videz d\'abord '
        .'l\'espace concerné, ou attendez le chantier « Epic 64 — la bascule d\'autorité ».';

    /** Le cloud actif de l'instance ({@see ActiveCloud}), en valeur brute. */
    public string $cloudActif = 'aucun';

    /** L'autorité de l'espace personnel ({@see FileBackendName}), en valeur brute. */
    public string $espacePerso = 'posix';

    /** L'autorité de l'espace partagé ({@see FileBackendName}), en valeur brute. */
    public string $espacePartage = 'posix';

    /** Le chemin d'accès au cloud ({@see CloudAccessPath}), en valeur brute. */
    public string $cloudAccessPath = 'web';

    /** Une décision a-t-elle déjà été enregistrée ? */
    public bool $decided = false;

    /** Le bandeau de reprise non jouée, ou `null`. */
    public ?string $adoptionNotice = null;

    /** Le message de l'exception de lecture, tel quel, ou `null`. */
    public ?string $readError = null;

    /**
     * Les quatre booléens historiques, POUR AFFICHAGE SEULEMENT — montrés en
     * lecture seule quand la reprise n'a pas été jouée, pour que l'exploitant
     * voie l'état hérité sans que l'écran ne prétende le traduire.
     *
     * @var array{home: bool, shares: bool, nextcloud: bool, opencloud: bool}
     */
    public array $heritage = ['home' => true, 'shares' => true, 'nextcloud' => false, 'opencloud' => false];

    public function mount(): void
    {
        if (! Gate::allows('server.admin')) {
            abort(403);
        }

        $this->cloudAccessPath = FilePolicyService::globalConfig()['cloud_access_path'];
        $this->heritage = FilePolicyService::capabilities();
        $this->decided = FileLocationService::isDecided();

        // ① La ligne persistée est ILLISIBLE. On affiche le message tel quel, on
        //    n'écrit rien, et on ne retombe JAMAIS sur les défauts : un repli
        //    silencieux inventerait une décision que personne n'a prise.
        try {
            $locations = FileLocationService::current();
        } catch (FileLocationException $e) {
            $this->readError = $e->getMessage();

            return;
        }

        // ② Aucune décision enregistrée, et les capacités historiques ne sont
        //    PAS les défauts : l'écran ne dérive rien — la reprise est un geste
        //    explicite, joué une fois, par une commande qui peut dire non.
        //    Rejouer sa table de décision ici ouvrirait un second chemin, qui
        //    divergerait.
        if (! $this->decided && ! $this->heritageIsTheHistoricalDefault()) {
            $this->adoptionNotice = self::ADOPTION_NOTICE;

            return;
        }

        // ③ Le cas nominal — décidé, ou neuf sur les défauts historiques (auquel
        //    cas l'écran pose LITTÉRALEMENT les défauts, il ne dérive rien).
        $this->restoreFrom($locations);
    }

    /**
     * Les capacités valent-elles EXACTEMENT les défauts historiques ? C'est la
     * signature d'une instance neuve — la seule où poser les défauts n'invente
     * rien.
     */
    private function heritageIsTheHistoricalDefault(): bool
    {
        return $this->heritage === ['home' => true, 'shares' => true, 'nextcloud' => false, 'opencloud' => false];
    }

    /** Les contrôles des blocs 1 et 2 sont-ils là ? (ABSENTS, jamais grisés.) */
    public function canDecide(): bool
    {
        return $this->readError === null && $this->adoptionNotice === null;
    }

    /**
     * Le cloud SÉLECTIONNÉ à l'écran, retombé sur `Aucun` si la valeur est hors
     * vocabulaire — le rendu ne doit jamais exploser sur une propriété forgée ;
     * c'est {@see self::save()} qui refuse, en nommant.
     */
    public function selectedCloud(): ActiveCloud
    {
        return ActiveCloud::tryFrom($this->cloudActif) ?? ActiveCloud::Aucun;
    }

    /**
     * Un objet d'emplacements NEUTRE, servant uniquement à interroger la
     * posabilité : seul le cloud actif entre dans la règle, et `posix` est
     * toujours acceptable — cette fabrique ne peut donc pas lever, quelle que
     * soit la sélection en cours de l'écran.
     */
    private function draftLocations(): FileLocations
    {
        return FileLocations::make(FileBackendName::Posix, FileBackendName::Posix, $this->selectedCloud());
    }

    /**
     * Les positions posables pour un emplacement, dans l'ordre d'affichage.
     *
     * @return list<FileBackendName>
     */
    public function availableAuthorities(): array
    {
        return app(FileLocationOptions::class)->available($this->draftLocations());
    }

    /**
     * Le motif d'ABSENCE du cloud actif dans la liste, ou `null` s'il y est.
     * Affiché À CÔTÉ de la liste : une position non disponible est absente, et
     * son motif est dit — jamais une case grisée sans explication.
     */
    public function cloudAuthorityRefusal(): ?string
    {
        $backend = $this->selectedCloud()->backend();

        if ($backend === null) {
            return FileLocationOptions::REFUSAL_NO_ACTIVE_CLOUD;
        }

        return app(FileLocationOptions::class)->refusalFor($backend, $this->draftLocations());
    }

    /**
     * Changer le cloud actif fait retomber SUR L'ÉCRAN tout emplacement qui
     * désignait l'ancien produit — sans quoi la combinaison serait
     * irreprésentable et l'écran afficherait une position qu'il n'offre plus.
     *
     * **Rien n'est persisté ici** : le geste d'enregistrement reste explicite,
     * et c'est lui qui rencontrera la garde de données — un espace peuplé ne se
     * déplace pas, fût-ce par ricochet d'un changement de cloud.
     */
    public function updatedCloudActif(): void
    {
        $backend = $this->selectedCloud()->backend();
        $accepted = $backend?->value;

        foreach (['espacePerso', 'espacePartage'] as $property) {
            if ($this->{$property} !== FileBackendName::Posix->value && $this->{$property} !== $accepted) {
                $this->{$property} = FileBackendName::Posix->value;
            }
        }
    }

    /**
     * LE GESTE EXPLICITE — et la chaîne de gardes, dans cet ordre exact :
     * cohérence, posabilité (sur ce qui change), données existantes, PUIS
     * SEULEMENT l'écriture de la source et de son miroir, **dans une seule
     * transaction**.
     *
     * ---------------------------------------------------------------------------
     * **LA POSABILITÉ NE PORTE QUE SUR CE QUI CHANGE** (correction de revue),
     * symétriquement à {@see FileLocationChangeGuard}. Rejouée
     * inconditionnellement, elle refusait un ré-enregistrement qui ne change
     * RIEN dès que la connexion du cloud actif s'était dégradée entre-temps :
     * l'administrateur se retrouvait enfermé, sans aucun geste pour en sortir —
     * le même défaut que l'écran inerte. Une position déjà persistée n'a pas à
     * être re-justifiée ; seule une position qu'on POSE doit être posable.
     *
     * **LES DEUX ÉCRITURES SONT ATOMIQUES.** La source dit « l'espace personnel
     * vit au cloud » et le miroir dit « la capacité cloud est active » : entre
     * les deux, un état où l'espace a quitté le serveur de fichiers sans que le
     * cloud soit joignable priverait les utilisateurs du seul chemin vers leurs
     * fichiers. Un agent qui compile dans cette fenêtre, ou un miroir qui échoue,
     * suffisent à la produire — d'où la transaction.
     * ---------------------------------------------------------------------------
     */
    public function save(): void
    {
        if (! Gate::allows('server.admin')) {
            abort(403);
        }

        if (! $this->canDecide()) {
            $this->toastError(
                'Les emplacements ne sont pas modifiables tant que la reprise n\'a pas été jouée : '
                .'aucune décision n\'est enregistrée.',
            );

            return;
        }

        try {
            $current = FileLocationService::current();
        } catch (FileLocationException $e) {
            $this->readError = $e->getMessage();
            $this->toastError($e->getMessage());

            return;
        }

        try {
            $submitted = FileLocations::make(
                $this->resolveAuthority('l\'espace personnel', $this->espacePerso),
                $this->resolveAuthority('l\'espace partagé', $this->espacePartage),
                $this->resolveActiveCloud(),
            );

            $options = app(FileLocationOptions::class);

            if ($submitted->espacePerso !== $current->espacePerso) {
                $options->assertAvailable($submitted->espacePerso, $submitted);
            }

            if ($submitted->espacePartage !== $current->espacePartage) {
                $options->assertAvailable($submitted->espacePartage, $submitted);
            }

            app(FileLocationChangeGuard::class)->assertChangeIsAllowed($current, $submitted);
        } catch (FileLocationException|FileLocationRefusalException $e) {
            // Refusé : RIEN n'est persisté — ni la source, ni le miroir — et
            // l'écran reprend l'état persisté plutôt que d'afficher une décision
            // que la base ne porte pas.
            $this->restoreFrom($current);
            $this->toastError($e->getMessage());

            return;
        }

        // LA SOURCE ET SON MIROIR, D'UN SEUL BLOC. Deux upserts sur deux lignes
        // de réglage : sans transaction, l'échec du second — ou un lecteur qui
        // passe entre les deux — laisse l'instance sur un état où les fichiers
        // ont changé d'endroit sans que le chemin d'accès existe.
        DB::transaction(function () use ($submitted): void {
            FileLocationService::set($submitted);
            app(FileLocationPolicyMirror::class)->write($submitted);
        });

        $this->publishPortalIcon();

        $this->decided = true;
        $this->heritage = FilePolicyService::capabilities();
        $this->restoreFrom($submitted);

        $this->toastSuccess('Emplacements enregistrés.');
    }

    /**
     * Le chemin d'accès s'enregistre SEUL, à la bascule : il est indépendant des
     * emplacements et n'est jamais refusé.
     *
     * **Il ne ré-énumère plus les treize paramètres de `setGlobal()`**
     * (correction de revue) : ce doublon de l'ordre des paramètres était
     * exactement la classe de défaut que cette story ferme. Il ne nomme que ce
     * qu'il change, et {@see FilePolicyService::patchGlobal()} — seul endroit du
     * dépôt à connaître cet ordre — relit et repasse tout le reste.
     */
    public function updatedCloudAccessPath(): void
    {
        if (! Gate::allows('server.admin')) {
            abort(403);
        }

        if (! CloudAccessPath::isKnown($this->cloudAccessPath)) {
            $this->cloudAccessPath = FilePolicyService::globalConfig()['cloud_access_path'];

            return;
        }

        FilePolicyService::patchGlobal(['cloud_access_path' => $this->cloudAccessPath]);

        $this->toastSuccess('Chemin d\'accès enregistré.');
    }

    /**
     * UNE CONNEXION VIENT D'ÊTRE ENREGISTRÉE DANS LE BLOC ENFANT — cet écran
     * recalcule ce qu'il propose (correction de revue).
     *
     * Sans cet aller-retour, l'écran se contredisait : l'administrateur
     * choisissait le cloud, l'enregistrait, complétait sa connexion juste
     * en-dessous (bloc auto-enregistré) — et la position cloud n'apparaissait
     * JAMAIS dans le bloc 2, dont le motif continuait d'annoncer « la connexion
     * est incomplète : complétez-la ci-dessus » alors qu'elle venait de l'être.
     * Il fallait recharger la page pour que l'écran se croie lui-même.
     *
     * Les positions posables sont recalculées à CHAQUE rendu
     * ({@see self::availableAuthorities()} interroge le service) : la seule chose
     * dont ce composant a besoin, c'est d'être re-rendu — et de rafraîchir
     * l'état hérité, qui, lui, est un instantané de montage.
     *
     * L'événement est ÉMIS par les deux blocs de connexion — `nextcloud-connection`
     * et `opencloud-connection` — après tout enregistrement réussi. Le nom est un
     * littéral aux trois endroits : un attribut PHP n'accepte qu'une expression
     * constante, et une constante partagée entre trois composants anonymes n'a
     * pas de foyer honnête.
     */
    #[On('cloud-connexion-enregistree')]
    public function onConnectionSaved(): void
    {
        if (! Gate::allows('server.admin')) {
            abort(403);
        }

        $this->heritage = FilePolicyService::capabilities();
    }

    /**
     * L'icône du raccourci-portail, publiée par TOUT chemin qui rend un cloud
     * actif — comme le fait déjà la commande de reprise. Sans elle, le raccourci
     * « Mes fichiers en ligne » arriverait sur les bureaux avec l'icône de
     * `rundll32.exe`.
     *
     * Idempotente et NON BLOQUANTE : un échec laisse le raccourci sans icône,
     * jamais sans raccourci — et n'annule surtout pas la décision enregistrée.
     */
    private function publishPortalIcon(): void
    {
        try {
            app(PortalShortcutIcon::class)->publish();
        } catch (\Throwable $e) {
            Log::warning('files.locations: publication de l\'icône de portail échouée', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** @throws FileLocationException */
    private function resolveAuthority(string $objet, string $raw): FileBackendName
    {
        return FileBackendName::tryFrom($raw) ?? throw FileLocationException::unknownAuthority($objet, $raw);
    }

    /** @throws FileLocationException */
    private function resolveActiveCloud(): ActiveCloud
    {
        return ActiveCloud::tryFrom($this->cloudActif) ?? throw FileLocationException::unknownActiveCloud($this->cloudActif);
    }

    private function restoreFrom(FileLocations $locations): void
    {
        $this->espacePerso = $locations->espacePerso->value;
        $this->espacePartage = $locations->espacePartage->value;
        $this->cloudActif = $locations->cloudActif->value;
    }
};
?>

{{-- RACINE STABLE : un seul élément au premier niveau, sans condition — une
     condition ici casse le re-rendu du parent. --}}
<div class="flex flex-col gap-6" data-testid="emplacements-tab">

    @if ($readError !== null)
        {{-- La ligne persistée est ILLISIBLE : le message de l'exception, tel
             quel, et AUCUN contrôle. Retomber sur les défauts inventerait une
             décision que personne n'a prise. --}}
        <div class="alert alert-error" data-testid="locations-read-error">
            <i class="fa-solid fa-circle-exclamation"></i>
            <div class="text-sm">
                <p class="font-medium">Le réglage des emplacements ne se relit pas</p>
                <p class="mt-1">{{ $readError }}</p>
            </div>
        </div>
    @else

        @if ($adoptionNotice !== null)
            {{-- La reprise n'a pas été jouée et les capacités historiques ne sont
                 pas les défauts : l'écran NE DEVINE PAS. Les contrôles des blocs
                 1 et 2 sont ABSENTS — pas grisés — et l'état hérité est montré
                 en lecture seule. Les blocs de connexion, eux, restent
                 éditables : c'est par eux qu'on répare une connexion incomplète,
                 et la reprise en a besoin. --}}
            <div class="alert alert-warning" data-testid="locations-adoption-notice">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <div class="text-sm">
                    <p>{{ $adoptionNotice }}</p>
                    <p class="mt-1">
                        Les connexions aux deux produits cloud restent modifiables ci-dessous : c'est par
                        elles qu'on complète une connexion incomplète — ou qu'on en retire une quand deux
                        clouds configurés empêchent la reprise de trancher.
                    </p>
                </div>
            </div>

            <div class="rounded-xl border border-base-300 bg-base-100 p-5">
                <p class="text-xs font-semibold uppercase tracking-wider text-base-content/60 mb-3">
                    État hérité (lecture seule)
                </p>
                <div class="flex flex-wrap gap-2 text-xs">
                    <span class="badge {{ $heritage['home'] ? 'badge-primary' : 'badge-ghost' }} badge-outline">
                        Accès au répertoire personnel : {{ $heritage['home'] ? 'actif' : 'coupé' }}
                    </span>
                    <span class="badge {{ $heritage['shares'] ? 'badge-primary' : 'badge-ghost' }} badge-outline">
                        Accès aux partages : {{ $heritage['shares'] ? 'actif' : 'coupé' }}
                    </span>
                    <span class="badge {{ $heritage['nextcloud'] ? 'badge-primary' : 'badge-ghost' }} badge-outline">
                        Accès Nextcloud : {{ $heritage['nextcloud'] ? 'actif' : 'éteint' }}
                    </span>
                    <span class="badge {{ $heritage['opencloud'] ? 'badge-primary' : 'badge-ghost' }} badge-outline">
                        Accès OpenCloud : {{ $heritage['opencloud'] ? 'actif' : 'éteint' }}
                    </span>
                </div>
            </div>
        @endif

        {{-- ═══════════════════════════════════════════════════════════════════
             BLOC 1 — LE CLOUD DE L'ÉTABLISSEMENT : un choix, trois positions
             mutuellement exclusives. Jamais deux interrupteurs indépendants :
             une instance n'active qu'UN SEUL cloud à la fois, et le vocabulaire
             fermé le rend irreprésentable autrement.
        ═══════════════════════════════════════════════════════════════════ --}}
        <section class="flex flex-col gap-4" data-testid="bloc-cloud">
            <div>
                <h3 class="text-sm font-semibold uppercase tracking-wider text-base-content/60">
                    <i class="fa-solid fa-cloud mr-1"></i>
                    Le cloud de l'établissement
                </h3>
                <p class="text-xs text-base-content/60 mt-1">
                    Un seul cloud à la fois. La position retenue révèle sa page de connexion, et elle seule.
                </p>
            </div>

            @if ($this->canDecide())
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    @foreach (\App\Enums\ActiveCloud::cases() as $cloud)
                        <label class="card bg-base-100 border cursor-pointer transition-all hover:shadow-md
                            {{ $cloudActif === $cloud->value ? 'border-primary/50 shadow-sm' : 'border-base-300' }}"
                            data-testid="cloud-choice-{{ $cloud->value }}">
                            <div class="card-body p-5 gap-2">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="w-11 h-11 rounded-xl flex items-center justify-center shrink-0
                                        {{ $cloudActif === $cloud->value ? 'bg-primary/10 text-primary' : 'bg-base-200 text-base-content/40' }}">
                                        <i class="fa-solid {{ $cloud === \App\Enums\ActiveCloud::Aucun ? 'fa-ban' : 'fa-cloud' }} text-lg"></i>
                                    </div>
                                    <input type="radio" name="cloud-actif" class="radio radio-primary"
                                        wire:model.live="cloudActif" value="{{ $cloud->value }}"
                                        aria-label="{{ $cloud->label() }}" />
                                </div>
                                <span class="font-medium">{{ $cloud->label() }}</span>
                            </div>
                        </label>
                    @endforeach
                </div>

                {{-- LE RICOCHET, DIT AVANT LE REFUS. Avec un espace au cloud,
                     cliquer « Aucun cloud » échoue sur « l'espace personnel
                     porte déjà des données » — un motif que rien ne relie au
                     geste qui vient d'être fait. --}}
                @if ($espacePerso !== 'posix' || $espacePartage !== 'posix')
                    <p class="text-xs text-warning" data-testid="cloud-ricochet-notice">
                        <i class="fa-solid fa-circle-info"></i>
                        Un espace vit actuellement sur ce cloud : changer de position ici le déplacerait,
                        et ce déplacement est refusé tant que l'espace porte des données. Videz d'abord
                        l'espace concerné, ou attendez le chantier « Epic 64 — la bascule d'autorité ».
                    </p>
                @endif
            @endif

            {{-- La page de configuration du produit retenu, et elle seule.

                 QUAND LA REPRISE N'A PAS ÉTÉ JOUÉE, LES DEUX SONT LÀ (correction
                 de revue). Elles l'étaient auparavant selon les CAPACITÉS
                 héritées — donc pas du tout quand les deux étaient éteintes,
                 état où l'écran devenait totalement inerte : ni radios, ni bloc
                 de connexion, aucun geste possible, et la commande de reprise
                 renvoyant vers cet écran-là. Or ce sont des réglages de
                 CONNEXION : ils ne décident de rien, ils ne peuvent donc pas
                 être conditionnés à une décision. C'est par eux qu'on complète
                 une connexion incomplète — et qu'on en dégrade une lorsque deux
                 clouds configurés empêchent la reprise de trancher. --}}
            @php
                $connectionProducts = $this->canDecide()
                    ? array_values(array_filter([$cloudActif === 'nextcloud' ? 'nextcloud' : null, $cloudActif === 'opencloud' ? 'opencloud' : null]))
                    : ['nextcloud', 'opencloud'];
            @endphp

            @foreach ($connectionProducts as $product)
                @if ($product === 'nextcloud')
                    <livewire:pages::admin.settings.files._partials.nextcloud-connection :key="'connexion-nextcloud'" />
                @else
                    <livewire:pages::admin.settings.files._partials.opencloud-connection :key="'connexion-opencloud'" />
                @endif
            @endforeach
        </section>

        @if ($this->canDecide())
            {{-- ═══════════════════════════════════════════════════════════════
                 BLOC 2 — OÙ VIVENT LES FICHIERS. Deux questions indépendantes,
                 deux positions au plus chacune, et l'effet sur le poste dit à
                 côté de chaque position. Une position non posable est ABSENTE,
                 avec son motif — jamais grisée.
            ═══════════════════════════════════════════════════════════════ --}}
            <section class="flex flex-col gap-4" data-testid="bloc-emplacements">
                <div>
                    <h3 class="text-sm font-semibold uppercase tracking-wider text-base-content/60">
                        <i class="fa-solid fa-folder-tree mr-1"></i>
                        Où vivent les fichiers
                    </h3>
                </div>

                @php
                    $available = $this->availableAuthorities();
                    $cloudRefusal = $this->cloudAuthorityRefusal();
                    $cloudLabel = $this->selectedCloud()->label();
                    $espaces = [
                        [
                            'titre' => 'Espace personnel',
                            'property' => 'espacePerso',
                            'valeur' => $espacePerso,
                            'testid' => 'espace-perso',
                            'effetPosix' => 'Lecteur K: monté sur le poste',
                        ],
                        [
                            'titre' => 'Espace partagé',
                            'property' => 'espacePartage',
                            'valeur' => $espacePartage,
                            'testid' => 'espace-partage',
                            'effetPosix' => 'Lecteur H: monté sur le poste',
                        ],
                    ];
                @endphp

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    @foreach ($espaces as $espace)
                        <div class="card bg-base-100 border border-base-300" data-testid="{{ $espace['testid'] }}-card">
                            <div class="card-body p-5 gap-3">
                                <span class="font-medium">{{ $espace['titre'] }}</span>

                                <div class="flex flex-col gap-2">
                                    @foreach ($available as $authority)
                                        <label class="flex items-start gap-3 rounded-lg border p-3 cursor-pointer transition-colors
                                            {{ $espace['valeur'] === $authority->value ? 'border-primary/50 bg-primary/5' : 'border-base-300' }}"
                                            data-testid="{{ $espace['testid'] }}-option-{{ $authority->value }}">
                                            <input type="radio" class="radio radio-sm radio-primary mt-0.5"
                                                name="{{ $espace['testid'] }}"
                                                wire:model="{{ $espace['property'] }}" value="{{ $authority->value }}" />
                                            <span class="flex flex-col">
                                                <span class="text-sm font-medium">
                                                    {{ $authority === \App\Enums\FileBackendName::Posix
                                                        ? 'Serveur de fichiers (SMB)'
                                                        : $cloudLabel }}
                                                </span>
                                                <span class="text-xs text-base-content/60">
                                                    {{ $authority === \App\Enums\FileBackendName::Posix
                                                        ? $espace['effetPosix']
                                                        : 'Pas de lettre de lecteur : accès par le client' }}
                                                </span>
                                            </span>
                                        </label>
                                    @endforeach

                                    @if ($cloudRefusal !== null)
                                        <p class="text-xs text-warning" data-testid="{{ $espace['testid'] }}-refusal">
                                            <i class="fa-solid fa-circle-info"></i>
                                            {{ $cloudRefusal }}
                                        </p>
                                    @endif
                                </div>

                                @if ($espace['property'] === 'espacePerso')
                                    {{-- La précision sans laquelle un administrateur croit
                                         qu'il éteint le partage personnel. --}}
                                    <p class="text-xs text-base-content/60 border-t border-base-300 pt-2"
                                        data-testid="espace-perso-precision">
                                        Le partage personnel du serveur de fichiers reste en service pour
                                        l'agent (Bureau, raccourcis, profils applicatifs) : seuls les fichiers
                                        de l'utilisateur changent d'endroit.
                                    </p>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <button type="button" class="btn btn-primary btn-sm" wire:click="save"
                        wire:loading.attr="disabled" wire:target="save" data-testid="save-locations">
                        <span wire:loading wire:target="save" class="loading loading-spinner loading-xs"></span>
                        <i wire:loading.remove wire:target="save" class="fa-solid fa-floppy-disk"></i>
                        Enregistrer les emplacements
                    </button>
                    <span class="text-xs text-base-content/60">
                        Le cloud et les deux emplacements vont ensemble : ils s'enregistrent d'un seul geste, et
                        le refus, s'il y en a un, arrive au même endroit.
                    </span>
                </div>

                {{-- LA FENÊTRE SE REFERME — dit AVANT le clic, et pas seulement
                     au moment du refus. La garde de données ne constate que ce
                     que la base porte : sur une instance qui reprend un serveur
                     de fichiers déjà en service, elle est aveugle jusqu'au
                     premier import d'annuaire. --}}
                <p class="text-xs text-warning" data-testid="choice-freezes-notice">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    Ce choix se fige dès que l'instance porte un compte ou un groupe : déplacer un espace
                    peuplé suppose de déménager les données, ce que le chantier « Epic 64 — la bascule
                    d'autorité » livrera. Tranchez-le avant le premier import d'annuaire.
                </p>
            </section>

            {{-- ═══════════════════════════════════════════════════════════════
                 BLOC 3 — RÉGLAGES. Il ne gouverne rien tant qu'aucun cloud n'est
                 actif : il n'apparaît donc pas.
            ═══════════════════════════════════════════════════════════════ --}}
            @if ($cloudActif !== 'aucun')
                <section class="flex flex-col gap-4" data-testid="bloc-reglages">
                    <div>
                        <h3 class="text-sm font-semibold uppercase tracking-wider text-base-content/60">
                            <i class="fa-solid fa-sliders mr-1"></i>
                            Réglages
                        </h3>
                    </div>

                    <div class="card bg-base-100 border border-base-300 w-full lg:max-w-xl">
                        <div class="card-body p-5 gap-3">
                            <div class="flex flex-col w-full">
                                <label class="label w-full" for="cloud-access-path">
                                    <span class="label-text font-medium">
                                        Chemin d'accès au cloud <span class="text-error">*</span>
                                    </span>
                                </label>
                                <select id="cloud-access-path" class="select select-bordered w-full"
                                    wire:model.live="cloudAccessPath" data-testid="cloud-access-path">
                                    @foreach (\App\Enums\CloudAccessPath::cases() as $path)
                                        <option value="{{ $path->value }}">{{ $path->label() }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <p class="text-xs text-warning" data-testid="access-path-honesty">
                                <i class="fa-solid fa-triangle-exclamation"></i>
                                La pose du client de synchronisation sur les postes est livrée par un chantier
                                séparé. D'ici là, cette position est enregistrée mais seul l'accès par le
                                navigateur est effectivement posé.
                            </p>
                        </div>
                    </div>

                    {{-- ICI se logeront les cartes « Quotas » et « Corbeille »
                         (story 63.4). Aucune carte vide n'est rendue d'ici là :
                         une carte sans contenu est de l'UI orpheline, exactement
                         ce que le retrait de l'onglet « Quotas & FS » a fermé. --}}
                </section>
            @endif
        @endif
    @endif
</div>
