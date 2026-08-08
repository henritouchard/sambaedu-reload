<?php

declare(strict_types=1);

namespace App\Services\Nextcloud;

use App\Enums\NextcloudInstanceMode;
use App\Exceptions\Nextcloud\NextcloudConfigurationException;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Story 61.2 — LE RATTACHEMENT EXPLICITE D'IDENTITÉ (le legs N1 de la revue 61.1).
 *
 * ---------------------------------------------------------------------------
 * **POURQUOI CE GESTE EXISTE.** Depuis la correction #2 de la revue 61.1, SE5
 * n'adopte plus qu'un HOMONYME : un candidat unique rendu par une recherche floue
 * n'est pas une preuve d'identité, et l'adopter rouvrait l'écrasement du mot de
 * passe d'un tiers (le scénario `p.durand` / `p.durand-martin`). Le cas
 * « l'instance dont les identifiants ne sont pas les logins » n'est donc plus
 * résolu automatiquement — la revue l'a nommé comme rouvrable ici, « avec une
 * corroboration explicite plutôt qu'une devinette ».
 *
 * Ce service EST cette corroboration explicite : un geste d'administrateur, vérifié
 * à distance avant d'écrire.
 *
 * **LA RÈGLE DE SÉCURITÉ DE LA CORRECTION #2 EST CONSERVÉE À L'IDENTIQUE :
 * JAMAIS D'ÉCRITURE SUR UNE IDENTITÉ NON CONFIRMÉE À DISTANCE.** Qu'un humain
 * l'ait tapée n'y change rien — une faute de frappe sur un identifiant voisin
 * produirait exactement le défaut d'en face : le prochain changement de mot de
 * passe AD écraserait le mot de passe du compte d'une autre personne, journalisé
 * comme un succès.
 * ---------------------------------------------------------------------------
 *
 * **La vérification est PAR MODE**, parce que les deux modes n'ont pas le même
 * privilège :
 *  - mode **administré** : sonde directe `GET cloud/users/<id>` — l'instance dit
 *    si le compte existe ;
 *  - mode **délégué** : autocomplétion avec correspondance EXACTE — c'est ce dont
 *    dispose un compte ordinaire (précédent SE4 `cloud.inc.php:989`).
 *
 * **Ce qui est écrit** : `users.nextcloud_user_id`, le cache posé en 61.1 — hors
 * `$fillable`, donc jamais par assignation en masse ; l'écriture est nominative et
 * passe par ce service. La colonne reste un CACHE : la vérité est chez Nextcloud,
 * et `--clear` la remet à null sans rien détruire ailleurs (D9).
 *
 * ---------------------------------------------------------------------------
 * **CORRECTION DE REVUE (61.2 #2) — UNE IDENTITÉ NEXTCLOUD N'EST PORTÉE QUE PAR UN
 * SEUL UTILISATEUR SE5.** La vérification à distance prouvait que l'identité EXISTE,
 * jamais qu'elle est LIBRE. Deux logins SE5 pouvant pointer le même compte
 * Nextcloud, la propagation de mot de passe de l'un écrasait le compte de l'autre —
 * exactement le défaut que la correction #2 de la revue 61.1 avait fermé, rouvert
 * par la porte « geste d'admin vérifié ». Le geste est désormais refusé **en nommant
 * le login SE5 qui détient déjà l'identité**, et rien n'est écrit.
 *
 * La garde est APPLICATIVE et vaut à tous les points d'écriture du cache (ici et
 * dans {@see NextcloudUserProvisioner}) ; l'index unique en base n'en est que la
 * défense en profondeur.
 * ---------------------------------------------------------------------------
 */
final class NextcloudIdentityLinker
{
    public function __construct(private readonly NextcloudClientFactory $factory)
    {
    }

