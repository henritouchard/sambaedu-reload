# Gestion des Groupes de Postes de Travail

## Vue d'ensemble

SambaEdu distingue deux types de groupes de postes de travail dans Active Directory :

| Type | Emplacement AD | Objet AD | Usage principal |
|------|----------------|----------|-----------------|
| **Groupe physique** | `OU=Computers` | OU (Organizational Unit) | GPO, hiérarchie des salles |
| **Groupe logique** | `OU=Parcs` | CN (Security Group) | WPKG, permissions |

## Pourquoi cette distinction ?

### Contrainte du mode d'application des GPO

Le système legacy de SambaEdu utilise le **ciblage par OU** pour appliquer les GPO (Group Policy Objects). Cette méthode lie directement une GPO à une Organizational Unit dans la hiérarchie AD.

```
OU=Computers
├── OU=batiment-a          ← GPO "Batiment A" liée ici
│   ├── OU=salle-info-101  ← GPO "Salle Info 101" liée ici (hérite aussi de "Batiment A")
│   │   ├── CN=pc-info-01$
│   │   └── CN=pc-info-02$
│   └── OU=salle-info-102
└── OU=batiment-b
```

**Conséquence** : Les GPO sont appliquées de manière hiérarchique selon l'emplacement de la machine dans l'arborescence des OU. Une machine dans `OU=salle-info-101` hérite automatiquement des GPO de `OU=batiment-a` et de `OU=Computers`.

### Groupes logiques pour WPKG

Les groupes logiques dans `OU=Parcs` sont des **groupes de sécurité** (CN) qui permettent :
- Le déploiement d'applications via WPKG
- L'attribution de permissions
- Le regroupement flexible de machines (indépendamment de leur emplacement physique)

```
OU=Parcs
├── CN=parc-bureautique     ← Contient des machines de plusieurs salles
├── CN=parc-multimedia
└── CN=salle-info-101       ← Miroir du groupe physique (même nom)
```

## Modèle de données SQL

### Table `workstation_groups`

| Colonne | Type | Description |
|---------|------|-------------|
| `id` | int | Clé primaire |
| `name` | string | Identifiant unique (slug) |
| `is_physical` | boolean | `true` = OU dans Computers, `false` = CN dans Parcs |
| `parent_id` | int | FK vers le groupe parent (hiérarchie pour groupes physiques) |
| `ad_dn` | string | Distinguished Name dans AD |
| `ad_guid` | string | objectGUID dans AD |

### Table pivot `workstation_group_workstation`

| Colonne | Type | Description |
|---------|------|-------------|
| `workstation_id` | int | FK vers workstations |
| `workstation_group_id` | int | FK vers workstation_groups |
| `physical` | boolean | `true` = lien physique (OU parent), `false` = lien logique (membre du groupe) |

### Relations

```
Workstation ──┬── physical=true ──→ WorkstationGroup (is_physical=true)  [1 seul]
              └── physical=false ──→ WorkstationGroup (is_physical=false) [0..N]
```

Une workstation :
- Appartient à **exactement 1** groupe physique (son OU parent dans AD)
- Peut appartenir à **0 ou plusieurs** groupes logiques (membre de groupes dans OU=Parcs)

## Scopes Eloquent

```php
// Récupérer uniquement les groupes physiques (salles)
WorkstationGroup::physical()->get();

// Récupérer uniquement les groupes logiques (parcs)
WorkstationGroup::logical()->get();

// Vérifier le type d'un groupe
$group->isPhysical(); // true si OU dans Computers
$group->isLogical();  // true si CN dans Parcs
```

## Import depuis AD

L'ordre d'import est important :

1. **Workstations** - Import des machines depuis `OU=Computers`
2. **Groupes physiques** - Import des OU depuis `OU=Computers`, création des liens pivot avec `physical=true`
3. **Groupes logiques** - Import des CN depuis `OU=Parcs`, création des liens pivot avec `physical=false`
4. **AppProfiles** - Import des profils applicatifs

---

## Évolution future : Filtrage de sécurité GPO

### Situation actuelle

Le legacy utilise le **ciblage par OU** (`gposetlink`) :
- La GPO est liée directement à l'OU
- Toutes les machines dans l'OU reçoivent la GPO
- La hiérarchie est rigide

### Alternative : Filtrage de sécurité

Une évolution possible serait de passer au **filtrage de sécurité par groupe** :
- La GPO est liée à `OU=Computers` (racine)
- Un groupe de sécurité est ajouté comme filtre
- Seules les machines membres du groupe reçoivent la GPO

#### Avantages

- **Flexibilité** : Un groupe peut contenir des machines de différentes OU
- **Simplification** : Plus besoin de maintenir une hiérarchie d'OU complexe
- **Unification** : Groupes physiques et logiques pourraient être gérés de la même manière

#### Implémentation requise

```php
/**
 * Ajoute un groupe au filtrage de sécurité d'une GPO
 * 
 * @param array $config Configuration Samba
 * @param string $gpoGuid GUID de la GPO
 * @param string $groupDn DN du groupe de sécurité
 * @return bool
 */
function gposetsecurityfilter(array $config, string $gpoGuid, string $groupDn): bool
{
    // Option 1: Via samba-tool gpo setacl (si disponible)
    // $command = "gpo setacl " . escapeshellarg($gpoGuid) . " --add " . escapeshellarg($groupDn);
    
    // Option 2: Modification directe des ACL via LDAP
    // Modifier l'attribut nTSecurityDescriptor de la GPO
    // Ajouter une ACE (Access Control Entry) pour le groupe
    
    // Complexité : Moyenne à élevée
    // - samba-tool ne gère pas directement le filtrage de sécurité
    // - Nécessite manipulation des ACL LDAP (nTSecurityDescriptor)
}
```

#### Étapes de migration

1. **Créer `gposetsecurityfilter()`** : Fonction pour ajouter un groupe au filtrage
2. **Modifier `gposetlink()`** : Lier les GPO à `OU=Computers` au lieu des sous-OU
3. **Migrer les GPO existantes** : Script de migration pour convertir les liens OU en filtres de sécurité
4. **Adapter l'interface** : Permettre la gestion des filtres de sécurité dans l'UI

#### Complexité estimée

| Aspect | Difficulté |
|--------|------------|
| Fonction `gposetsecurityfilter()` | Moyenne |
| Modification des ACL LDAP | Élevée |
| Migration des GPO existantes | Moyenne |
| Tests de non-régression | Élevée |

### Conclusion

Le mode actuel (ciblage par OU) est fonctionnel et bien intégré au legacy. Le passage au filtrage de sécurité offrirait plus de flexibilité mais nécessite un travail significatif sur la manipulation des ACL AD. Cette évolution pourrait être envisagée dans une version future de SambaEdu.
