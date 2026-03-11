<?php

namespace App\Services;

use App\Facades\SEConfig;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Service unifié pour la gestion des mots de passe
 * Fusionne PasswordPolicyService et PasswordWordService
 */
class PasswordService
{
    private string $wordsFile = 'password_words.json';
    private array $defaultNouns;
    private array $defaultAdjectives;

    public function __construct()
    {
        // Listes par défaut style Ubuntu : nom-adjectif
        $this->defaultNouns = [
            "chat",
            "chien",
            "lapin",
            "renard",
            "souris",
            "pizza",
            "cafe",
            "gateau",
            "image",
            "source",
            "chaine",
            "eponge",
            "pied",
            "coeur",
            "carreau",
            "trefle",
            "pique",
            "valet",
            "fonction",
            "hasard"
        ];

        $this->defaultAdjectives = [
            "bleu",
            "blanc",
            "rouge",
            "jaune",
            "vert",
            "joyeux",
            "rapide",
            "lent",
            "grand",
            "petit",
            "doux",
            "fort",
            "sage",
            "vif",
            "calme",
            "brave",
            "gentil",
            "malin",
            "fier",
            "noble"
        ];
    }

    /**
     * Récupère les règles de mot de passe
     * Remplace get_password_rule()
     * 
     * @return array
     */
    public function getRules(): array
    {
        try {
            $pwdPolicy = SEConfig::get('pwdPolicy', 0);

            $rules = [
                'min-pwd-length' => 8,
                'complexity' => 'off',
                'pwdPolicy' => $pwdPolicy
            ];

            // Appliquer les règles selon la politique
            switch ($pwdPolicy) {
                case 0:
                case 1:
                    // Politique par défaut : date de naissance ou mot de passe aléatoire
                    $rules['min-pwd-length'] = SEConfig::get('min_password_length', 8);
                    $rules['complexity'] = SEConfig::get('password_complexity', 'off');
                    break;

                case 2:
                    // Mot de passe aléatoire obligatoire
                    $rules['min-pwd-length'] = SEConfig::get('random_password_length', 12);
                    $rules['complexity'] = SEConfig::get('random_password_complexity', 'on');
                    break;

                case 3:
                    // Code d'activation
                    $rules['min-pwd-length'] = strlen(SEConfig::get('activation_code', ''));
                    $rules['complexity'] = 'off';
                    break;

                default:
                    // Politique personnalisée
                    $rules['min-pwd-length'] = SEConfig::get('min_password_length', 8);
                    $rules['complexity'] = SEConfig::get('password_complexity', 'off');
            }

            return $rules;

        } catch (\Exception $e) {
            Log::error('PasswordService getRules error: ' . $e->getMessage());

            return [
                'min-pwd-length' => 8,
                'complexity' => 'off',
                'pwdPolicy' => 0
            ];
        }
    }

    /**
     * Obtient la politique de mot de passe complète
     * 
     * @return array
     */
    public function getPolicy(): array
    {
        try {
            $rules = $this->getRules();
            $pwdPolicy = $rules['pwdPolicy'];

            $length = $rules['min-pwd-length'];
            $complexity = ($rules['complexity'] === 'on');

            $description = '';
            switch ($pwdPolicy) {
                case 0:
                case 1:
                    $description = "date de naissance (YYYYMMDD). Si ni la date de naissance ni le mot de passe ne sont renseignées, un mot de passe aléatoire sera généré";
                    break;
                case 2:
                    $description = "aléatoire ($length car.)";
                    break;
                case 3:
                    $description = "code d'activation " . SEConfig::get('activation_code', '');
                    break;
                default:
                    $description = "personnalisée (min. $length car.)";
            }

            return [
                'policy' => $pwdPolicy,
                'min_length' => (int) $length,
                'complexity' => $complexity,
                'description' => $description,
                'rules' => $rules
            ];

        } catch (\Exception $e) {
            Log::error('PasswordService getPolicy error: ' . $e->getMessage());

            return [
                'policy' => 0,
                'min_length' => 8,
                'complexity' => false,
                'description' => 'Mot de passe aléatoire',
                'rules' => []
            ];
        }
    }

