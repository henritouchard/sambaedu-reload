<?php

declare(strict_types=1);

namespace App\Services\OpenCloud;

use App\Exceptions\OpenCloud\OpenCloudConfigurationException;
use App\Models\User;

/**
 * LE RATTACHEMENT D'IDENTITÉ — le SEUL écrivain du cache, et sa garde d'unicité.
 *
 * ---------------------------------------------------------------------------
 * **POURQUOI CE SERVICE EXISTE, ET POURQUOI IL EST SI PETIT.**
 *
 * Le backend traduit un sujet de plan en compte distant **par le cache, et par
 * rien d'autre** : cache vide ⇒ le nœud rend un échec NOMMÉ. Il faut donc bien
 * que quelque chose remplisse ce cache — sans quoi le message de remédiation
 * renverrait vers un geste qui n'existe pas, c'est-à-dire vers le défaut exact que
 * tout cet epic combat : un signal dont le destinataire est absent.
 *
 * Ce service est ce geste, et il ne fait que lui. Il ne provisionne AUCUN compte :
 * créer des comptes sur l'instance est un chantier à part, et un backend de plan
 * de fichiers qui saurait le faire finirait par en créer « à la volée » le jour où
 * le cache serait vide — rouvrant la règle de l'homonyme, payée cher ailleurs.
 *
 * ---------------------------------------------------------------------------
 * **DEUX GARDES, ET AUCUNE N'EST UNE COMMODITÉ.**
 *
 *  1. **L'identité est CONFIRMÉE À DISTANCE avant d'être écrite.** Un identifiant
 *     saisi de travers rattacherait l'utilisateur à rien — ou, pire, à quelqu'un.
 *     On demande donc à l'instance de la produire, et on n'écrit que ce qu'elle a
 *     rendu (sa valeur RELUE, jamais celle saisie).
 *  2. **UNE IDENTITÉ N'EST PORTÉE QUE PAR UN SEUL UTILISATEUR SE5.** Sans cette
 *     garde, deux logins pourraient désigner le même compte distant, et l'octroi
 *     nominatif du dossier personnel d'un élève atterrirait chez un tiers. Le refus
 *     NOMME le login qui détient déjà l'identité — il ne dit pas « impossible ».
 *     L'index unique en base est la défense EN PROFONDEUR, pas la seule.
 *
 * **Détacher n'est jamais destructeur** : le cache redevient nul, et rien n'est
 * supprimé côté instance (D9 — aucune suppression implicite).
 */
final class OpenCloudIdentityLinker
{
    public function __construct(private readonly OpenCloudClientFactory $factory) {}

    /** L'identité actuellement en cache, ou `null`. Lecture PURE. */
    public function current(User $user): ?string
    {
        $cached = trim((string) ($user->opencloud_user_id ?? ''));

        return $cached === '' ? null : $cached;
    }

    /**
     * Rattache un utilisateur SE5 à une identité OpenCloud, après confirmation.
     *
     * L'identifiant fourni peut être l'UUID du compte **ou** son identifiant de
     * connexion : dans les deux cas, c'est l'UUID RELU qui est écrit. Ce détour est
     * imposé par la mesure — l'API refuse de filtrer sur l'identifiant de connexion
     * (`unsupported filter`), donc on énumère l'annuaire et on apparie.
     */
    public function link(User $user, string $identifier): OpenCloudResult
    {
        $identifier = trim($identifier);

        if ($identifier === '') {
            return OpenCloudResult::failed(
                OpenCloudFailure::Absent,
                'Aucun identifiant fourni : rien n\'a été rattaché.',
            );
        }

        if ($this->current($user) === $identifier) {
            return OpenCloudResult::conforming(sprintf(
                'L\'utilisateur « %s » est déjà rattaché à cette identité : rien n\'a été écrit, et aucun '
                . 'appel n\'a été émis.',
                $user->login,
            ));
        }

        try {
            $transport = $this->factory->transport();
        } catch (OpenCloudConfigurationException $e) {
            return OpenCloudResult::failed(OpenCloudFailure::Injoignable, $e->getMessage());
        }

        $listed = (new \App\Services\Filesystem\Backend\OpenCloud\OpenCloudDirectoryClient($transport))->listUsers();
        if ($listed->isFailure()) {
            return $listed;
        }

        $confirmed = null;
        foreach ($listed->entries() as $account) {
            $id = (string) ($account['id'] ?? '');
            $login = (string) ($account['onPremisesSamAccountName'] ?? '');
            if ($id === '') {
                continue;
            }
            if ($id === $identifier || mb_strtolower($login) === mb_strtolower($identifier)) {
                // On retient la valeur RELUE, jamais celle saisie.
                $confirmed = $id;
                break;
            }
        }

        if ($confirmed === null) {
            return OpenCloudResult::failed(
                OpenCloudFailure::Absent,
                sprintf(
                    'Aucun compte « %s » sur l\'instance : le rattachement est refusé, et rien n\'a été '
                    . 'écrit. Un identifiant non confirmé rattacherait l\'utilisateur à rien — ou à '
                    . 'quelqu\'un d\'autre.',
                    $identifier,
                ),
            );
        }

        $holder = $this->holderOf($confirmed, (int) $user->id);
        if ($holder !== null) {
            return OpenCloudResult::failed(
                OpenCloudFailure::Refus,
                sprintf(
                    'L\'identité OpenCloud « %s » est déjà portée par « %s » : une identité n\'appartient '
                    . 'qu\'à un seul utilisateur SE5. Détachez-la de « %s » avant de la rattacher à « %s ».',
                    $confirmed,
                    $holder,
                    $holder,
                    $user->login,
                ),
            );
        }

        if ($this->current($user) === $confirmed) {
            return OpenCloudResult::conforming(sprintf(
                'L\'utilisateur « %s » était déjà rattaché à cette identité.',
                $user->login,
            ));
        }

        $user->opencloud_user_id = $confirmed;
        $user->saveQuietly();

        return OpenCloudResult::ok(
            ['opencloud_user_id' => $confirmed],
            null,
            sprintf('L\'utilisateur « %s » est rattaché à l\'identité « %s ».', $user->login, $confirmed),
        );
    }

    /**
     * Détache. **Rien n'est supprimé côté instance** — ni compte, ni fichier, ni
     * octroi. Seul le cache redevient nul.
     */
    public function unlink(User $user): OpenCloudResult
    {
        if ($this->current($user) === null) {
            return OpenCloudResult::conforming(sprintf(
                'L\'utilisateur « %s » n\'était rattaché à aucune identité.',
                $user->login,
            ));
        }

        $user->opencloud_user_id = null;
        $user->saveQuietly();

        return OpenCloudResult::ok([], null, sprintf(
            'L\'utilisateur « %s » est détaché. Rien n\'a été supprimé côté instance.',
            $user->login,
        ));
    }

    /** Le login SE5 qui détient déjà cette identité, ou `null` si elle est libre. */
    private function holderOf(string $identity, int $exceptUserId): ?string
    {
        $holder = User::query()
            ->where('opencloud_user_id', $identity)
            ->where('id', '!=', $exceptUserId)
            ->value('login');

        return is_string($holder) && $holder !== '' ? $holder : null;
    }
}