    /**
     * Rattache explicitement un utilisateur SE5 à une identité Nextcloud.
     *
     * Trois issues, et elles se distinguent :
     *  - **abouti** — l'identité a été confirmée à distance PUIS écrite ;
     *  - **déjà conforme** — la colonne portait déjà exactement cette valeur : c'est
     *    un no-op, et il n'émet AUCUN appel (idempotence) ;
     *  - **échec nommé** — utilisateur SE5 inconnu, configuration/mode inutilisable,
     *    identité **déjà détenue par un autre login SE5**, ou identité non confirmée
     *    par l'instance. Rien n'est écrit.
     */
    public function link(string $login, string $nextcloudUserId): NextcloudResult
    {
        $login = trim($login);
        $nextcloudUserId = trim($nextcloudUserId);

        if ($nextcloudUserId === '') {
            return NextcloudResult::failed(
                NextcloudFailure::Absent,
                'Rattachement refusé : aucun identifiant Nextcloud fourni.',
            );
        }

        $user = $this->findUser($login);

        if ($user === null) {
            return NextcloudResult::failed(
                NextcloudFailure::Absent,
                sprintf('Rattachement refusé : aucun utilisateur SE5 nommé « %s ».', $login),
            );
        }

        if ((string) ($user->nextcloud_user_id ?? '') === $nextcloudUserId) {
            // Idempotence : rejouer le même rattachement ne parle pas à l'instance
            // et n'écrit rien. Une commande d'exploitation se rejoue.
            return NextcloudResult::conforming(
                sprintf('« %s » est déjà rattaché à l\'identité Nextcloud « %s ».', $login, $nextcloudUserId),
            );
        }

        // Une identité déjà portée par QUELQU'UN D'AUTRE se refuse AVANT le
        // round-trip : c'est un fait local, et il ne dépend pas de l'instance.
        $held = $this->heldByAnotherUser($nextcloudUserId, $login);

        if ($held !== null) {
            return self::alreadyHeld($nextcloudUserId, $held);
        }

        $verification = $this->verifyRemotely($nextcloudUserId);

        if ($verification->isFailure()) {
            return $verification;
        }

        // On écrit l'identifiant **tel que l'instance l'écrit**, pas tel qu'il a été
        // saisi : c'est elle qui fait autorité sur l'orthographe de ses comptes, et
        // un identifiant à la casse approximative ne désignerait rien.
        $confirmed = is_string($verification->value('id')) && $verification->value('id') !== ''
            ? (string) $verification->value('id')
            : $nextcloudUserId;

        // Second contrôle : l'instance a pu rendre une ORTHOGRAPHE différente de la
        // saisie (`p.durand` → `P.Durand`), et c'est cette orthographe-là qui est
        // écrite. La garde d'entrée ne l'avait donc pas vue.
        $held = $this->heldByAnotherUser($confirmed, $login);

        if ($held !== null) {
            return self::alreadyHeld($confirmed, $held);
        }

        $user->nextcloud_user_id = $confirmed;
        $user->saveQuietly();

        Log::info('nextcloud.identity.linked', ['login' => $login, 'nextcloud_user_id' => $confirmed]);

        return NextcloudResult::ok(
            ['id' => $confirmed],
            null,
            null,
            sprintf('« %s » est désormais rattaché à l\'identité Nextcloud « %s ».', $login, $confirmed),
        );
    }

    /**
     * Détache l'utilisateur de son identité Nextcloud — la colonne redevient nulle.
     *
     * **Rien n'est supprimé côté instance** (D9) : le compte Nextcloud, ses fichiers
     * et ses partages ne sont pas touchés. On efface un CACHE, et la prochaine
     * résolution le reconstruira si elle le peut.
     */
    public function clear(string $login): NextcloudResult
    {
        $login = trim($login);
        $user = $this->findUser($login);

        if ($user === null) {
            return NextcloudResult::failed(
                NextcloudFailure::Absent,
                sprintf('Détachement refusé : aucun utilisateur SE5 nommé « %s ».', $login),
            );
        }

        if ((string) ($user->nextcloud_user_id ?? '') === '') {
            return NextcloudResult::conforming(
                sprintf('« %s » n\'est rattaché à aucune identité Nextcloud.', $login),
            );
        }

        $previous = (string) $user->nextcloud_user_id;
        $user->nextcloud_user_id = null;
        $user->saveQuietly();

        Log::info('nextcloud.identity.cleared', ['login' => $login, 'previous' => $previous]);

        return NextcloudResult::ok(
            [],
            null,
            null,
            sprintf(
                'Rattachement de « %s » retiré (l\'identité « %s » n\'est plus mise en cache ; rien n\'a '
                . 'été modifié côté Nextcloud).',
                $login,
                $previous,
            ),
        );
    }