    /**
     * Génère un mot de passe aléatoire selon les règles
     * 
     * @return string
     */
    public function generateRandomPassword(): string
    {
        try {
            $rules = $this->getRules();
            $length = $rules['min-pwd-length'] ?? 8;
            $complexity = ($rules['complexity'] === 'on');

            // Si la complexité est désactivée, utiliser la génération lisible
            if (!$complexity) {
                return $this->generateReadablePassword($length);
            }

            // Sinon, générer un mot de passe complexe
            return $this->generateComplexPassword($length);

        } catch (\Exception $e) {
            Log::error('PasswordService generateRandomPassword error: ' . $e->getMessage());

            // Fallback sur la méthode lisible
            return $this->generateReadablePassword(8);
        }
    }

    /**
     * Génère un mot de passe complexe avec caractères spéciaux
     * Reproduit le comportement legacy pour les mots de passe complexes
     * 
     * @param int $length
     * @return string
     */
    private function generateComplexPassword(int $length): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_+-=[]{}|;:,.<>?';

        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $password;
    }

    /**
     * Génère un mot de passe lisible style Ubuntu (Nom-Adjectif-Chiffre)
     * Exemple: Chat-Joyeux-42, Lapin-Rapide-17
     * 
     * @param int $nbChars Longueur minimale souhaitée
     * @param bool $complex Ajouter des substitutions de caractères
     * @param bool $easy Utiliser les listes prédéfinies (true) ou générées (false)
     * @return string
     */
    public function generateReadablePassword(int $nbChars = 8, bool $complex = false, bool $easy = true): string
    {
        try {
            if (!$easy) {
                // Mode legacy: mots aléatoires
                return $this->generateLegacyReadablePassword($nbChars, $complex);
            }

            // Mode Ubuntu: Nom-Adjectif-Chiffre
            $nouns = $this->getNouns();
            $adjectives = $this->getAdjectives();

            $specials = ["+", "-", "*", "$", "!"];
            $map = ["o" => 0, "s" => 5, "e" => 3];

            // Sélectionner un nom
            $noun = $nouns[rand(0, count($nouns) - 1)];

            // Sélectionner un adjectif
            $adjective = $adjectives[rand(0, count($adjectives) - 1)];

            // Générer un nombre aléatoire (2 chiffres)
            $number = rand(10, 99);

            // Construire le mot de passe: Nom-Adjectif-Nombre
            $separator = $complex ? $specials[rand(0, 4)] : "-";
            $password = ucfirst($noun) . $separator . ucfirst($adjective) . $separator . $number;

            // Appliquer les substitutions si complexité requise
            if ($complex) {
                foreach ($map as $l => $replacement) {
                    $res = preg_replace("/" . $l . "/", $replacement, $password, 1);
                    if ($res != $password) {
                        return $res;
                    }
                }
            }

            return $password;

        } catch (\Exception $e) {
            Log::error('PasswordService generateReadablePassword error: ' . $e->getMessage());
            return substr(md5(uniqid()), 0, max($nbChars, 8));
        }
    }

    /**
     * Génère un mot de passe lisible selon l'ancienne méthode legacy
     * Conservé pour rétrocompatibilité
     * 
     * @param int $nbChars
     * @param bool $complex
     * @return string
     */
    private function generateLegacyReadablePassword(int $nbChars = 8, bool $complex = false): string
    {
        $mots = $this->getWordList(false);

        $specials = ["+", "-", "*", "$", "!"];
        $map = ["o" => 0, "s" => 5, "e" => 3];

        // Sélectionner le premier mot
        $m1 = $mots[rand(0, count($mots) - 1)];
        while (strlen($m1) > $nbChars - 3) {
            $m1 = $mots[rand(0, count($mots) - 1)];
        }

        // Calculer la longueur restante pour le deuxième mot
        $r1 = $nbChars - strlen($m1) - 1;
        $m2 = $mots[rand(0, count($mots) - 1)];
        $i = 0;
        while ($i++ < 100 && (strlen($m2) > $r1 || (strlen($m2) > $r1 - 3 && strlen($m2) < $r1))) {
            $m2 = $mots[rand(0, count($mots) - 1)];
        }

        // Construire le mot de passe
        $s = $complex ? $specials[rand(0, 4)] : "-";
        $r = ucfirst($m1) . $s . ucfirst($m2);

        // Appliquer les substitutions si complexité requise
        if ($complex) {
            foreach ($map as $l => $replacement) {
                $res = preg_replace("/" . $l . "/", $replacement, $r, 1);
                if ($res != $r) {
                    return $res;
                }
            }
        }

        return $r . "0";
    }

    /**
     * Détermine le mot de passe final selon la politique
     * 
     * @param string|null $password Mot de passe fourni
     * @param string|null $birthdate Date de naissance (YYYYMMDD)
     * @return string
     */
    public function determinePassword(?string $password, ?string $birthdate): string
    {
        try {
            $rules = $this->getRules();
            $pwdPolicy = $rules['pwdPolicy'];

            // Si un mot de passe est fourni, l'utiliser
            if (!empty($password)) {
                return $password;
            }

            switch ($pwdPolicy) {
                case 0:
                case 1:
                    // Utiliser la date de naissance si fournie
                    if (!empty($birthdate) && $this->isValidBirthdate($birthdate)) {
                        return $birthdate;
                    }
                    // Sinon générer un mot de passe aléatoire
                    return $this->generateRandomPassword();

                case 2:
                    // Mot de passe aléatoire obligatoire
                    return $this->generateRandomPassword();

                case 3:
                    // Code d'activation
                    return SEConfig::get('activation_code') ?? $this->generateRandomPassword();

                default:
                    return $this->generateRandomPassword();
            }

        } catch (\Exception $e) {
            Log::error('PasswordService determinePassword error: ' . $e->getMessage());
            return $this->generateRandomPassword();
        }
    }

    /**
     * Vérifie si une date de naissance est valide
     * 
     * @param string $birthdate Date au format YYYYMMDD
     * @return bool
     */
    private function isValidBirthdate(string $birthdate): bool
    {
        // Vérifier le format YYYYMMDD
        if (!preg_match('/^[0-9]{8}$/', $birthdate)) {
            return false;
        }

        $year = substr($birthdate, 0, 4);
        $month = substr($birthdate, 4, 2);
        $day = substr($birthdate, 6, 2);

        return checkdate((int) $month, (int) $day, (int) $year) &&
            $year >= 1900 && $year <= date('Y');
    }

    /**
     * Vérifie si un mot de passe respecte les règles
     * 
     * @param string $password Mot de passe à vérifier
     * @return array Résultat de la validation ['valid' => bool, 'errors' => array]
     */
    public function validatePassword(string $password): array
    {
        $rules = $this->getRules();
        $errors = [];

        // Vérifier la longueur minimale
        if (strlen($password) < $rules['min-pwd-length']) {
            $errors[] = "Le mot de passe doit contenir au moins {$rules['min-pwd-length']} caractères";
        }

        // Vérifier la complexité si requise
        if ($rules['complexity'] === 'on') {
            if (!preg_match('/[a-z]/', $password)) {
                $errors[] = "Le mot de passe doit contenir au moins une lettre minuscule";
            }
            if (!preg_match('/[A-Z]/', $password)) {
                $errors[] = "Le mot de passe doit contenir au moins une lettre majuscule";
            }
            if (!preg_match('/[0-9]/', $password)) {
                $errors[] = "Le mot de passe doit contenir au moins un chiffre";
            }
            if (!preg_match('/[!@#$%^&*()_+\-=\[\]{}|;:,.<>?]/', $password)) {
                $errors[] = "Le mot de passe doit contenir au moins un caractère spécial";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    // ===== GESTION DES LISTES DE MOTS =====

    /**
     * Récupère la liste de noms
     * 
     * @return array
     */
    public function getNouns(): array
    {
        try {
            $words = $this->loadFromJsonFile();
            if ($words !== null && isset($words['nouns'])) {
                return $words['nouns'];
            }
            return $this->defaultNouns;
        } catch (\Exception $e) {
            Log::error('PasswordService getNouns error: ' . $e->getMessage());
            return $this->defaultNouns;
        }
    }

    /**
     * Récupère la liste d'adjectifs
     * 
     * @return array
     */
    public function getAdjectives(): array
    {
        try {
            $words = $this->loadFromJsonFile();
            if ($words !== null && isset($words['adjectives'])) {
                return $words['adjectives'];
            }
            return $this->defaultAdjectives;
        } catch (\Exception $e) {
            Log::error('PasswordService getAdjectives error: ' . $e->getMessage());
            return $this->defaultAdjectives;
        }
    }

    /**
     * Récupère la liste de mots pour la génération (rétrocompatibilité)
     * Combine noms et adjectifs
     * 
     * @param bool $easy
     * @return array
     */
    public function getWordList(bool $easy = true): array
    {
        try {
            if ($easy) {
                // Combiner noms et adjectifs pour rétrocompatibilité
                return array_merge($this->getNouns(), $this->getAdjectives());
            } else {
                return $this->generateRandomWords();
            }
        } catch (\Exception $e) {
            Log::error('PasswordService getWordList error: ' . $e->getMessage());
            return array_merge($this->defaultNouns, $this->defaultAdjectives);
        }
    }

    /**
     * Charge la liste de mots depuis un fichier JSON
     * 
     * @return array|null
     */
    private function loadFromJsonFile(): ?array
    {
        try {
            if (Storage::disk('local')->exists($this->wordsFile)) {
                $content = Storage::disk('local')->get($this->wordsFile);
                return json_decode($content, true);
            }
        } catch (\Exception $e) {
            Log::warning('Impossible de charger le fichier de mots: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Génère des mots aléatoires (mode non-easy)
     * Reproduit le comportement legacy
     * 
     * @return array
     */
    public function generateRandomWords(): array
    {
        $mots = [];
        $lettres = "azertyuipqsdfghjkmwxcvbn";
        for ($i = 0; $i < 50; $i++) {
            $mot = "";
            $long = rand(4, 7);
            for ($j = 0; $j < $long; $j++) {
                $mot .= substr($lettres, rand(0, strlen($lettres) - 1), 1);
            }
            $mots[$i] = $mot;
        }
        return $mots;
    }

    /**
     * Sauvegarde une liste de mots personnalisée dans un fichier JSON
     * 
     * @param array $nouns
     * @param array $adjectives
     * @return bool
     */
    public function saveCustomWordList(array $nouns, array $adjectives): bool
    {
        try {
            $data = [
                'nouns' => $nouns,
                'adjectives' => $adjectives,
                'created_at' => now()->toISOString(),
                'version' => '1.0'
            ];

            Storage::disk('local')->put($this->wordsFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return true;

        } catch (\Exception $e) {
            Log::error('PasswordService saveCustomWordList error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Vérifie si un fichier de mots personnalisé existe
     * 
     * @return bool
     */
    public function hasCustomWordList(): bool
    {
        return Storage::disk('local')->exists($this->wordsFile);
    }

    /**
     * Supprime le fichier de mots personnalisé
     * 
     * @return bool
     */
    public function removeCustomWordList(): bool
    {
        try {
            if ($this->hasCustomWordList()) {
                Storage::disk('local')->delete($this->wordsFile);
            }
            return true;
        } catch (\Exception $e) {
            Log::error('PasswordService removeCustomWordList error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Retourne les listes par défaut
     * 
     * @return array
     */
    public function getDefaultWords(): array
    {
        return [
            'nouns' => $this->defaultNouns,
            'adjectives' => $this->defaultAdjectives
        ];
    }
}
