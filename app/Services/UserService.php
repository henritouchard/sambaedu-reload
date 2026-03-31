<?php

namespace App\Services;

use App\Config\LdapDnHelper;
use App\Config\SambaEduConfig;
use App\Models\User as SqlUserModel;
use App\Repositories\UserRepository;
use App\Repositories\OrganizationalUnitRepository;
use App\Repositories\EstablishmentRepository;
use App\Repositories\FunctionRepository;
use App\Repositories\ClassRepository;
use App\Services\PasswordService;
use App\LdapModels\LdapUser;
use App\Constants\Ldap\FunctionGroups;
use App\LdapModels\SambaEduGroup;
use App\Types\UserSearchCriteria;
use App\Types\PaginatedResult;
use App\Types\User;
use App\Constants\Ldap\MainGroups;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * Service de gestion des utilisateurs SE4FS
 * Interface avec LDAP Samba4 AD
 * 
 * Utilise exclusivement LdapRecord via les repositories et modèles
 */
class UserService
{
    public function __construct(
        private UserRepository $userRepository,
        private OrganizationalUnitRepository $ouRepository,
        private EstablishmentRepository $establishmentRepository,
        private FunctionRepository $functionRepository,
        private ClassRepository $classRepository,
        private PasswordService $passwordService,
        private SambaEduConfig $config
    ) {
    }


    /**
     * Recherche des utilisateurs dans Active Directory via le repository
     * 
     * OPTIMISÉ: Utilise searchWithFilters() qui pousse tous les filtres dans LDAP
     * 
     * @param string $search Terme de recherche générique
     * @param string|array $role Rôle(s) (string 'all' ou array ['profs', 'eleves'])
     * @param string|array $status Statut(s) (string 'all' ou array ['active', 'inactive'])
     * @param int $perPage
     * @param int $page
     * @param array $groups Groupes et classes fusionnés
     * @param string $searchLogin Recherche spécifique login
     * @param string $searchName Recherche spécifique nom/prénom
     * @return PaginatedResult
     */
    public function searchUsers(string $search = '', string|array $role = 'all', string|array $status = 'all', int $perPage = 20, int $page = 1, array $groups = [], string $searchLogin = '', string $searchName = ''): PaginatedResult
    {
        try {
            // Récupérer l'établissement de la session
            $establishmentCode = $this->config->getCurrentEstablishmentCode();

            // Filtres de recherche spécifiques (prioritaires)
            $loginSearch = !empty($searchLogin) ? $searchLogin : null;
            $nameSearch = !empty($searchName) ? $searchName : null;
            $genericSearch = (!empty($search) && empty($searchLogin) && empty($searchName)) ? $search : null;

            // Préparation des tableaux pour le DTO
            $rolesList = is_array($role) ? $role : ($role !== 'all' && !empty($role) ? [$role] : []);
            $statusesList = is_array($status) ? $status : ($status !== 'all' && !empty($status) ? [$status] : []);
            $groupsList = !empty($groups) ? $groups : [];

            // Création du DTO de critères
            $criteria = new UserSearchCriteria(
                genericSearch: $genericSearch,
                loginSearch: $loginSearch,
                nameSearch: $nameSearch,
                roles: $rolesList,
                statuses: $statusesList,
                groups: $groupsList,
                perPage: $perPage,
                page: $page
            );

            // Utiliser la nouvelle méthode optimisée du repository
            // Tous les filtres, tri et pagination sont gérés côté LDAP
            $result = $this->userRepository->searchWithFilters($criteria);

            return PaginatedResult::create(
                items: $result->users->all(), // Retourne un array d'objets User
                pagination: [
                    'current_page' => $result->currentPage,
                    'per_page' => $result->perPage,
                    'total' => $result->total,
                    'last_page' => $result->lastPage,
                    'from' => $result->from,
                    'to' => $result->to,
                    'has_more_pages' => $result->hasMorePages
                ],
                itemClass: User::class
            );

        } catch (\Exception $e) {
            Log::error('UserService searchUsers error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return PaginatedResult::create(
                items: [],
                pagination: [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => 0,
                    'last_page' => 1,
                    'from' => 0,
                    'to' => 0,
                    'has_more_pages' => false
                ],
                itemClass: User::class
            );
        }
    }

    public function getByLogin(string $login): ?User
    {
        return $this->userRepository->findByLogin($login);
    }

    /**
     * Récupère un utilisateur depuis la base SQL locale (table users), sans LDAP.
     */
    public function getByLoginFromSql(string $login): ?User
    {
        $sqlUser = SqlUserModel::query()->where('login', $login)->first();

        if (!$sqlUser) {
            return null;
        }

        $groups = $sqlUser->userGroups()->pluck('name')->all();
        $rights = is_array($sqlUser->ad_right_profiles) ? $sqlUser->ad_right_profiles : [];

        return new User(
            login: (string) $sqlUser->login,
            fullname: (string) ($sqlUser->fullname ?? $sqlUser->login),
            firstname: $sqlUser->firstname,
            lastname: $sqlUser->lastname,
            email: $sqlUser->email,
            etabCode: $sqlUser->school_code,
            isActive: (bool) $sqlUser->is_active,
            memberOf: $groups,
            groups: $groups,
            rights: $rights,
            dn: $sqlUser->dn,
            role: (string) ($sqlUser->role ?? 'autre'),
            isActiveUser: (bool) $sqlUser->is_active,
            isTrash: false,
            objectGuid: $sqlUser->ad_guid,
            objectGuidDisplay: $sqlUser->ad_guid,
        );
    }

