# TODO : Réimplémentation de la mise à jour utilisateur

## Analyse du fichier legacy `annu/mod_user_entry.php`

### Flux de mise à jour utilisateur

```
┌─────────────────────────────────────────────────────────────────┐
│                    mod_user_entry.php                           │
├─────────────────────────────────────────────────────────────────┤
│ 1. Vérification des droits                                      │
│    - have_right($config, SE_USER_ADMIN)                         │
│    - OU is_my_eleve() + SE_USER_PASSWORD_INIT/READ/MODIFY       │
├─────────────────────────────────────────────────────────────────┤
│ 2. Récupération utilisateur existant                            │
│    - search_user($config, $cn)                                  │
│    - decode_birthdate() si hash présent                         │
│    - Extraction des IDs (Siecle, GPEI, ASM, Pronote, NC)        │
│    - Extraction des titles (id ENT, externalId AAF)             │
├─────────────────────────────────────────────────────────────────┤
│ 3. Validation des entrées                                       │
│    - verifEntree($nom), verifEntree($prenom)                    │
│    - verifDescription($description)                             │
│    - verifPwd($userpwd)                                         │
│    - verifDateNaissance($naissance)                             │
├─────────────────────────────────────────────────────────────────┤
│ 4. Modification du login (si admin et login changé)             │
│    - modify_ad_login($config, $mod, $html)                      │
│      → move_ad() : déplace le DN                                │
│      → modify_ad() : met à jour samaccountname                  │
│      → sudo mv /home/old /home/new                              │
│      → sudo mv profiles                                         │
│      → mv dossiers classes (si élève)                           │
├─────────────────────────────────────────────────────────────────┤
│ 5. Préparation des attributs LDAP                               │
│    - sn : nom (avec normalisation accents)                      │
│    - displayname : prénom + nom                                 │
│    - givenname : prénom                                         │
│    - mail : email (si admin)                                    │
│    - description : description (si admin)                       │
│    - physicaldeliveryofficename : date naissance chiffrée RSA   │
│    - employeenumber : idSiecle,idGPEI,idASM,idPronote,idNC      │
│    - title : idENT,externalIdAAF                                │
├─────────────────────────────────────────────────────────────────┤
│ 6. Modification LDAP                                            │
│    - modify_ad($config, $cn, "user", $entry, "replace")         │
│      → ldap_mod_replace($config['bind'], $dn, $attrs)           │
│      → Invalidation cache APCu                                  │
├─────────────────────────────────────────────────────────────────┤
│ 7. Changement mot de passe (si fourni)                          │
│    - usersetpassword($config, $cn, $userpwd)                    │
│      → ldap_mod_replace unicodepwd (UTF-16LE)                   │
│      → pwdlastset = 0 si changement requis                      │
└─────────────────────────────────────────────────────────────────┘
```

---

## Plan de réimplémentation Laravel/Livewire

### Phase 1 : Service Layer

#### 1.1 Créer `UserService::updatePersonalInfo()`
```php
// app/Services/UserService.php

public function updatePersonalInfo(string $login, array $data): array
{
    // Validation
    // Préparation attributs LDAP
    // Appel repository
    // Retour résultat
}
```

**Champs à supporter :**
- [ ] `prenom` → `givenname`, `displayname`
- [ ] `nom` → `sn`, `displayname`
- [ ] `email` → `mail`
- [ ] `phone` → `telephonenumber`
- [ ] `description` → `description`

#### 1.2 Créer `UserService::updateIdentifiers()` (admin only)
```php
public function updateIdentifiers(string $login, array $ids): array
{
    // idEnt, idAaf → title
    // idSiecle, idGpei, idNc → employeenumber
}
```

#### 1.3 Créer `UserService::updateBirthdate()` (admin only)
```php
public function updateBirthdate(string $login, string $birthdate): array
{
    // Chiffrement RSA
    // → physicaldeliveryofficename
}
```

#### 1.4 Créer `UserService::changeLogin()` (admin only)
```php
public function changeLogin(string $oldLogin, string $newLogin): array
{
    // Vérifier doublon
    // move_ad (DN)
    // update samaccountname
    // mv home directory
    // mv profiles
    // mv dossiers classes
}
```

