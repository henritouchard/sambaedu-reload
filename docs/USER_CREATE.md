# Documentation : Création d'un utilisateur dans le système SambaEdu Legacy

Ce document décrit toutes les étapes et fonctions impliquées dans la création d'un utilisateur dans le système SambaEdu.

## 📋 Vue d'ensemble du processus

La création d'un utilisateur dans SambaEdu suit un flux complexe impliquant plusieurs couches :
1. **Interface web** (`/annu/add_user.php`)
2. **Validation des données** et **politique de mots de passe**
3. **Génération du login** selon des règles spécifiques
4. **Création dans l'Active Directory** via Samba-tool
5. **Affectation aux groupes** (classes, fonctions, établissements)
6. **Création du dossier personnel** optionnelle
7. **Configuration des services additionnels** (cloud, email, etc.)

---

## 🌐 1. Interface Web - Point d'entrée

### Fichier principal : `/annu/add_user.php`

**Champs requis :**
- **Nom** (`$_POST['nom']`) : Nom de famille de l'utilisateur
- **Prénom** (`$_POST['prenom']`) : Prénom de l'utilisateur
- **Catégorie** (`$_POST['categorie']`) : Eleves, Profs, Administratifs

**Champs optionnels :**
- **Login** (`$_POST['login']`) : Suggestion de login (si vide, généré automatiquement)
- **Date de naissance** (`$_POST['naissance']`) : Format YYYYMMDD
- **Mot de passe** (`$_POST['userpw']`) : Si vide, généré selon la politique
- **Sexe** (`$_POST['sexe']`) : H/F
- **Fonction** (`$_POST['fonction']`) : Pour les personnels
- **Classes** (`$_POST['classes[]']`) : Pour élèves et profs
- **Établissement** (`$_POST['new_etab']`) : En multi-établissements

**Validation initiale :**
```php
if (!have_right($config, SE_USER_ADMIN)) {
    // Redirection si pas les droits admin
}
```

---

## 🔐 2. Validation et Politique de Mots de Passe

### Fonctions de validation utilisées :

#### `verifPwd()` dans `functions.inc.php`
- **Complexité** : Vérifie les règles de complexité si activées
- **Longueur minimale** : Configurable dans `$config['pwdPolicy']`
- **Caractères ASCII** : Le mot de passe doit être en ASCII

#### `verifDateNaissance()` 
- **Format** : Doit être YYYYMMDD
- **Utilité** : Sert pour la correspondance ENT/Pronote

### Politiques de mots de passe (`$config['pwdPolicy']`) :
1. **0/1** : Date de naissance (YYYYMMDD)
2. **2** : Mot de passe aléatoire (longueur configurée)
3. **3** : Code d'activation (`$config['activation_code']`)

---

## 🏷️ 3. Génération du Login (CN)

### Processus de génération :
Le login est généré selon des règles spécifiques définies dans le système legacy.

**Format typique :**
- **Élèves** : `prenom.nom` (avec adaptations en cas de doublons)
- **Personnels** : `pnom` ou `nom.p` selon la configuration

**Gestion des doublons :**
- Ajout de chiffres si le login existe déjà
- Vérification dans l'AD avant validation finale

---

## 🏢 4. Création dans l'Active Directory

### Fonction principale : `useradd()` dans `includes/samba-tool.inc.php`

```php
function useradd($config, $cn, $prenom, $nom, $userpwd, $naissance, $sexe, $categorie, $employeeNumber)
```

**Paramètres :**
- `$cn` : Login généré
- `$prenom`, `$nom` : Informations personnelles
- `$userpwd` : Mot de passe (ou date de naissance)
- `$naissance` : Date de naissance (hashée dans `physicalDeliveryOffice`)
- `$categorie` : Catégorie (Eleves, Profs, Administratifs)
- `$employeeNumber` : ID externe (Pronote, ENT)

**Commande Samba-tool générée :**
```bash
samba-tool user create '$cn' '$userpwd' \
    --use-username-as-cn \
    --given-name=$prenom \
    --surname=$nom \
    --mail-address='$cn@domaine.fr' \
    --physical-delivery-office='$office_hash' \
    --userou='ou=$categorie,$people_rdn'
```

**Attributs LDAP créés :**
- `objectClass` : top, user
- `sAMAccountName` : Login
- `givenName` : Prénom
- `sn` : Nom
- `mail` : Email
- `physicalDeliveryOffice` : Hash de la date de naissance
- `userPrincipalName` : UPN pour l'authentification

---

## 👥 5. Affectation aux Groupes

### Fonction : `add_user_group()` dans `includes/ldap.inc.php`

**Types de groupes :**

#### 1. **Groupes principaux (obligatoires)**
- **Catégorie** : `ou=Eleves`, `ou=Profs`, `ou=Administratifs`
- **Établissement** : Groupes spécifiques à l'établissement