    /**
     * Met à jour les informations personnelles d'un utilisateur
     *
     * Double-write: AD (source de vérité) puis PostgreSQL
     *
     * @param string $login Login de l'utilisateur
     * @param array $data Données à mettre à jour (prenom, nom, email, phone, description)
     * @return array ['success' => bool, 'message' => string]
     */
    public function updatePersonalInfo(string $login, array $data): array
    {
        // D1: Vérification des permissions côté service
        if (!Gate::allows('update-user')) {
            return ['success' => false, 'message' => 'Vous n\'avez pas les droits pour modifier cet utilisateur.'];
        }

        try {
            // Charger l'utilisateur LDAP
            $ldapUser = $this->userRepository->findLdapModelByLogin($login);
            if (!$ldapUser) {
                return ['success' => false, 'message' => 'Utilisateur introuvable dans l\'annuaire.'];
            }

            // Préparer les attributs LDAP à modifier
            $prenom = trim($data['prenom']);
            $nom = trim($data['nom']);

            $ldapUser->givenname = $prenom;
            $ldapUser->sn = $nom;
            $ldapUser->displayname = "{$prenom} {$nom}";

            if (array_key_exists('email', $data)) {
                $ldapUser->mail = $data['email'] ?: null;
            }
            if (array_key_exists('phone', $data)) {
                $ldapUser->telephonenumber = $data['phone'] ?: null;
            }
            if (array_key_exists('description', $data)) {
                $ldapUser->description = $data['description'] ?: null;
            }

            // Sauvegarder dans l'AD
            $ldapUser->save();

            // Invalider le cache
            $this->userRepository->invalidateCache($login);

            // Double-write SQL (non bloquant)
            $sqlWriteOk = true;
            try {
                SqlUserModel::updateOrCreate(
                    ['login' => $login],
                    [
                        'firstname' => $prenom,
                        'lastname' => $nom,
                        'fullname' => trim("{$prenom} {$nom}"),
                        'email' => $data['email'] ?? null,
                        'phone' => $data['phone'] ?? null,
                        'description' => $data['description'] ?? null,
                        'ad_synced_at' => now(),
                    ]
                );
            } catch (\Throwable $e) {
                $sqlWriteOk = false;
                Log::error('Échec double-write SQL pour updatePersonalInfo (AD = source de vérité, on continue)', [
                    'login' => $login,
                    'error' => $e->getMessage(),
                ]);
            }

            Log::info('User personal info updated', [
                'login' => $login,
                'fields' => array_keys($data),
                'operator' => auth()->user()?->login ?? 'system',
                'sql_synced' => $sqlWriteOk,
            ]);

            return ['success' => true, 'message' => 'Informations mises à jour.'];

        } catch (\Throwable $e) {
            Log::error('Erreur lors de la mise à jour des informations personnelles', [
                'login' => $login,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => 'Une erreur est survenue lors de la mise à jour.'];
        }
    }

    /**
     * Valide les données de mise à jour des informations personnelles.
     * Réutilisable par les controllers API et les composants Livewire.
     *
     * @param array $data
     * @return array Liste des messages d'erreur (vide si valide)
     */
    public function validatePersonalInfo(array $data): array
    {
        $errors = [];

        if (empty(trim($data['prenom'] ?? ''))) {
            $errors[] = 'Le prénom est requis.';
        } elseif (mb_strlen($data['prenom']) > 64) {
            $errors[] = 'Le prénom ne doit pas dépasser 64 caractères.';
        }
        if (empty(trim($data['nom'] ?? ''))) {
            $errors[] = 'Le nom est requis.';
        } elseif (mb_strlen($data['nom']) > 64) {
            $errors[] = 'Le nom ne doit pas dépasser 64 caractères.';
        }
        if (!empty($data['email'] ?? '') && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'L\'adresse email n\'est pas valide.';
        }
        if (!empty($data['phone'] ?? '') && mb_strlen($data['phone']) > 20) {
            $errors[] = 'Le numéro de téléphone ne doit pas dépasser 20 caractères.';
        }
        if (!empty($data['description'] ?? '') && mb_strlen($data['description']) > 1000) {
            $errors[] = 'La description ne doit pas dépasser 1000 caractères.';
        }

        return $errors;
    }

    /**
     * Formate un utilisateur depuis le modèle LdapRecord
     * Retourne un objet User (DTO) au lieu d'un tableau
     *
     * @param \App\LdapModels\LdapUser $ldapUser
     * @return \App\Types\User
     */
    private function formatLdapUserFromModel(\App\LdapModels\LdapUser $ldapUser): \App\Types\User
    {
        return $ldapUser->toBusinessObject();
    }

    /**
     * Vérifie si un utilisateur correspond aux filtres
     * 
     * @param \App\Types\User $user
     * @param string $role
     * @param string $status
     * @return bool
     */
    private function matchesFilters(\App\Types\User $user, string $role, string $status): bool
    {
        // Normaliser le rôle pour la comparaison
        $normalizedRole = $this->normalizeRole($role);

        // Filtre par rôle
        if ($normalizedRole !== 'all' && $user->role !== $normalizedRole) {
            return false;
        }

        // Normaliser le statut pour la comparaison
        $normalizedStatus = $this->normalizeStatus($status);

        // Filtre par statut
        if ($normalizedStatus === 'active' && !$user->isActiveUser) {
            return false;
        }
        if ($normalizedStatus === 'inactive' && $user->isActiveUser) {
            return false;
        }
        if ($normalizedStatus === 'trash' && !$user->isTrash) {
            return false;
        }

        return true;
    }

    /**
     * Normalise les valeurs de rôle pour correspondre aux valeurs du User DTO
     */
    private function normalizeRole(string $role): string
    {
        $roleMap = [
            'admin' => 'administratifs',
            'administrateur' => 'administratifs',
            'administratif' => 'administratifs',
            'admins' => 'administratifs',
            'professeur' => 'profs',
            'prof' => 'profs',
            'enseignant' => 'profs',
            'eleve' => 'eleves',
            'élève' => 'eleves',
            'personnel' => 'administratifs',
            'technicien' => 'administratifs',
        ];

        return $roleMap[strtolower($role)] ?? $role;
    }

    /**
     * Normalise les valeurs de statut
     */
    private function normalizeStatus(string $status): string
    {
        $statusMap = [
            'actif' => 'active',
            'inactif' => 'inactive',
            'suspendu' => 'inactive',
        ];

        return $statusMap[strtolower($status)] ?? $status;
    }

    /**
     * Récupère les premiers utilisateurs (pour affichage par défaut) avec pagination
     * Utilise le repository optimisé
     */
    public function getFirstUsers(int $perPage = 20, int $page = 1): array
    {
        try {
            $criteria = new UserSearchCriteria(
                statuses: ['active', 'inactive'], // Par défaut, on affiche les actifs et inactifs
                perPage: $perPage,
                page: $page
            );

            $result = $this->userRepository->searchWithFilters($criteria);

            return [
                'users' => $result->users->all(), // Retourne un array d'objets User
                'pagination' => [
                    'current_page' => $result->currentPage,
                    'per_page' => $result->perPage,
                    'total' => $result->total,
                    'last_page' => $result->lastPage,
                    'from' => $result->from,
                    'to' => $result->to,
                    'has_more_pages' => $result->hasMorePages
                ]
            ];

        } catch (\Exception $e) {
            Log::error('UserService getFirstUsers error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return [
                'users' => [],
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => 0,
                    'last_page' => 1,
                    'from' => 0,
                    'to' => 0,
                    'has_more_pages' => false
                ]
            ];
        }
    }