---

### Phase 2 : Repository Layer

#### 2.1 Créer `UserRepository::update()`
```php
// app/Repositories/UserRepository.php

public function update(string $login, array $attributes): bool
{
    $ldapUser = $this->findLdapModelByLogin($login);
    if (!$ldapUser) return false;
    
    foreach ($attributes as $key => $value) {
        $ldapUser->setAttribute($key, $value);
    }
    
    return $ldapUser->save();
}
```

#### 2.2 Créer `UserRepository::rename()`
```php
public function rename(string $oldLogin, string $newLogin): bool
{
    // LdapRecord rename/move
}
```

---

### Phase 3 : Composant Livewire

#### 3.1 Mettre à jour `personal-info-form.blade.php`
```php
public function save(): void
{
    $this->validate([...]);
    
    $result = $this->userService->updatePersonalInfo($this->login, [
        'prenom' => $this->prenom,
        'nom' => $this->nom,
        'email' => $this->email,
        'phone' => $this->phone,
        'description' => $this->description,
    ]);
    
    if ($result['success']) {
        $this->dispatch('notify', type: 'success', message: $result['message']);
        // Recharger les données
        $this->user = $this->userService->getByLogin($this->login);
        $this->loadUserData();
    } else {
        $this->dispatch('notify', type: 'error', message: $result['message']);
    }
    
    $this->editMode = false;
}
```

---

### Phase 4 : Fonctionnalités Admin

#### 4.1 Composant `technical-identifiers-form.blade.php`
- Édition des identifiants ENT/AAF/Siecle/GPEI/NC
- Réservé aux admins

#### 4.2 Composant `login-change-form.blade.php`
- Changement de login avec toutes les opérations système
- Réservé aux admins

#### 4.3 Composant `password-reset.blade.php`
- Réinitialisation mot de passe
- Option "forcer changement au prochain login"

---

## Fonctions Legacy à porter

| Fonction Legacy | Nouveau Service/Repository | Priorité |
|-----------------|---------------------------|----------|
| `modify_ad()` | `UserRepository::update()` | Haute |
| `usersetpassword()` | `PasswordService::setPassword()` | Haute |
| `modify_ad_login()` | `UserService::changeLogin()` | Moyenne |
| `encode_birthdate()` | `UserService::encodeBirthdate()` | Basse |
| `decode_birthdate()` | `UserService::decodeBirthdate()` | Basse |
| `verifEntree()` | Validation Laravel | Haute |
| `verifPwd()` | `PasswordService::validatePassword()` | Haute |

---

## Normalisation des noms (à conserver)

Le legacy utilise une normalisation complexe des accents :
```php
strtr(preg_replace("/Æ/", "AE", ...), 
    "'ÂÄÀÁÃÄÅÇÊËÈÉÎÏÌÍÑÔÖÒÓÕ¦ÛÜÙÚÝ¾´áàâäãåçéèêëîïìíñôöðòóõ¨ûüùúýÿ¸", 
    "_AAAAAAACEEEEIIIINOOOOOSUUUUYYZaaaaaaceeeeiiiinoooooosuuuuyyz")
```

**Recommandation :** Créer un helper `StringHelper::normalizeForLdap()` ou utiliser `Str::ascii()` de Laravel.

---

## Checklist de validation

- [ ] Les modifications sont bien enregistrées dans LDAP
- [ ] Le cache APCu est invalidé après modification
- [ ] Les permissions sont vérifiées (admin vs user normal)
- [ ] Les logs sont générés pour audit
- [ ] Les notifications utilisateur fonctionnent
- [ ] Le changement de login déplace tous les fichiers
- [ ] Le mot de passe est correctement encodé en UTF-16LE

---

## Estimation

| Phase | Effort estimé |
|-------|---------------|
| Phase 1 : Services | 4h |
| Phase 2 : Repository | 2h |
| Phase 3 : Livewire | 2h |
| Phase 4 : Admin | 4h |
| Tests | 2h |
| **Total** | **14h** |