#### 2. **Groupes de classes**
- **Format** : `Classe_NOMCLASSE`
- **Élèves** : Uniquement une classe principale
- **Profs** : Peuvent être dans plusieurs classes

#### 3. **Groupes de fonctions** (personnels)
- **Direction**, **Secretariat**, **Gestionnaire**
- **Medical**, **VieScol**, **Agent**, **AED**, **Tech**
- **Documentaliste**, **AESH** (pour les profs)

#### 4. **Groupes d'équipe pédagogique**
- **Equipe_NOMCLASSE** : Pour les profs d'une classe

**Processus d'affectation :**
```php
// Ajout au groupe principal
groupaddmember($config, $cn, $categorie);

// Ajout aux classes
if ($categorie == "Eleves" || $categorie == "Profs") {
    foreach ($classes as $classe) {
        groupaddmember($config, $cn, "Classe_" . $classe);
        if ($categorie == "Profs") {
            groupaddmember($config, $cn, "Equipe_" . $classe);
        }
    }
}

// Ajout aux fonctions
if ($categorie != "Eleves" && !empty($fonction)) {
    groupaddmember($config, $cn, $fonction);
}
```

---

## 🏠 6. Création du Dossier Personnel (Optionnel)

### Script : `/usr/share/sambaedu/shares/shares.avail/mkhome.sh`

**Déclenchement :**
- Via le formulaire `add_user.php` si `$_POST['create_home'] == 'y'`
- Automatiquement pour certaines configurations

**Processus :**
```bash
sudo /usr/share/sambaedu/shares/shares.avail/mkhome.sh $cn
```

**Actions réalisées :**
1. **Création du répertoire** : `/home/$cn`
2. **Permissions** : `chown $cn:users /home/$cn`
3. **Droits** : `chmod 755 /home/$cn`
4. **Liens symboliques** : Vers les partages réseau
5. **Profils** : Configuration selon le type d'utilisateur

**Structure créée :**
```
/home/$cn/
├── Bureau/
├── Documents/
├── Téléchargements/
├── Images/
├── Musique/
├── Vidéos/
├── .config/
└── Partages/
    ├── Classes/
    ├── Commun/
    └── Perso/
```

---

## ☁️ 7. Configuration des Services Additionnels

### Cloud / Nextcloud (si configuré)
**Fonction :** `create_nc_api_user()` dans `includes/cloud.inc.php`

**Actions :**
- Création du compte utilisateur Nextcloud
- Génération d'un mot de passe API
- Configuration rclone si nécessaire

### Email (si configuré)
**Configuration :**
- Création de la boîte mail
- Configuration des quotas
- Intégration avec le serveur mail

### Partages réseau
**Configuration Samba :**
- Création des partages personnels
- Configuration des droits d'accès
- Intégration avec les groupes

---

## 🔄 8. Processus Complet (Workflow)

### Étape 1 : Préparation
```php
// 1. Chargement de la configuration
$config = get_config();

// 2. Vérification des droits
if (!have_right($config, SE_USER_ADMIN)) {
    die("Droits insuffisants");
}

// 3. Initialisation de la session
$_SESSION['comptes_crees'] = [];
```

### Étape 2 : Validation des données
```php
// Validation des champs obligatoires
if (empty($nom) || empty($prenom)) {
    $error = "Nom et prénom obligatoires";
}

// Validation du mot de passe
if (!empty($password) && !verifPwd($password, $length, $complexity)) {
    $error = "Mot de passe invalide";
}

// Validation de la date de naissance
if (!empty($naissance) && !verifDateNaissance($naissance)) {
    $error = "Format de date invalide";
}
```

### Étape 3 : Préparation pour l'import ENT
```php
// Structure pour l'import ENT
$ent[0] = [
    'structure' => $new_etab,
    'login' => $originallogin,
    'firstName' => $prenom,
    'lastName' => $nom,
    'birthDate' => $naissance,
    'code' => $password,
    'type' => map_profil($categorie, "manuel"),
    'fonction' => $fonction,
    'sex' => $sexe,
    'Id Pronote' => $id,
    'allClasses' => $allClasses
];
```

### Étape 4 : Création effective
```php
// 1. Vérrouillage pour éviter les doublons
$session = "add_user_" . session_id();
apcu_add('ent_lock', ['lock' => $session], $config['ent_timeout']);

// 2. Matching avec l'AD
match_ad_from_ent($config, 120, true, false, true);

// 3. Création/mise à jour
$ret = update_user_ent($config, $match, $group, $html, false, true, false, "all", $session);

// 4. Ajout aux groupes
update_group_ent($config, $group, $html, false, true, $session);

// 5. Nettoyage
apcu_delete('ent_lock');
cache_delete('ent');
```

---

## 📊 9. Données Stockées et Retournées