    /**
     * Récupère un utilisateur spécifique via le repository
     * 
     * @param string $username
     * @return \App\Types\User|null
     */
    public function getUser(string $username): ?\App\Types\User
    {
        try {
            // Utiliser le repository pour récupérer l'utilisateur
            $ldapUser = $this->userRepository->findLdapModelByLogin($username);

            if (!$ldapUser) {
                return null;
            }

            return $this->formatLdapUserFromModel($ldapUser);

        } catch (\Exception $e) {
            Log::error('UserService getUser error: ' . $e->getMessage(), [
                'username' => $username,
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Calcule les quotas utilisateur
     */
    public function getUserQuota(string $username): array
    {
        // TODO: Implémenter le calcul réel des quotas
        return [
            'total_mb' => 5000,
            'used_mb' => rand(1000, 4500),
            'percentage' => 0
        ];
    }

    /**
     * Vérifie si un utilisateur a dépassé son quota
     */
    public function isQuotaExceeded(string $username): bool
    {
        $quota = $this->getUserQuota($username);
        return $quota['used_mb'] > $quota['total_mb'];
    }

    /**
     * Formate un utilisateur pour l'API
     */
    private function formatUser(array $ldapUser): array
    {
        $quota = $this->getUserQuota($ldapUser['username']);
        $quota['percentage'] = round(($quota['used_mb'] / $quota['total_mb']) * 100, 1);

        return [
            'ldap_dn' => $ldapUser['dn'],
            'username' => $ldapUser['username'],
            'display_name' => $ldapUser['displayName'],
            'email' => $ldapUser['mail'],
            'first_name' => $ldapUser['givenName'],
            'last_name' => $ldapUser['sn'],
            'profile' => $ldapUser['profile'],
            'class' => $ldapUser['class'] ?? null,
            'groups' => $ldapUser['groups'] ?? [],
            'quota' => $quota,
            'last_login' => $ldapUser['lastLogin'] ?? null,
            'created_at' => $ldapUser['whenCreated'] ?? null,
            'updated_at' => $ldapUser['whenChanged'] ?? null
        ];
    }

    /**
     * Récupère la liste des établissements
     */
    public function getEtablissements(): array
    {
        try {
            return $this->establishmentRepository->getAll();

        } catch (\Exception $e) {
            Log::error('UserService getEtablissements error: ' . $e->getMessage());
            return [0 => 'Domaine entier'];
        }
    }

    /**
     * Récupère la liste des fonctions disponibles
     */
    public function getFonctions(string $categorie = 'all'): array
    {
        try {
            return $this->functionRepository->getAll($categorie);

        } catch (\Exception $e) {
            Log::error('UserService getFonctions error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Récupère la liste des classes disponibles
     */
    public function getClasses(string $etab = '0'): array
    {
        try {
            return $this->classRepository->getAll($etab);

        } catch (\Exception $e) {
            Log::error('UserService getClasses error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Récupère la liste des rôles disponibles
     */
    public function getAvailableRoles(): array
    {
        try {
            // Rôles standards SambaEdu
            return [
                ['value' => 'admin', 'label' => 'Administrateur', 'description' => 'Droits complets sur le système'],
                ['value' => 'professeur', 'label' => 'Professeur', 'description' => 'Enseignant'],
                ['value' => 'eleve', 'label' => 'Élève', 'description' => 'Apprenant'],
                ['value' => 'personnel', 'label' => 'Personnel', 'description' => 'Personnel administratif ou technique'],
                ['value' => 'administratif', 'label' => 'Administratif', 'description' => 'Personnel administratif'],
                ['value' => 'technicien', 'label' => 'Technicien', 'description' => 'Personnel technique'],
            ];
        } catch (\Exception $e) {
            Log::error('UserService getAvailableRoles error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Récupère la politique de mot de passe
     */
    public function getPasswordPolicy(): array
    {
        try {
            return $this->passwordService->getPolicy();

        } catch (\Exception $e) {
            Log::error('UserService getPasswordPolicy error: ' . $e->getMessage());
            return [
                'policy' => 0,
                'min_length' => 8,
                'complexity' => false,
                'description' => 'Mot de passe aléatoire'
            ];
        }
    }

    /**
     * Change le mot de passe d'un utilisateur directement dans l'AD.
     */
    public function changePasswordInAd(string $login, string $newPassword, bool $mustChangeAtNextLogin = true): bool
    {
        try {
            $ldapUser = $this->userRepository->findLdapModelByLogin($login);
            if (!$ldapUser) {
                Log::warning('UserService changePasswordInAd: utilisateur introuvable', ['login' => $login]);
                return false;
            }

            $this->setUserPassword($ldapUser, $newPassword);

            // Recharger l'utilisateur après changement de mot de passe
            $ldapUser = $this->userRepository->findLdapModelByLogin($login);

            if ($mustChangeAtNextLogin) {
                // Forcer le changement au prochain login
                $ldapUser->setAttribute('pwdlastset', 0);
            } else {
                // Marquer le mot de passe comme changé (AD remplace -1 par le timestamp actuel)
                $ldapUser->setAttribute('pwdlastset', -1);
            }
            $ldapUser->save();

            $this->userRepository->invalidateCache($login);

            return true;
        } catch (\Exception $e) {
            Log::error('UserService changePasswordInAd error: ' . $e->getMessage(), [
                'login' => $login,
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /**
     * Réinitialise le mot de passe d'un utilisateur dans l'AD.
     */
    public function resetPasswordInAd(string $login): array
    {
        $generatedPassword = $this->passwordService->generateRandomPassword();

        $success = $this->changePasswordInAd(
            login: $login,
            newPassword: $generatedPassword,
            mustChangeAtNextLogin: true,
        );

        if (!$success) {
            return [
                'success' => false,
                'message' => 'Échec de la réinitialisation du mot de passe dans l\'AD.',
            ];
        }

        return [
            'success' => true,
            'message' => 'Mot de passe réinitialisé avec succès dans l\'AD.',
            'password' => $generatedPassword,
        ];
    }

    /**
     * Crée un nouvel utilisateur avec LdapRecord
     * 
     * Remplace l'ancienne implémentation basée sur les fonctions legacy
     */
    public function createUser(array $data): array
    {
        try {
            // Validation des données
            $nom = $data['nom'] ?? '';
            $prenom = $data['prenom'] ?? '';
            $naissance = $data['naissance'] ?? '';
            $password = $data['password'] ?? '';
            $categorie = $data['categorie'] ?? 'Administratifs';
            $originallogin = $data['login'] ?? '';
            $fonction = $data['fonction'] ?? '';
            $classes = $data['classes'] ?? [];
            $new_etab = $data['new_etab'] ?? 0;

            // Validation basique
            if (empty($nom) || empty($prenom)) {
                return [
                    'success' => false,
                    'message' => 'Vous devez obligatoirement renseigner les champs : nom, prénom !'
                ];
            }

            // Validation de la catégorie et des champs obligatoires
            if ($categorie == "Administratifs" && empty($fonction)) {
                return [
                    'success' => false,
                    'message' => 'La fonction est obligatoire pour les administratifs'
                ];
            }

            if ($categorie == "Eleves" && empty($classes)) {
                return [
                    'success' => false,
                    'message' => 'La classe est obligatoire pour les élèves'
                ];
            }

            if ($categorie == "Profs" && empty($classes) && empty($fonction)) {
                return [
                    'success' => false,
                    'message' => 'La classe ou la fonction est obligatoire pour les professeurs'
                ];
            }

            // Générer le login si nécessaire
            $login = $this->generateLogin($nom, $prenom, $originallogin);

            // Vérifier que le login n'existe pas déjà
            $existingUser = $this->userRepository->findByLogin($login);
            if ($existingUser) {
                return [
                    'success' => false,
                    'message' => "Un utilisateur avec le login '$login' existe déjà"
                ];
            }

            // Déterminer le mot de passe final via le repository
            $finalPassword = $this->passwordService->determinePassword($password, $naissance);

            // Créer les OUs si elles n'existent pas
            $this->ouRepository->ensureUserOUsExist($categorie, $fonction, $new_etab);

            // Construire le DN de l'utilisateur
            $userDn = $this->buildUserDn($login, $categorie, $fonction, $new_etab);

            // Créer l'utilisateur avec LdapRecord
            $ldapUser = new LdapUser();
            $ldapUser->setDn($userDn);
            $this->setUserAttributes($ldapUser, $login, $nom, $prenom, $data, $finalPassword);

            // Définir le mot de passe AVANT la sauvegarde (requis par AD)
            // LdapRecord encode automatiquement via Password::encode() (UTF-16LE + guillemets)
            // Ne PAS encoder manuellement sinon double encodage
            $ldapUser->unicodepwd = $finalPassword;

            // Sauvegarder l'utilisateur avec le mot de passe
            $ldapUser->save();

            // Recharger l'utilisateur après création
            $ldapUser = $this->userRepository->findLdapModelByLogin($login);
            if (!$ldapUser) {
                throw new \Exception("Impossible de recharger l'utilisateur après création");
            }

            // Effectuer les opérations post-création
            $this->postCreationOperations($ldapUser, $data, $finalPassword);

            return [
                'success' => true,
                'message' => "L'utilisateur $prenom $nom a été créé avec succès.",
                'user' => [
                    'cn' => $login,
                    'password' => $finalPassword,
                    'nom' => $nom,
                    'prenom' => $prenom
                ]
            ];

        } catch (\Exception $e) {
            // Récupérer le détail de l'erreur LDAP si disponible
            $ldapError = null;
            $ldapDiagnostic = null;
            try {
                $connection = \LdapRecord\Container::getDefaultConnection();
                $ldap = $connection->getLdapConnection();
                $ldapError = $ldap->getLastError();
                $ldapDiagnostic = $ldap->getDiagnosticMessage();
            } catch (\Throwable $ldapEx) {
                // ignore
            }

            Log::error('UserService createUser error: ' . $e->getMessage(), [
                'ldap_error' => $ldapError,
                'ldap_diagnostic' => $ldapDiagnostic,
                'dn' => $userDn ?? null,
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => 'Erreur lors de la création de l\'utilisateur: ' . $e->getMessage()
            ];
        }
    }
    /**
     * Définit les attributs de base de l'utilisateur
     */
    private function setUserAttributes(LdapUser $ldapUser, string $login, string $nom, string $prenom, array $data, string $finalPassword): void
    {
        $ldapConfig = $this->config->ldap();
        $domain = $ldapConfig->domain;

        $ldapUser->setAttribute('cn', $login);
        $ldapUser->setAttribute('samaccountname', $login);
        $ldapUser->setAttribute('sn', $nom);
        $ldapUser->setAttribute('givenname', $prenom);
        $ldapUser->setAttribute('displayname', "$prenom $nom");

        // Gestion de l'email
        $email = $this->determineEmail($login, $data);
        $ldapUser->setAttribute('mail', $email);
        $ldapUser->setAttribute('userprincipalname', "$login@$domain");
        $ldapUser->setAttribute('useraccountcontrol', 512); // Compte actif

        // pwdlastset = 0 si changement de mot de passe requis
        $noPasswdChange = $this->config->get('no_passwd_change', '0') == '1';
        $force = !empty($data['force'] ?? false);
        if (!$noPasswdChange && !$force) {
            $ldapUser->setAttribute('pwdlastset', 0);
        }

        // Date de naissance si fournie
        if (!empty($data['naissance'] ?? '')) {
            $encodedBirthdate = $this->encodeBirthdate($data['naissance']);
            $ldapUser->setAttribute('physicaldeliveryofficename', $encodedBirthdate);
        }

        // Employeenumber
        $employeeNumber = $this->buildEmployeeNumber($data);
        if (!empty($employeeNumber)) {
            $ldapUser->setAttribute('employeenumber', $employeeNumber);
        }

        // Title
        $title = $this->buildTitle($data, $data['fonction'] ?? '');
        if (!empty($title)) {
            $ldapUser->setAttribute('title', $title);
        }

        // Ne pas définir objectclass manuellement :
        // LdapRecord\Models\ActiveDirectory\User définit automatiquement
        // ['top', 'person', 'organizationalperson', 'user']
    }

    /**
     * Définit le mot de passe de l'utilisateur
     */
    private function setUserPassword(LdapUser $ldapUser, string $password): void
    {
        try {
            // LdapRecord encode automatiquement via Password::encode() (UTF-16LE + guillemets)
            // Ne PAS encoder manuellement sinon double encodage
            $ldapUser->unicodepwd = $password;
            $ldapUser->save();

            Log::info("Mot de passe défini avec succès pour " . $ldapUser->getLogin());
        } catch (\Exception $e) {
            Log::error("Erreur lors de la définition du mot de passe", [
                'login' => $ldapUser->getLogin(),
                'error' => $e->getMessage()
            ]);

            throw new \Exception("Impossible de définir le mot de passe: " . $e->getMessage());
        }
    }

    /**
     * Effectue les opérations post-création
     */
    private function postCreationOperations(LdapUser $ldapUser, array $data, string $password): void
    {
        $login = $ldapUser->getLogin();
        $categorie = $data['categorie'] ?? 'Administratifs';
        $fonction = $data['fonction'] ?? '';
        $classes = $data['classes'] ?? [];
        $new_etab = $data['new_etab'] ?? 0;

        // Audit log (NFR8) — qui a créé quoi, quand
        Log::info("Création utilisateur", [
            'action' => 'user.create',
            'login' => $login,
            'categorie' => $categorie,
            'fonction' => $fonction,
            'classes' => $classes,
            'operator' => auth()->user()?->login ?? 'system',
        ]);

        // Double-write : persister le User Eloquent dans PostgreSQL
        $this->persistUserToSql($ldapUser, $data);

        // Créer le dossier home
        $this->createHomeDirectory($login);

        // Configuration cloud si activée
        $noCloud = $this->config->get('no_cloud', '0') == '1';
        if (!$noCloud) {
            $this->configureUserCloud($login, $password);
        }

        // Ajouter aux groupes (AD)
        $this->addUserToGroups($ldapUser, $categorie, $fonction, $classes, $new_etab, $data['etabs'] ?? []);

        // Double-write : lier l'utilisateur à ses groupes en SQL
        $this->persistUserGroupsToSql($login, $categorie, $fonction, $classes);
    }

    /**
     * Persiste l'utilisateur dans PostgreSQL après création LDAP (double-write)
     *
     * Utilise updateOrCreate sur le login pour gérer le cas où le guard
     * a déjà auto-provisionné le User SQL.
     * En cas d'échec PostgreSQL : log mais pas d'exception (AD = source de vérité MVP).
     */
    private function persistUserToSql(LdapUser $ldapUser, array $data): void
    {
        try {
            $login = $ldapUser->getLogin();
            $nom = $data['nom'] ?? '';
            $prenom = $data['prenom'] ?? '';
            $categorie = $data['categorie'] ?? 'Administratifs';

            // Mapper la catégorie AD vers le rôle SQL
            $roleMap = [
                'eleves' => 'eleve',
                'profs' => 'prof',
                'administratifs' => 'admin',
            ];
            $role = $roleMap[strtolower($categorie)] ?? 'autre';

            // Récupérer l'ad_guid depuis l'objet LDAP rechargé (binaire → hex string)
            $adGuidRaw = $ldapUser->getFirstAttribute('objectguid');
            $adGuid = $adGuidRaw ? bin2hex($adGuidRaw) : null;

            $domain = $this->config->ldap()?->domain ?? '';
            $email = $ldapUser->getFirstAttribute('mail') ?? ($domain ? "$login@$domain" : $login);

            SqlUserModel::updateOrCreate(
                ['login' => $login],
                [
                    'fullname' => trim("$prenom $nom"),
                    'firstname' => $prenom,
                    'lastname' => $nom,
                    'email' => $email,
                    'dn' => $ldapUser->getDn(),
                    'ad_guid' => $adGuid,
                    'role' => $role,
                    'is_active' => true,
                    'ad_synced_at' => now(),
                ]
            );

            Log::info("User Eloquent persisté pour $login (double-write)");
        } catch (\Exception $e) {
            Log::error("Échec persistance SQL pour l'utilisateur (AD = source de vérité, on continue)", [
                'login' => $ldapUser->getLogin(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Lie l'utilisateur SQL à ses groupes dans la table pivot user_group_user
     */
    private function persistUserGroupsToSql(string $login, string $categorie, string $fonction, array $classes): void
    {
        try {
            $sqlUser = SqlUserModel::where('login', $login)->first();
            if (!$sqlUser) {
                return;
            }

            // Noms des groupes : catégorie + fonction + classes
            $groupNames = array_merge(
                [$categorie],
                !empty($fonction) ? [$fonction] : [],
                array_map(fn($c) => 'Classe_' . $c, $classes),
            );

            $groupIds = \App\Models\UserGroup::where(function ($q) use ($groupNames) {
                foreach ($groupNames as $name) {
                    $q->orWhereRaw('LOWER(name) = ?', [strtolower($name)]);
                }
            })->pluck('id');

            if ($groupIds->isNotEmpty()) {
                $sqlUser->groups()->syncWithoutDetaching($groupIds);
                Log::info("Groupes SQL liés pour $login", ['groups' => $groupNames]);
            }
        } catch (\Exception $e) {
            Log::error("Échec liaison groupes SQL (AD = source de vérité, on continue)", [
                'login' => $login,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Ajoute l'utilisateur aux groupes appropriés
     */
    private function addUserToGroups(LdapUser $ldapUser, string $categorie, string $fonction, array $classes, int $new_etab, array $etabs): void
    {
        // Groupe principal (Eleves, Profs, Administratifs)
        $mainGroup = SambaEduGroup::findMainGroup($categorie);
        if ($mainGroup) {
            $mainGroup->members()->attach($ldapUser);
        }

        // Groupe établissement
        $uai = $this->establishmentRepository->toUai($new_etab);
        if (!empty($uai) && $uai != '0') {
            $etabGroup = SambaEduGroup::query()->where('cn', '=', $uai)->first();
            if ($etabGroup) {
                $etabGroup->members()->attach($ldapUser);
            }
        }

        // Autres établissements
        foreach ($etabs as $etabId) {
            $otherUai = $this->establishmentRepository->toUai($etabId);
            if (!empty($otherUai) && $otherUai != $uai) {
                $otherEtabGroup = SambaEduGroup::query()->where('cn', '=', $otherUai)->first();
                if ($otherEtabGroup) {
                    $otherEtabGroup->members()->attach($ldapUser);
                }
            }
        }

        // Groupe fonction
        if (!empty($fonction)) {
            $fonctionGroup = SambaEduGroup::query()->where('cn', '=', $fonction)->first();
            if ($fonctionGroup) {
                $fonctionGroup->members()->attach($ldapUser);
            }
        }

        // Groupes classes
        foreach ($classes as $classe) {
            $classeGroup = SambaEduGroup::query()->where('cn', '=', $classe)->first();
            if ($classeGroup) {
                $classeGroup->members()->attach($ldapUser);
            }
        }
    }

    /**
     * Génère un login pour l'utilisateur
     * Utilise la configuration depuis les repositories
     */
    private function generateLogin(string $nom, string $prenom, string $originallogin): string
    {
        if (!empty($originallogin)) {
            return $originallogin;
        }

        // Simplification des noms
        $nom = $this->simplifyName($nom);
        $prenom = $this->simplifyFirstName($prenom);

        // Politique de génération de login depuis la config
        $cnPolicy = config('ldap.cn_policy', 0);

        $login = '';
        $nb = 0;

        do {
            switch ($cnPolicy) {
                case 0:
                case 1:
                    // prenom.nom (tronqué à 19 caractères)
                    if (strlen($nom) + strlen($prenom) > 18) {
                        $prenom = substr($prenom, 0, 18 - strlen($nom));
                    }
                    $login = !empty($prenom) ? "$prenom.$nom" : $nom;
                    if ($nb > 0) {
                        $login = "$prenom.$nom$nb";
                    }
                    break;
                case 2:
                    // p.nom (tronqué à 15 caractères)
                    $p = substr($prenom, 0, 1);
                    $login = !empty($p) ? "$p.$nom" : $nom;
                    if ($nb > 0) {
                        $login = "$p.$nom$nb";
                    }
                    break;
                default:
                    // Par défaut : prenom.nom
                    if (strlen($nom) + strlen($prenom) > 18) {
                        $prenom = substr($prenom, 0, 18 - strlen($nom));
                    }
                    $login = !empty($prenom) ? "$prenom.$nom" : $nom;
                    if ($nb > 0) {
                        $login = "$prenom.$nom$nb";
                    }
            }

            // Vérifier si le login existe déjà
            $existingUser = $this->userRepository->findByLogin($login);
            if ($existingUser) {
                $nb++;
            } else {
                break;
            }
        } while ($nb < 100);

        return strtolower($login);
    }

    /**
     * Simplifie un nom (suppression accents, caractères spéciaux)
     */
    private function simplifyName(string $name, bool $removeHyphens = false): string
    {
        $name = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        $name = preg_replace('/[^a-zA-Z0-9]/', '', $name);
        if ($removeHyphens) {
            $name = str_replace('-', '', $name);
        }
        return strtolower($name);
    }

    /**
     * Simplifie un prénom
     */
    private function simplifyFirstName(string $firstname, bool $firstLetterOnly = false, bool $removeHyphens = false): string
    {
        if ($firstLetterOnly) {
            return substr($this->simplifyName($firstname, $removeHyphens), 0, 1);
        }
        return $this->simplifyName($firstname, $removeHyphens);
    }


    /**
     * Génère un mot de passe aléatoire
     * Délègue au PasswordPolicyService
     */
    private function generateRandomPassword(): string
    {
        return $this->passwordService->generateRandomPassword();
    }

    /**
     * Construit le DN de l'utilisateur selon la catégorie, fonction et établissement
     * Utilise SambaEduConfig pour accéder aux DN de manière typée
     */
    private function buildUserDn(string $login, string $categorie, string $fonction, int $etab): string
    {
        $ldapConfig = $this->config->ldap();
        $baseDn = $ldapConfig->baseDn;
        $peopleRdn = $ldapConfig->peopleRdn;

        // Obtenir l'UAI depuis l'établissement
        $uai = $this->establishmentRepository->toUai($etab);

        // Construire la racine des OUs
        $racine = '';

        if (!empty($uai) && $uai != '0' && preg_match("/[0-9]{7}[a-z]/i", $uai)) {
            // Cas avec UAI : préfixer people_rdn avec OU=UAI
            $peopleRdn = "OU=$uai,$peopleRdn";

            if (!empty($fonction)) {
                $racine = "OU=$fonction,OU=$categorie";
            } else {
                $racine = "OU=$categorie";
            }
        } else {
            // Cas sans UAI
            if (!empty($fonction)) {
                $racine = "OU=$fonction,OU=$categorie";
            } else {
                $racine = "OU=$categorie";
            }
        }

        // Construire le DN complet
        if (!empty($racine)) {
            $dn = "CN=$login,$racine,$peopleRdn,$baseDn";
        } else {
            $dn = "CN=$login,$peopleRdn,$baseDn";
        }

        return $dn;
    }

    /**
     * Crée ou vérifie le dossier home de l'utilisateur
     *
     * Reproduit le comportement legacy de mkhome.sh :
     * - Si /home/$login n'existe pas : mkdir + copie skel + chown + chmod 770
     * - Si /home/$login existe : vérifie et corrige le propriétaire si nécessaire
     */
    public function createHomeDirectory(string $login): void
    {
        // Validation : empêcher l'injection de commande via le login
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $login)) {
            Log::error("createHomeDirectory: login invalide (caractères non autorisés)", ['login' => $login]);
            return;
        }

        $homePath = "/home/" . $login;

        try {
            if (!is_dir($homePath)) {
                // Créer le répertoire
                exec("sudo mkdir -p " . escapeshellarg($homePath) . " 2>&1", $output, $returnCode);
                if ($returnCode !== 0) {
                    Log::error("createHomeDirectory: échec mkdir", ['login' => $login, 'output' => implode("\n", $output)]);
                    return;
                }

                // Copier le skel (user.windows comme dans le legacy)
                // Utiliser /. au lieu de /* pour inclure les dotfiles (.bashrc, .profile, etc.)
                $skelPath = '/etc/skel/user.windows';
                if (is_dir($skelPath)) {
                    exec("sudo cp -a " . escapeshellarg($skelPath) . "/. " . escapeshellarg($homePath) . "/ 2>&1", $output2, $rc2);
                    if ($rc2 !== 0) {
                        Log::warning("createHomeDirectory: échec copie skel", ['login' => $login, 'output' => implode("\n", $output2)]);
                    }
                } else {
                    Log::warning("createHomeDirectory: skel absent, home créé vide", ['skel' => $skelPath, 'login' => $login]);
                }

                // Appliquer propriétaire et permissions comme mkhome.sh
                // Sur SE4FS, les UID AD ne sont pas résolubles (pas de winbind) → www-admin est le propriétaire effectif
                exec("sudo chown -R www-admin:www-admin " . escapeshellarg($homePath) . " 2>&1", $output3, $rc3);
                if ($rc3 !== 0) {
                    Log::warning("createHomeDirectory: échec chown", ['login' => $login, 'output' => implode("\n", $output3)]);
                }
                exec("sudo chmod -R 770 " . escapeshellarg($homePath) . " 2>&1", $output4, $rc4);
                if ($rc4 !== 0) {
                    Log::warning("createHomeDirectory: échec chmod", ['login' => $login, 'output' => implode("\n", $output4)]);
                }

                Log::info("Home directory créé pour $login", ['path' => $homePath]);
            } else {
                // Le home existe : vérifier et corriger le propriétaire si nécessaire
                $stat = stat($homePath);
                if ($stat !== false) {
                    $expectedUid = posix_getpwnam('www-admin');
                    if ($expectedUid !== false && $stat['uid'] !== $expectedUid['uid']) {
                        exec("sudo chown -R www-admin:www-admin " . escapeshellarg($homePath) . " 2>&1", $output5, $rc5);
                        Log::info("Home directory propriétaire corrigé pour $login", ['path' => $homePath]);
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning("Erreur lors de la création/vérification du dossier home pour $login", [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Détermine l'email selon la configuration
     * Utilise SambaEduConfig pour accéder à la configuration de manière typée
     */
    private function determineEmail(string $login, array $data): string
    {
        $entEmail = $this->config->get('ent_email', '0');
        $entEmailDomain = $this->config->get('ent_email_domain', '');
        $domain = $this->config->ldap()->domain;

        if ($entEmail == "1" && !empty($data['email'] ?? '')) {
            if (!empty($entEmailDomain) && $entEmailDomain != "0" && !empty($data['originalLogin'] ?? '')) {
                return $data['originalLogin'] . "@" . $entEmailDomain;
            } else {
                return $data['email'] ?? "$login@$domain";
            }
        }

        return "$login@$domain";
    }

    /**
     * Construit employeenumber à partir des IDs
     */
    private function buildEmployeeNumber(array $data): string
    {
        $s = $data['Id Siecle'] ?? '';
        $g = $data['Id GPEI'] ?? '';
        $a = $data['Id ASM'] ?? '';
        $p = $data['Id Pronote'] ?? '';

        return "$s,$g,$a,$p";
    }

    /**
     * Construit title à partir de id et externalId
     */
    private function buildTitle(array $data, string $fonction): string
    {
        $i = $data['id'] ?? '';
        $x = $data['externalId'] ?? '';

        // Si id ou externalId sont fournis, les utiliser
        if (!empty($i) || !empty($x)) {
            return "$i,$x";
        }

        // Sinon, utiliser la fonction si fournie
        return $fonction;
    }

    /**
     * Encode la date de naissance avec RSA
     */
    private function encodeBirthdate(string $birthdate): string
    {
        try {
            $publicKeyPath = "/etc/sambaedu/sambaedu-pubkey.pem";
            if (!file_exists($publicKeyPath)) {
                // Si la clé n'existe pas, utiliser password_hash comme fallback
                Log::warning("Clé publique RSA non trouvée, utilisation de password_hash pour la date de naissance");
                return password_hash($birthdate, PASSWORD_DEFAULT);
            }

            $publicKey = file_get_contents($publicKeyPath);
            $encrypted = "";
            openssl_public_encrypt($birthdate, $encrypted, $publicKey);
            return base64_encode($encrypted);
        } catch (\Exception $e) {
            Log::warning("Erreur lors du chiffrement de la date de naissance", [
                'error' => $e->getMessage()
            ]);
            // Fallback vers password_hash
            return password_hash($birthdate, PASSWORD_DEFAULT);
        }
    }

    /**
     * Configure le cloud pour l'utilisateur (Nextcloud via rclone)
     *
     * Reproduit le comportement legacy de cloud.inc.php:configure_user_cloud() :
     * 1. Obtenir un app password Nextcloud via l'API OCS
     * 2. Récupérer l'ID cloud de l'utilisateur
     * 3. Créer la config rclone via `rclone config create`
     * 4. Si rclone OK : chown + ajout au groupe AD "Cloud"
     *
     * L'échec cloud ne bloque pas la création utilisateur.
     */
    private function configureUserCloud(string $login, string $password): void
    {
        try {
            // Validation du login
            if (!preg_match('/^[a-zA-Z0-9._-]+$/', $login)) {
                Log::warning("configureUserCloud: login invalide", ['login' => $login]);
                return;
            }

            // Lire la config cloud
            $cloudType = $this->config->get('cloud_type', '');
            $cloudName = $this->config->get('cloud_name', '');
            $cloudUri = $this->config->get('cloud_uri', '');

            if (empty($cloudType) || empty($cloudName) || empty($cloudUri)) {
                Log::info("Configuration cloud incomplète, skip pour $login");
                return;
            }

            // Seul webdav (Nextcloud) est supporté pour l'instant
            if ($cloudType !== 'webdav') {
                Log::info("Type cloud '$cloudType' non supporté (seul webdav est implémenté), skip pour $login");
                return;
            }

            // 1. Obtenir un app password Nextcloud
            $appPassword = $this->getNextcloudAppPassword($cloudUri, $login, $password);
            if ($appPassword === null) {
                Log::warning("Impossible d'obtenir un app password Nextcloud pour $login");
                return;
            }

            // 2. Récupérer l'ID cloud
            $cloudId = $this->getNextcloudUserId($cloudUri, $login, $password);

            // 3. Créer la config rclone
            $rcloneDir = "/home/" . $login . "/.config/rclone";
            $rcloneConf = $rcloneDir . "/rclone.conf";

            exec("sudo mkdir -p " . escapeshellarg($rcloneDir) . " 2>&1", $mkdirOutput, $mkdirRc);
            if ($mkdirRc !== 0) {
                Log::warning("configureUserCloud: échec mkdir rclone dir", ['login' => $login, 'output' => implode("\n", $mkdirOutput)]);
                return;
            }

            // --config= pour écrire dans le fichier de l'utilisateur (pas celui de root)
            $davUser = $cloudId ?: $login;
            $rcloneCmd = sprintf(
                "sudo rclone --config=%s config create %s %s vendor=sambaedu url=%s user=%s pass=%s 2>&1",
                escapeshellarg($rcloneConf),
                escapeshellarg($cloudName),
                escapeshellarg($cloudType),
                escapeshellarg(rtrim($cloudUri, '/') . '/remote.php/dav/files/' . rawurlencode($davUser) . '/'),
                escapeshellarg($login),
                escapeshellarg($appPassword)
            );

            exec($rcloneCmd, $rcloneOutput, $rcloneRc);

            if ($rcloneRc === 0) {
                // chown + chmod 600 pour que seul l'utilisateur puisse lire ses credentials
                exec("sudo chown " . escapeshellarg($login) . " " . escapeshellarg($rcloneConf) . " 2>&1");
                exec("sudo chmod 600 " . escapeshellarg($rcloneConf) . " 2>&1");

                // Ajouter au groupe AD "Cloud" si pas déjà membre
                $this->addToCloudGroupIfNeeded($login);

                Log::info("Configuration cloud rclone créée pour $login");
            } else {
                Log::warning("Échec rclone config create pour $login", [
                    'exit_code' => $rcloneRc,
                    'output' => implode("\n", $rcloneOutput),
                ]);
            }
        } catch (\Exception $e) {
            Log::warning("Erreur lors de la configuration cloud pour $login", [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Obtient un app password Nextcloud via l'API OCS
     *
     * Note : CURLOPT_SSL_VERIFYPEER = false car le Nextcloud est en réseau local
     * avec un certificat auto-signé (infrastructure SE4FS). À activer si le
     * certificat est remplacé par un certificat public.
     */
    private function getNextcloudAppPassword(string $cloudUri, string $login, string $password): ?string
    {
        try {
            $url = rtrim($cloudUri, '/') . '/ocs/v2.php/core/getapppassword';

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['OCS-APIREQUEST: true'],
                CURLOPT_USERPWD => $login . ':' . $password,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => false, // réseau local, certificat auto-signé SE4FS
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200 || $response === false) {
                return null;
            }

            $xml = @simplexml_load_string($response);
            if ($xml === false) {
                return null;
            }

            return (string) ($xml->data->apppassword ?? '') ?: null;
        } catch (\Exception $e) {
            Log::warning("Erreur getNextcloudAppPassword", ['login' => $login, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Récupère l'ID cloud Nextcloud de l'utilisateur
     */
    private function getNextcloudUserId(string $cloudUri, string $login, string $password): ?string
    {
        try {
            $url = rtrim($cloudUri, '/') . '/ocs/v2.php/cloud/user';

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['OCS-APIREQUEST: true'],
                CURLOPT_USERPWD => $login . ':' . $password,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => false, // réseau local, certificat auto-signé SE4FS
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200 || $response === false) {
                return null;
            }

            $xml = @simplexml_load_string($response);
            if ($xml === false) {
                return null;
            }

            return (string) ($xml->data->id ?? '') ?: null;
        } catch (\Exception $e) {
            Log::warning("Erreur getNextcloudUserId", ['login' => $login, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Ajoute l'utilisateur au groupe AD "Cloud" s'il n'en est pas déjà membre
     */
    // ============================================
    // DÉSACTIVATION / ACTIVATION / SUPPRESSION
    // ============================================

    /**
     * Désactive un compte utilisateur.
     * Double-write: AD (useraccountcontrol → 514) puis PostgreSQL (is_active → false).
     * Archive le home directory dans /home/trash/.
     */
    public function disableUser(string $login): array
    {
        if (!Gate::allows('delete-user')) {
            return ['success' => false, 'message' => 'Vous n\'avez pas les droits pour cette action.'];
        }

        if (MainGroups::isSystemAccount($login)) {
            return ['success' => false, 'message' => 'Ce compte système ne peut pas être désactivé.'];
        }

        $lock = Cache::lock("user-operation-{$login}", 30);
        if (!$lock->get()) {
            return ['success' => false, 'message' => 'Une opération est déjà en cours sur cet utilisateur.'];
        }

        try {
            $ldapUser = $this->userRepository->findLdapModelByLogin($login);
            if (!$ldapUser) {
                return ['success' => false, 'message' => 'Utilisateur introuvable dans l\'annuaire.'];
            }

            // AD: désactiver le compte
            $ldapUser->useraccountcontrol = User::UAC_DISABLED;
            $ldapUser->save();

            // Invalider le cache
            $this->userRepository->invalidateCache($login);

            // Double-write SQL (non bloquant)
            try {
                SqlUserModel::where('login', $login)->update(['is_active' => false]);
            } catch (\Throwable $e) {
                Log::error('Échec double-write SQL pour disableUser (AD = source de vérité)', [
                    'login' => $login,
                    'error' => $e->getMessage(),
                ]);
            }

            // Archiver le home directory
            $archived = $this->archiveHomeDirectory($login);
            if (!$archived) {
                Log::warning('disableUser: échec archivage home directory (compte désactivé quand même)', [
                    'login' => $login,
                ]);
            }

            Log::info('User disabled', [
                'login' => $login,
                'home_archived' => $archived,
                'operator' => auth()->user()?->login ?? 'system',
            ]);

            $message = $archived
                ? 'Compte désactivé.'
                : 'Compte désactivé, mais l\'archivage du home directory a échoué.';

            return ['success' => true, 'message' => $message];

        } catch (\Throwable $e) {
            Log::error('Erreur lors de la désactivation du compte', [
                'login' => $login,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => 'Erreur lors de la désactivation du compte.'];
        } finally {
            $lock->release();
        }
    }

    /**
     * Réactive un compte utilisateur.
     * Double-write: AD (useraccountcontrol → 512) puis PostgreSQL (is_active → true).
     * Restaure le home directory depuis /home/trash/ si présent.
     */
    public function enableUser(string $login): array
    {
        if (!Gate::allows('delete-user')) {
            return ['success' => false, 'message' => 'Vous n\'avez pas les droits pour cette action.'];
        }

        if (MainGroups::isSystemAccount($login)) {
            return ['success' => false, 'message' => 'Ce compte système ne peut pas être modifié.'];
        }

        $lock = Cache::lock("user-operation-{$login}", 30);
        if (!$lock->get()) {
            return ['success' => false, 'message' => 'Une opération est déjà en cours sur cet utilisateur.'];
        }

        try {
            $ldapUser = $this->userRepository->findLdapModelByLogin($login);
            if (!$ldapUser) {
                return ['success' => false, 'message' => 'Utilisateur introuvable dans l\'annuaire.'];
            }

            // AD: réactiver le compte
            $ldapUser->useraccountcontrol = User::UAC_ACTIVE;
            $ldapUser->save();

            // Invalider le cache
            $this->userRepository->invalidateCache($login);

            // Double-write SQL (non bloquant)
            try {
                SqlUserModel::where('login', $login)->update(['is_active' => true]);
            } catch (\Throwable $e) {
                Log::error('Échec double-write SQL pour enableUser (AD = source de vérité)', [
                    'login' => $login,
                    'error' => $e->getMessage(),
                ]);
            }

            // Restaurer le home directory si archivé (après réactivation AD)
            if ($this->hasArchivedHome($login)) {
                $this->restoreHomeDirectory($login);
            }

            Log::info('User enabled', [
                'login' => $login,
                'operator' => auth()->user()?->login ?? 'system',
            ]);

            return ['success' => true, 'message' => 'Compte réactivé.'];

        } catch (\Throwable $e) {
            Log::error('Erreur lors de la réactivation du compte', [
                'login' => $login,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => 'Erreur lors de la réactivation du compte.'];
        } finally {
            $lock->release();
        }
    }

    /**
     * Supprime définitivement un compte utilisateur.
     * Requiert que le compte soit déjà désactivé (suppression en deux temps).
     * Ordre: AD → home archivé → PostgreSQL → cache.
     */
    public function deleteUserPermanently(string $login): array
    {
        if (!Gate::allows('delete-user')) {
            return ['success' => false, 'message' => 'Vous n\'avez pas les droits pour cette action.'];
        }

        if (MainGroups::isSystemAccount($login)) {
            return ['success' => false, 'message' => 'Ce compte système ne peut pas être supprimé.'];
        }

        $lock = Cache::lock("user-operation-{$login}", 30);
        if (!$lock->get()) {
            return ['success' => false, 'message' => 'Une opération est déjà en cours sur cet utilisateur.'];
        }

        try {
            $ldapUser = $this->userRepository->findLdapModelByLogin($login);

            // P-1: l'utilisateur DOIT exister dans l'AD pour vérifier le two-step
            if (!$ldapUser) {
                return ['success' => false, 'message' => 'Utilisateur introuvable dans l\'annuaire. Impossible de vérifier le statut du compte.'];
            }

            // Vérifier la suppression en deux temps : le compte doit être désactivé
            $uac = (int) ($ldapUser->getFirstAttribute('useraccountcontrol') ?? User::UAC_ACTIVE);
            if ($uac !== User::UAC_DISABLED) {
                return ['success' => false, 'message' => 'Vous devez d\'abord désactiver le compte avant de le supprimer définitivement.'];
            }

            // P-5: Supprimer de l'AD en premier (source de vérité)
            $deleted = $ldapUser->delete();
            if ($deleted === false) {
                Log::error('deleteUserPermanently: échec suppression AD', ['login' => $login]);
                return ['success' => false, 'message' => 'Échec de la suppression du compte dans l\'annuaire.'];
            }

            // Supprimer le home archivé (après AD, car irréversible)
            $this->deleteHomeDirectoryPermanently($login);

            // Double-write SQL (non bloquant — AD = source de vérité)
            try {
                SqlUserModel::where('login', $login)->delete();
            } catch (\Throwable $e) {
                Log::error('Échec double-write SQL pour deleteUserPermanently (AD = source de vérité)', [
                    'login' => $login,
                    'error' => $e->getMessage(),
                ]);
            }

            // Supprimer du cache
            $this->userRepository->invalidateCache($login);

            Log::info('User permanently deleted', [
                'login' => $login,
                'timestamp' => now()->toIso8601String(),
                'operator' => auth()->user()?->login ?? 'system',
            ]);

            return ['success' => true, 'message' => 'Compte supprimé définitivement.'];

        } catch (\Throwable $e) {
            Log::error('Erreur lors de la suppression permanente du compte', [
                'login' => $login,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => 'Erreur lors de la suppression du compte.'];
        } finally {
            $lock->release();
        }
    }

    // ============================================
    // GESTION FILESYSTEM HOME DIRECTORY (private)
    // ============================================

    /**
     * Archive le home directory : /home/{login} → /home/trash/{login}
     */
    private function archiveHomeDirectory(string $login): bool
    {
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $login)) {
            Log::error("archiveHomeDirectory: login invalide", ['login' => $login]);
            return false;
        }

        $homePath = "/home/" . $login;
        $trashPath = "/home/trash/" . $login;

        if (!is_dir($homePath)) {
            Log::warning("archiveHomeDirectory: home inexistant", ['path' => $homePath]);
            return false;
        }

        // Créer /home/trash/ si inexistant
        if (!is_dir('/home/trash')) {
            exec("sudo mkdir -p /home/trash 2>&1", $output, $rc);
            if ($rc !== 0) {
                Log::error("archiveHomeDirectory: échec création /home/trash", ['output' => implode("\n", $output)]);
                return false;
            }
        }

        exec("sudo mv " . escapeshellarg($homePath) . " " . escapeshellarg($trashPath) . " 2>&1", $output, $rc);
        if ($rc !== 0) {
            Log::error("archiveHomeDirectory: échec mv", ['login' => $login, 'output' => implode("\n", $output)]);
            return false;
        }

        Log::info("Home directory archivé", ['login' => $login, 'from' => $homePath, 'to' => $trashPath]);
        return true;
    }

    /**
     * Restaure le home directory : /home/trash/{login} → /home/{login}
     */
    private function restoreHomeDirectory(string $login): bool
    {
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $login)) {
            Log::error("restoreHomeDirectory: login invalide", ['login' => $login]);
            return false;
        }

        $trashPath = "/home/trash/" . $login;
        $homePath = "/home/" . $login;

        if (!is_dir($trashPath)) {
            Log::warning("restoreHomeDirectory: archive inexistante", ['path' => $trashPath]);
            return false;
        }

        exec("sudo mv " . escapeshellarg($trashPath) . " " . escapeshellarg($homePath) . " 2>&1", $output, $rc);
        if ($rc !== 0) {
            Log::error("restoreHomeDirectory: échec mv", ['login' => $login, 'output' => implode("\n", $output)]);
            return false;
        }

        Log::info("Home directory restauré", ['login' => $login, 'from' => $trashPath, 'to' => $homePath]);
        return true;
    }

    /**
     * Supprime définitivement le home archivé : rm -rf /home/trash/{login}
     * UNIQUEMENT depuis /home/trash/ — ne jamais supprimer /home/{login} directement.
     */
    private function deleteHomeDirectoryPermanently(string $login): bool
    {
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $login)) {
            Log::error("deleteHomeDirectoryPermanently: login invalide", ['login' => $login]);
            return false;
        }

        $trashPath = "/home/trash/" . $login;

        if (!is_dir($trashPath)) {
            Log::info("deleteHomeDirectoryPermanently: rien à supprimer", ['path' => $trashPath]);
            return true;
        }

        exec("sudo rm -rf " . escapeshellarg($trashPath) . " 2>&1", $output, $rc);
        if ($rc !== 0) {
            Log::error("deleteHomeDirectoryPermanently: échec rm", ['login' => $login, 'output' => implode("\n", $output)]);
            return false;
        }

        Log::info("Home directory supprimé définitivement", ['login' => $login, 'path' => $trashPath]);
        return true;
    }

    /**
     * Vérifie si un home archivé existe dans /home/trash/
     */
    private function hasArchivedHome(string $login): bool
    {
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $login)) {
            return false;
        }
        return is_dir("/home/trash/" . $login);
    }

    private function addToCloudGroupIfNeeded(string $login): void
    {
        try {
            $ldapUser = $this->userRepository->findLdapModelByLogin($login);
            if (!$ldapUser) {
                return;
            }

            // Vérifier s'il est déjà membre du groupe Cloud
            // getAttribute (pas getFirstAttribute) pour récupérer TOUS les groupes
            $memberOf = $ldapUser->getAttribute('memberof') ?? [];

            $alreadyInCloud = false;
            foreach ($memberOf as $groupDn) {
                if (stripos($groupDn, 'CN=Cloud,') !== false) {
                    $alreadyInCloud = true;
                    break;
                }
            }

            if (!$alreadyInCloud) {
                $cloudGroup = SambaEduGroup::query()->where('cn', '=', 'Cloud')->first();
                if ($cloudGroup) {
                    $cloudGroup->members()->attach($ldapUser);
                    Log::info("Utilisateur $login ajouté au groupe Cloud");
                } else {
                    Log::warning("Groupe AD 'Cloud' introuvable, impossible d'ajouter $login");
                }
            }
        } catch (\Exception $e) {
            Log::warning("Erreur ajout groupe Cloud pour $login", ['error' => $e->getMessage()]);
        }
    }

    // ============================================
    // DÉPLACEMENT DN — Story 2.5
    // ============================================

    /**
     * Déplace un utilisateur dans l'arbre AD quand sa catégorie ou sa fonction change.
     *
     * 1. Détecte si un déplacement est nécessaire (DN actuel vs DN cible)
     * 2. Crée les OUs manquantes
     * 3. ldap_rename vers le nouveau parent
     * 4. Recharge l'objet LdapUser (le DN a changé)
     *
     * @return array{success: bool, message: string, ldapUser?: LdapUser}
     */
    public function moveUserDn(LdapUser $ldapUser, string $newCategorie, string $newFonction, int|string $etab): array
    {
        $login = $ldapUser->getLogin();
        $oldDn = $ldapUser->getDn();

        // Gérer les cas spéciaux Documentaliste/AESH : DN sous Profs
        $dnCategorie = $newCategorie;
        if (in_array($newFonction, FunctionGroups::PEDAGOGIQUES) && strtolower($newCategorie) !== 'profs') {
            $dnCategorie = 'Profs';
        }

        // Construire le DN cible
        $targetDn = $this->buildUserDn($login, $dnCategorie, $newFonction, $etab);

        // Pas de changement nécessaire ?
        if (strcasecmp($oldDn, $targetDn) === 0) {
            return ['success' => true, 'message' => 'Aucun déplacement nécessaire.', 'ldapUser' => $ldapUser];
        }

        // Créer les OUs cibles si manquantes
        $this->ouRepository->ensureUserOUsExist($dnCategorie, $newFonction, $etab);

        // Préparer les paramètres pour ldap_rename
        $connection = $ldapUser->getConnection()->getLdapConnection();
        $newRdn = "CN={$login}";

        // Extraire le parent DN depuis le DN cible (tout sauf le premier composant CN=...)
        $targetParts = ldap_explode_dn($targetDn, 0);
        if ($targetParts === false) {
            Log::error('DN cible malformé', ['target_dn' => $targetDn, 'login' => $login]);
            return ['success' => false, 'message' => 'DN cible malformé.'];
        }
        unset($targetParts['count']);
        array_shift($targetParts); // retirer CN=login
        $newParentDn = implode(',', $targetParts);

        $result = @ldap_rename($connection, $oldDn, $newRdn, $newParentDn, true);

        if (!$result) {
            $error = ldap_error($connection);
            Log::error('Échec ldap_rename pour déplacement utilisateur', [
                'login' => $login,
                'old_dn' => $oldDn,
                'target_dn' => $targetDn,
                'error' => $error,
            ]);
            return ['success' => false, 'message' => "Erreur LDAP lors du déplacement : {$error}"];
        }

        Log::info('Utilisateur déplacé dans l\'AD', [
            'action' => 'user.move_dn',
            'login' => $login,
            'old_dn' => $oldDn,
            'new_dn' => $targetDn,
            'new_categorie' => $newCategorie,
            'new_fonction' => $newFonction,
            'operator' => auth()->user()?->login ?? 'system',
        ]);

        // Recharger l'objet LDAP (le DN a changé)
        $this->userRepository->invalidateCache($login);
        $reloadedUser = $this->userRepository->findLdapModelByLogin($login);

        return [
            'success' => true,
            'message' => 'Utilisateur déplacé avec succès.',
            'ldapUser' => $reloadedUser ?? $ldapUser,
        ];
    }

    /**
     * Synchronise les groupes de rôle/fonction d'un utilisateur après un changement.
     *
     * Retire les anciens groupes de catégorie et de fonction,
     * puis ajoute les nouveaux.
     */
    public function syncRoleGroups(LdapUser $ldapUser, string $newCategorie, string $newFonction): void
    {
        $login = $ldapUser->getLogin();
        $allMainGroups = MainGroups::all(); // Eleves, Profs, Administratifs
        $allFonctions = FunctionGroups::all();

        // Lire les groupes actuels de l'utilisateur (normalisés en lowercase pour comparaisons)
        $memberOf = $ldapUser->getAttribute('memberof') ?? [];
        $currentGroupCns = [];
        foreach ($memberOf as $dn) {
            if (preg_match('/^CN=([^,]+),/i', $dn, $m)) {
                $currentGroupCns[] = $m[1];
            }
        }
        $currentGroupCnsLower = array_map('strtolower', $currentGroupCns);

        // --- Retirer les anciens groupes de catégorie ---
        foreach ($allMainGroups as $mainGroupName) {
            if (strcasecmp($mainGroupName, $newCategorie) === 0) {
                continue; // on garde le nouveau
            }
            if (in_array(strtolower($mainGroupName), $currentGroupCnsLower)) {
                $group = SambaEduGroup::findMainGroup($mainGroupName);
                if ($group) {
                    $group->members()->detach($ldapUser);
                }
            }
        }

        // --- Retirer les anciens groupes de fonction ---
        foreach ($allFonctions as $fonctionName) {
            if (strcasecmp($fonctionName, $newFonction) === 0) {
                continue; // on garde la nouvelle
            }
            if (in_array(strtolower($fonctionName), $currentGroupCnsLower)) {
                $fonctionGroup = SambaEduGroup::query()->where('cn', '=', $fonctionName)->first();
                if ($fonctionGroup) {
                    $fonctionGroup->members()->detach($ldapUser);
                }
            }
        }

        // --- Ajouter le nouveau groupe de catégorie ---
        if (!in_array(strtolower($newCategorie), $currentGroupCnsLower)) {
            $mainGroup = SambaEduGroup::findMainGroup($newCategorie);
            if ($mainGroup) {
                $mainGroup->members()->attach($ldapUser);
            }
        }

        // --- Ajouter le nouveau groupe de fonction ---
        if (!empty($newFonction) && !in_array(strtolower($newFonction), $currentGroupCnsLower)) {
            $fonctionGroup = SambaEduGroup::query()->where('cn', '=', $newFonction)->first();
            if ($fonctionGroup) {
                $fonctionGroup->members()->attach($ldapUser);
            }
        }

        // --- Cas spécial : groupe Portables pour Direction/Gestionnaire ---
        $portablesPerdir = $this->config->get('portables_perdir', '0');
        if ($portablesPerdir == '1') {
            if (in_array($newFonction, ['Direction', 'Gestionnaire'])) {
                if (!in_array(strtolower('Portables'), $currentGroupCnsLower)) {
                    $portablesGroup = SambaEduGroup::query()->where('cn', '=', 'Portables')->first();
                    if ($portablesGroup) {
                        $portablesGroup->members()->attach($ldapUser);
                    }
                }
            } else {
                // Retirer Portables si l'utilisateur n'est plus Direction/Gestionnaire
                if (in_array(strtolower('Portables'), $currentGroupCnsLower)) {
                    $portablesGroup = SambaEduGroup::query()->where('cn', '=', 'Portables')->first();
                    if ($portablesGroup) {
                        $portablesGroup->members()->detach($ldapUser);
                    }
                }
            }
        }

        Log::info('Groupes de rôle synchronisés', [
            'action' => 'user.sync_role_groups',
            'login' => $login,
            'new_categorie' => $newCategorie,
            'new_fonction' => $newFonction,
            'operator' => auth()->user()?->login ?? 'system',
        ]);
    }

    /**
     * Change le rôle (catégorie/fonction) d'un utilisateur.
     *
     * Orchestre : moveUserDn + syncRoleGroups + double-write SQL.
     *
     * @return array{success: bool, message: string}
     */
    public function changeUserRole(string $login, string $newCategorie, string $newFonction, int|string $etab): array
    {
        if (!Gate::allows('update-user')) {
            return ['success' => false, 'message' => 'Vous n\'avez pas les droits pour modifier cet utilisateur.'];
        }

        // Validation : fonction obligatoire pour Administratifs
        if (strtolower($newCategorie) === 'administratifs' && empty($newFonction)) {
            return ['success' => false, 'message' => 'La fonction est obligatoire pour les Administratifs.'];
        }

        $ldapUser = $this->userRepository->findLdapModelByLogin($login);
        if (!$ldapUser) {
            return ['success' => false, 'message' => 'Utilisateur introuvable dans l\'annuaire.'];
        }

        // Catégorie effective pour les groupes et le SQL (Documentaliste/AESH → Profs)
        $effectiveCategorie = $newCategorie;
        if (in_array($newFonction, FunctionGroups::PEDAGOGIQUES) && strtolower($newCategorie) !== 'profs') {
            $effectiveCategorie = 'Profs';
        }

        // 1. Déplacement DN dans l'AD
        $moveResult = $this->moveUserDn($ldapUser, $newCategorie, $newFonction, $etab);
        if (!$moveResult['success']) {
            return $moveResult;
        }

        $ldapUser = $moveResult['ldapUser'];

        // 2. Synchronisation des groupes AD (catégorie effective)
        $this->syncRoleGroups($ldapUser, $effectiveCategorie, $newFonction);

        // 3. Double-write SQL (non bloquant, catégorie effective)
        $this->updateRoleInSql($login, $ldapUser, $effectiveCategorie, $newFonction);

        Log::info('Changement de rôle utilisateur complet', [
            'action' => 'user.change_role',
            'login' => $login,
            'new_categorie' => $newCategorie,
            'new_fonction' => $newFonction,
            'operator' => auth()->user()?->login ?? 'system',
        ]);

        return ['success' => true, 'message' => 'Rôle modifié avec succès.'];
    }

    /**
     * Met à jour le rôle et le DN en SQL après un déplacement AD (double-write).
     * Échec SQL = log, pas de rollback (AD = source de vérité MVP).
     */
    private function updateRoleInSql(string $login, LdapUser $ldapUser, string $newCategorie, string $newFonction): void
    {
        try {
            $roleMap = [
                'eleves' => 'eleve',
                'profs' => 'prof',
                'administratifs' => 'admin',
            ];
            $role = $roleMap[strtolower($newCategorie)] ?? 'autre';

            SqlUserModel::where('login', $login)->update([
                'dn' => $ldapUser->getDn(),
                'role' => $role,
                'ad_synced_at' => now(),
            ]);

            // Re-sync les groupes SQL
            $this->persistUserGroupsToSql($login, $newCategorie, $newFonction, []);

            Log::info("Rôle SQL mis à jour pour $login (double-write)");
        } catch (\Exception $e) {
            Log::error('Échec double-write SQL pour changeUserRole (AD = source de vérité, on continue)', [
                'login' => $login,
                'error' => $e->getMessage(),
            ]);
        }
    }
}