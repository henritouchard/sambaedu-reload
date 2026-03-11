<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Configuration des politiques de mot de passe
 * 
 * DTO immuable contenant les règles de validation des mots de passe.
 */
final readonly class PasswordPolicyConfig
{
    public function __construct(
        public int $minLength = 8,
        public int $maxLength = 128,
        public bool $requireUppercase = true,
        public bool $requireLowercase = true,
        public bool $requireDigit = true,
        public bool $requireSpecialChar = false,
        public int $expirationDays = 0,        // 0 = pas d'expiration
        public int $historyCount = 0,          // Nombre de mots de passe à mémoriser
        public bool $checkDictionary = false,  // Vérification dictionnaire
    ) {
    }

    /**
     * Vérifie si un mot de passe respecte la politique
     * 
     * @param string $password Mot de passe à vérifier
     * @return array{valid: bool, errors: string[]} Résultat de la validation
     */
    public function validate(string $password): array
    {
        $errors = [];

        if (strlen($password) < $this->minLength) {
            $errors[] = "Le mot de passe doit contenir au moins {$this->minLength} caractères";
        }

        if (strlen($password) > $this->maxLength) {
            $errors[] = "Le mot de passe ne doit pas dépasser {$this->maxLength} caractères";
        }

        if ($this->requireUppercase && !preg_match('/[A-Z]/', $password)) {
            $errors[] = "Le mot de passe doit contenir au moins une majuscule";
        }

        if ($this->requireLowercase && !preg_match('/[a-z]/', $password)) {
            $errors[] = "Le mot de passe doit contenir au moins une minuscule";
        }

        if ($this->requireDigit && !preg_match('/[0-9]/', $password)) {
            $errors[] = "Le mot de passe doit contenir au moins un chiffre";
        }

        if ($this->requireSpecialChar && !preg_match('/[^a-zA-Z0-9]/', $password)) {
            $errors[] = "Le mot de passe doit contenir au moins un caractère spécial";
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Retourne une description lisible de la politique
     */
    public function getDescription(): string
    {
        $rules = [];
        $rules[] = "Entre {$this->minLength} et {$this->maxLength} caractères";

        if ($this->requireUppercase) {
            $rules[] = "Au moins une majuscule";
        }

        if ($this->requireLowercase) {
            $rules[] = "Au moins une minuscule";
        }

        if ($this->requireDigit) {
            $rules[] = "Au moins un chiffre";
        }

        if ($this->requireSpecialChar) {
            $rules[] = "Au moins un caractère spécial";
        }

        return implode(', ', $rules);
    }

    /**
     * Vérifie si l'expiration des mots de passe est activée
     */
    public function hasExpiration(): bool
    {
        return $this->expirationDays > 0;
    }

    /**
     * Vérifie si l'historique des mots de passe est activé
     */
    public function hasHistory(): bool
    {
        return $this->historyCount > 0;
    }
}
