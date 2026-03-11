<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Configuration de sécurité et politiques de mot de passe
 */
final readonly class SecurityConfig
{
    public function __construct(
        /** Politique CN activée (1 = oui) */
        public bool $cnPolicy,

        /** Politique de mot de passe activée (1 = oui) */
        public bool $pwdPolicy,

        /** Vérification des mots de passe activée */
        public bool $checkPasswords,

        /** Vérification AD activée */
        public bool $adCheck,

        /** Clé SE4 pour les opérations sécurisées */
        public string $se4Key,

        /** Clé publique SSH SE4 */
        public string $se4PubKey,
    ) {
    }
}