    /** L'identité actuellement mise en cache, ou `null`. */
    public function current(string $login): ?string
    {
        $user = $this->findUser(trim($login));
        $value = (string) ($user?->nextcloud_user_id ?? '');

        return $value === '' ? null : $value;
    }

    // =========================================================================
    // Interne
    // =========================================================================

    /**
     * Confirmation à distance, PAR MODE. Le refus nomme la cause — et l'absence de
     * confirmation n'écrit jamais.
     */
    private function verifyRemotely(string $nextcloudUserId): NextcloudResult
    {
        $mode = $this->factory->mode();

        try {
            if ($mode === NextcloudInstanceMode::Delegue) {
                return $this->factory->makeDelegate()->findUserByExactId($nextcloudUserId);
            }

            $direct = $this->factory->make()->getUser($nextcloudUserId);
        } catch (NextcloudConfigurationException $e) {
            return NextcloudResult::failed(
                NextcloudFailure::Absent,
                'Rattachement refusé : ' . $e->getMessage(),
            );
        }

        if ($direct->isFailure()) {
            return $direct->failure === NextcloudFailure::Absent
                ? NextcloudResult::failed(
                    NextcloudFailure::Absent,
                    sprintf(
                        'Rattachement refusé : l\'instance ne connaît aucun compte « %s ». Rien n\'a été '
                        . 'écrit — une identité non confirmée mettrait un futur changement de mot de passe '
                        . 'sur le compte de quelqu\'un d\'autre.',
                        $nextcloudUserId,
                    ),
                    $direct->httpStatus,
                    $direct->ocsStatusCode,
                )
                : $direct;
        }

        $id = $direct->value('id');

        return NextcloudResult::ok(
            ['id' => is_string($id) && $id !== '' ? $id : $nextcloudUserId],
            $direct->httpStatus,
            $direct->ocsStatusCode,
        );
    }

    /**
     * Le login SE5 qui détient déjà cette identité Nextcloud, ou `null` si elle est
     * libre. Le détenteur légitime lui-même n'est jamais son propre conflit.
     */
    private function heldByAnotherUser(string $nextcloudUserId, string $login): ?string
    {
        $holder = User::query()
            ->where('nextcloud_user_id', $nextcloudUserId)
            ->where('login', '!=', $login)
            ->value('login');

        return is_string($holder) && $holder !== '' ? $holder : null;
    }

    /**
     * Le refus NOMME le détenteur : sans son login, l'exploitant ne sait ni où
     * chercher, ni quoi détacher.
     *
     * `Refus` plutôt qu'`Absent` : la cible existe très probablement côté instance —
     * ce qui est refusé, c'est le geste, et il l'est par SE5. Le ranger dans
     * « cible absente » enverrait chercher un compte manquant qui ne l'est pas.
     */
    private static function alreadyHeld(string $nextcloudUserId, string $holder): NextcloudResult
    {
        return NextcloudResult::failed(
            NextcloudFailure::Refus,
            sprintf(
                'Rattachement refusé : l\'identité Nextcloud « %s » est déjà rattachée à l\'utilisateur '
                . 'SE5 « %s ». Rien n\'a été écrit — deux comptes SE5 pointant la même identité feraient '
                . 'qu\'un changement de mot de passe de l\'un écraserait le compte de l\'autre. Si le '
                . 'rattachement est à déplacer, détachez d\'abord le détenteur : '
                . '`php artisan nextcloud:identity %s --clear`.',
                $nextcloudUserId,
                $holder,
                $holder,
            ),
        );
    }

    private function findUser(string $login): ?User
    {
        if ($login === '') {
            return null;
        }

        return User::query()->where('login', $login)->first();
    }
}