### En session (`$_SESSION['comptes_crees']`)
```php
$nouveau = [
    'nom' => "$nom",
    'pre' => "$prenom", 
    'cn' => $group[0]['ad']["cn"],
    'pwd' => $group[0]['ent']["code"]
];
$_SESSION['comptes_crees'][] = $nouveau;
```

### Affichage à l'utilisateur
```
L'utilisateur Jean DUPONT a été créé avec succès.
Son identifiant est jdupont
Son mot de passe initial est 20000101
```

---

## ⚠️ 10. Gestion des Erreurs

### Types d'erreurs possibles :
1. **Droits insuffisants** : `have_right() == false`
2. **Login déjà existant** : `samba-tool` retourne une erreur
3. **Mot de passe invalide** : `verifPwd() == false`
4. **Date invalide** : `verifDateNaissance() == false`
5. **Erreur LDAP** : `ldap_add() == false`
6. **Erreur Samba-tool** : Code retour != 0

### Messages d'erreur :
```php
echo "<div class='error_msg'>Erreur lors de la création du nouvel utilisateur $prenom $nom</div>";
```

---

## 🔧 11. Fonctions Utilitaires Clés

### Dans `includes/samba-tool.inc.php`
- `useradd()` : Création utilisateur AD
- `groupaddmember()` : Ajout à un groupe
- `sambatool()` : Exécution des commandes samba-tool

### Dans `includes/ldap.inc.php`
- `create_ad_user()` : Création directe LDAP
- `add_user_group()` : Gestion des groupes
- `search_ad()` : Recherche dans l'AD

### Dans `includes/functions.inc.php`
- `verifPwd()` : Validation mot de passe
- `verifDateNaissance()` : Validation date
- `create_random_password()` : Génération mot de passe

### Dans `includes/ent.inc.php`
- `update_user_ent()` : Mise à jour via ENT
- `match_ad_from_ent()` : Matching ENT/AD
- `create_ent_user()` : Création utilisateur ENT

---

## 📝 12. Logs et Traçabilité

### Logs système :
- **Logs LDAP** : `/var/log/sambaedu/ldap.log`
- **Logs Samba** : `/var/log/sambaedu/samba.log`
- **Logs Apache** : `/var/log/apache2/error.log`

### Logs applicatifs :
- **Création utilisateurs** : Dans la base de données
- **Erreurs** : Via `trigger_error()`
- **Audit** : Via `apcu_store()` pour le suivi

---

## 🚀 13. Optimisations et Cache

### Cache APCu utilisé :
- `ent_lock` : Verrouillage des imports
- `ldap_cache_invalid` : Invalidation cache LDAP
- `ad_users` : Cache des utilisateurs AD
- `ent` : Données temporaires ENT

### Durées de cache :
- **Cache LDAP** : 60 secondes
- **Lock ENT** : Selon `$config['ent_timeout']`
- **Données utilisateurs** : Variable selon la configuration

---

## 📋 14. Checklist de Création

### ✅ Pré-requis :
- [ ] Droits administrateur (`SE_USER_ADMIN`)
- [ ] Configuration LDAP chargée
- [ ] Connexion AD établie

### ✅ Données requises :
- [ ] Nom et prénom valides
- [ ] Catégorie définie
- [ ] Établissement spécifié (multi-établissements)

### ✅ Validation :
- [ ] Mot de passe ou date de naissance
- [ ] Format des dates (YYYYMMDD)
- [ ] Classes pour élèves/profs
- [ ] Fonction pour personnels

### ✅ Création :
- [ ] Génération du login unique
- [ ] Création dans l'AD
- [ ] Affectation aux groupes
- [ ] Création dossier personnel (optionnel)

### ✅ Post-création :
- [ ] Vérification dans l'AD
- [ ] Test de connexion
- [ ] Configuration services additionnels
- [ ] Nettoyage du cache

---

## 🔗 15. Fichiers et Fonctions Référencés

### Fichiers principaux :
- `/annu/add_user.php` : Interface web
- `/includes/samba-tool.inc.php` : Fonctions Samba-tool
- `/includes/ldap.inc.php` : Fonctions LDAP
- `/includes/ent.inc.php` : Gestion ENT
- `/includes/functions.inc.php` : Utilitaires

### Scripts système :
- `/usr/share/sambaedu/shares/shares.avail/mkhome.sh` : Création dossiers
- `/usr/bin/samba-tool` : Commandes AD

### Routes web :
- `/annu/add_user.php` : Formulaire création
- `/annu/add_user_group.php` : Ajout aux groupes
- `/annu/people.php` : Voir l'utilisateur créé

---

Cette documentation couvre l'ensemble du processus de création d'utilisateur dans le système SambaEdu Legacy, de l'interface web à la création effective dans l'Active Directory et la configuration des services associés.
