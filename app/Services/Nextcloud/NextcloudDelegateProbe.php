<?php

declare(strict_types=1);

namespace App\Services\Nextcloud;

/**
 * Story 61.2 — LA SONDE DU MODE DÉLÉGUÉ : trois diagnostics, EN LECTURE SEULE.
 *
 * Les trois causes réelles se corrigent à trois endroits différents :
 *  - **instance injoignable** → réseau, DNS, TLS, ou l'instance est à l'arrêt ;
 *  - **identifiants porteurs refusés** → l'app password du compte porteur est
 *    révoqué, faux, ou l'identifiant ne désigne aucun compte de l'instance ;
 *  - **API de partage désactivée** → l'instance a éteint le partage
 *    (`sharing.enabled = no` côté administration). Aucun octroi n'y sera possible,
 *    et c'est un réglage d'instance : rien que SE5 puisse corriger à distance.
 *
 * ---------------------------------------------------------------------------
 * **CE QUE CETTE SONDE NE PEUT PAS CONSTATER — ET QU'ELLE DIT.** Deux capacités
 * décident du mode délégué et ne s'observent qu'À L'ÉCRITURE : la création de
 * l'arborescence dans l'espace du porteur, et l'émission d'un octroi. Fabriquer un
 * dossier d'épreuve « pour tester » serait un geste qui MODIFIE l'instance sous
 * couvert de la sonder — exactement le reproche déjà encaissé une fois sur ce
 * dépôt (un enregistrement DNS effacé par un test « inoffensif »), et exactement
 * la raison pour laquelle le quatrième diagnostic de 61.1 n'est pas dans sa sonde
 * non plus.
 *
 * La sonde ne les affirme donc ni dans un sens ni dans l'autre : son message VERT
 * dit qu'elles se constateront au premier usage réel (61.3). Un vert qui
 * promettrait plus que ce qu'il a mesuré serait un signal qui n'atteint pas son
 * destinataire.
 * ---------------------------------------------------------------------------
 *
 * **Aucun secret n'entre dans un message** : ils sont construits à partir de
 * l'identifiant du porteur (non secret) et de la cause. Un test l'épingle.
 */
final class NextcloudDelegateProbe
{
    private function __construct(
        public readonly bool $reachable,
        /** Le compte porteur s'authentifie-t-il et son espace répond-il ? */
        public readonly bool $authenticated,
        /** L'API de partage est-elle activée sur l'instance ? */
        public readonly bool $sharingEnabled,
        public readonly ?NextcloudFailure $failure,
        /** Message destiné à l'exploitant. **Jamais** le secret. */
        public readonly string $message,
        public readonly ?int $httpStatus = null,
    ) {
    }

    public static function ok(string $delegateUser): self
    {
        return new self(
            true,
            true,
            true,
            null,
            sprintf(
                'Instance joignable, compte porteur « %s » authentifié, partage activé sur l\'instance. '
                . 'Ce que cette vérification ne peut PAS établir sans écrire : que ce compte pourra '
                . 'réellement créer l\'arborescence et émettre les octrois — cela se constatera au premier '
                . 'usage réel, jamais par une écriture d\'épreuve.',
                $delegateUser,
            ),
        );
    }

    public static function unreachable(string $detail): self
    {
        return new self(false, false, false, NextcloudFailure::Injoignable, $detail);
    }

    /** Les identifiants du porteur sont refusés par l'instance. */
    public static function credentialsRefused(string $detail, ?int $httpStatus = null): self
    {
        return new self(true, false, false, NextcloudFailure::Privilege, $detail, $httpStatus);
    }

    /** Le compte s'authentifie, mais le partage est éteint sur l'instance. */
    public static function sharingDisabled(string $detail, ?int $httpStatus = null): self
    {
        return new self(true, true, false, NextcloudFailure::Absent, $detail, $httpStatus);
    }

    /**
     * Refus que la sonde ne sait pas ranger dans les trois cas connus. Il EXISTE et
     * il porte son relevé — un « ça ne marche pas » sans code enverrait chercher
     * ailleurs.
     */
    public static function rejected(string $detail, ?int $httpStatus = null): self
    {
        return new self(true, false, false, NextcloudFailure::Refus, $detail, $httpStatus);
    }

    /**
     * La configuration du porteur est incomplète : **aucun appel n'est émis**, et
     * le refus nomme ce qui manque (patron fail-closed de 61.1).
     */
    public static function misconfigured(string $detail): self
    {
        return new self(false, false, false, NextcloudFailure::Absent, $detail);
    }

    public function isOk(): bool
    {
        return $this->reachable && $this->authenticated && $this->sharingEnabled;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'ok' => $this->isOk(),
            'mode' => 'delegue',
            'reachable' => $this->reachable,
            'authenticated' => $this->authenticated,
            'sharing_enabled' => $this->sharingEnabled,
            'failure' => $this->failure?->value,
            'message' => $this->message,
            'http_status' => $this->httpStatus,
        ];
    }
}
