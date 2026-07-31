# Domaine GPEI — le flux d'alimentation académique

_Dernière mise à jour : 2026-07-30. Observations chiffrées mesurées sur la livraison réelle `CLG95_GPEI_Complet_20251024` (114 collèges du Val-d'Oise, 66 579 élèves, 9 648 personnels, 130 Mo)._

Ce document explique **ce que le flux GPEI nous fournit**, **sous quelle forme**, et **ce qu'il ne fournit pas**. Il ne décrit pas l'implémentation de l'import (cf. « Références » en fin de page).

---

## 1. Qu'est-ce que GPEI ?

**GPEI** (*Gestion des Parcs des Équipements Informatiques*) est une **extraction spécifique de l'Annuaire Académique Fédérateur (AAF)**, produite par un traitement national du ministère de l'Éducation nationale. C'est la source d'alimentation recommandée par le cadre de référence **CARMO** pour les solutions de gestion de parc et de gestion de classe :

> « Il est recommandé d'utiliser l'export AAF spécifique nommé GPEI (Gestion des Parcs des Équipements Informatiques) qui est le résultat d'un traitement national. »
> — CARMO v3.0, note 30, p. 100

Concrètement : l'académie dépose périodiquement des archives sur un serveur, SambaEdu les récupère et en dérive les comptes AD (élèves, enseignants, personnels) ainsi que leurs classes et groupes.

C'est un flux **descendant et en lecture seule** : on ne renvoie rien vers l'académie.

---

## 2. Ce qu'on reçoit

### Nommage des archives

```
CLG95_GPEI_Complet_20251024.zip     ← photo intégrale à une date
CLG95_GPEI_Delta_20251103.zip       ← seulement les mouvements depuis la précédente
```

| Type | Contenu | Usage |
|---|---|---|
| **Complet** | l'intégralité de la population à la date d'export | point de départ, typiquement en début d'année |
| **Delta** | uniquement les créations / modifications / suppressions | mise à jour quotidienne ou hebdomadaire |

La règle d'assemblage : on prend **le Complet le plus récent**, puis **tous les Delta postérieurs à sa date**, dans l'ordre chronologique. Les Complet plus anciens et les Delta antérieurs sont sans objet.

### Contenu d'une archive

L'archive contient un répertoire daté (`20251024/`) avec des fichiers XML découpés par lots :

| Fichier | Nombre | Rôle |
|---|---|---|
| `…_EtabEducNat_0000.xml` | 1 | les établissements (UAI, nom, ville, académie…) |
| `…_PersEducNat_0000.xml` … `_0001.xml` | 2 | les personnels (enseignants, direction, vie scolaire, médico-social…) |
| `…_Eleve_0000.xml` … `_0019.xml` | 20 | les élèves |
| `…_InfosEducNat_0000.xml` | 1 | **métadonnées** de la génération (année scolaire, dates, périmètre) |
| `….md5` | 1 | empreinte de contrôle |

> ⚠️ **Ordre de traitement** : les fichiers `_Etab…` doivent être lus **en premier**, car les personnes y référencent leur établissement de rattachement. L'ordre alphabétique ne suffit pas (`_Eleve_` précède `_EtabEducNat_`).

Le fichier `InfosEducNat` n'est pas un fichier de population : il décrit la livraison elle-même.

```xml
<param name="GPEIAnneeScolaire"><value>2025</value></param>
<param name="GPEIDateExport"><value>24/10/2025</value></param>
<param name="GPEIgeneration"><value>
    listeDept=[095]
    listeSecteur=[PU]
    listeType=[CLG]
    listeTransformation=[GPEI2D_eleve, GPEI2D_etab, GPEI2D_pen]
</value></param>
```

On y lit le périmètre exact de l'export : ici, les **collèges publics du département 095**.

---

## 3. Structure d'un fichier XML

Format `ficAlimMENESR` (DTD AAF). Trois types d'opérations coexistent :

| Balise | Signification | Présent dans |
|---|---|---|
| `<addRequest>` | l'enregistrement complet d'une personne ou structure | Complet et Delta |
| `<modifyRequest>` | modification partielle d'un enregistrement existant | Delta |
| `<deleteRequest>` | suppression (seul l'identifiant est fourni) | Delta |

Un enregistrement type :

```xml
<addRequest>
  <operationalAttributes>
    <attr name="categoriePersonne"><value>Eleve</value></attr>
  </operationalAttributes>
  <identifier><id>16280193</id></identifier>
  <attributes>
    <attr name="GPEIPersonJointure"><value>16280193</value></attr>
    <attr name="GPEIPersonNom"><value>SCARABODI</value></attr>
    <attr name="GPEIPersonPrenom"><value>Marc-aurele</value></attr>
    <attr name="GPEIPersonDateNaissance"><value>19/09/2007</value></attr>
    <attr name="GPEIPersonStructRattach"><value>7380</value></attr>
    <attr name="GPEIElvNiveau"><value>2116$3EME</value></attr>
    <attr name="GPEIElvDiv"><value>7380$3E$3E</value></attr>
    <attr name="GPEIElvGpes"><value/></attr>
    <attr name="GPEIElvMatieres">
      <value>7380$020700$FRANCAIS</value>
      <value>7380$061300$MATHEMATIQUES</value>
    </attr>
  </attributes>
</addRequest>
```

Trois points structurants :

1. **`categoriePersonne` détermine le profil.** Trois valeurs : `Eleve`, `PersEducNat`, `EtabEducNat`.
2. **Un attribut peut être multivalué** — plusieurs balises `<value>` dans un même `<attr>`.
3. **Une valeur peut être composite**, avec `$` comme séparateur de sous-champs (voir ci-dessous).

### La syntaxe `$`

C'est la convention la plus déroutante du format. Une valeur unique encode plusieurs informations :

| Attribut | Valeur brute | Lecture |
|---|---|---|
| `GPEIElvDiv` | `7380$3E$3E` | établissement `7380`, division `3E`, libellé `3E` |
| `GPEIElvNiveau` | `2116$3EME` | code niveau `2116`, libellé `3EME` |
| `GPEIElvMatieres` | `7380$061300$MATHEMATIQUES` | établissement, code matière, libellé |
| `GPEIPersonFonctions` | `7380$ENS$ENSEIGNEMENT DEVANT ELEVES$…$MATHEMATIQUES` | établissement, code fonction, libellé, id, discipline |

Le **premier champ est presque toujours l'identifiant d'établissement** — c'est ce qui permet à une même personne d'exercer dans plusieurs établissements.

⚠️ Le nombre de sous-champs n'est pas garanti : certaines valeurs sont tronquées (`205000$3A_ESPAGNOL$` sans libellé). Toute lecture doit tolérer les champs manquants.

---

## 4. Ce que le flux met à disposition

### Élèves (`Eleve`)

| Attribut | Contenu |
|---|---|
| `GPEIPersonJointure` | **identifiant pivot** — clé de rapprochement stable entre livraisons |
| `GPEIElvINE` | identifiant national élève |
| `GPEIEleveStructRattachId` | identifiant SIECLE |
| `GPEIPersonNom`, `GPEIPersonPrenom`, `GPEIPersonAutresPrenoms`, `GPEIPersonCivilite` | état civil |
| `GPEIPersonDateNaissance` | date de naissance (`JJ/MM/AAAA`) |
| `GPEIPersonStructRattach` | établissement de rattachement |
| `GPEIElvNiveau` | niveau (`3EME`, `4EME`…) |
| `GPEIElvMEF` | module élémentaire de formation |
| `GPEIElvDiv` | **division (classe)** — toujours une seule |
| `GPEIElvGpes` | **groupes** — zéro à n |
| `GPEIElvMatieres` | matières suivies |
| `GPEIEleveDateEntreeStructRattach` / `…DateSortie…` | dates d'entrée et de sortie |

### Personnels (`PersEducNat`)

Même socle d'état civil, plus :

| Attribut | Contenu |
|---|---|
| `GPEIPersonMail` | adresse professionnelle (absente pour les élèves) |
| `GPEIPersonFonctions` | **la fonction exercée**, par établissement — c'est elle qui détermine le profil |
| `GPEIEnsDiv` | divisions où la personne intervient |
| `GPEIEnsGpes` | groupes où la personne intervient |
| `GPEIEnsMatieres` | matières enseignées |

Codes fonction rencontrés dans le flux réel, par fréquence :

| Code | Libellé | Occurrences |
|---|---|---|
| `ENS` | Enseignement devant élèves | 4 730 |
| `-` | Sans objet | 2 662 |
| `PSY` | Psychologue de l'Éducation nationale | 2 109 |
| `ASH` / `AES` | Accompagnement élèves en situation de handicap | 1 937 |
| `AED` / `ADE` | Assistant d'éducation | 1 669 |
| `ADF` | Personnels administratifs | 533 |
| `DIR` | Direction | 249 |
| `EDU` | Éducation (vie scolaire) | 181 |
| `MDS` | Personnels médico-sociaux | 176 |
| `DOC` | Documentation | 121 |
| `ASE`, `TEC` | Assistant étranger, personnels techniques | 22 |

Une même personne peut porter **plusieurs fonctions dans plusieurs établissements**.

### Établissements (`EtabEducNat`)

`GPEIStructureUAI` (le code RNE), `GPEIStructureNomCourant`, `GPEIStructureVille`, `GPEIStructureCodePostal`, `GPEIStructureTelephone`, `GPEIStructureMailSI`, `GPEIStructureTypeStruct`, `GPEIServAcAcademie`, `GPEIEtablissementBassin`, ainsi que les attributs propres au premier degré (`GPEIEcoleCirconscription`, `GPEIEcoleCommune`, `GPEIEcoleRPI`).

---

## 5. Ce que le flux ne fournit pas

C'est aussi important que le reste.

- **Aucun login ni mot de passe.** Les identifiants de connexion sont générés par SambaEdu, pas fournis par l'académie.
- **Aucune hiérarchie entre divisions et groupes.** Le cadre CARMO définit `EleveClasse`, `EleveGroupes` et `EleveNiveau` comme trois attributs **indépendants** ; la relation « ce groupe est un sous-groupe de cette classe » n'existe nulle part dans le flux. Elle ne peut qu'être **inférée** (§ 6).
- **Aucune garantie de présence.** Dans le dictionnaire CARMO (Tableau 9, p. 99), classe, groupes et niveau sont tous marqués **facultatifs**. Le flux réel le confirme : **seuls 21 établissements sur 114 renseignent les groupes des élèves** (6 724 élèves sur 66 579).
- **Aucune donnée sur les responsables légaux** dans cette extraction.

Le cadre reconnaît lui-même cette limite et oriente vers une autre source :

> « le référentiel d'identité sera de préférence le référentiel le plus complet et le plus à jour de ces données (par exemple celui de l'ENT qui intègre l'ensemble des groupes mis en œuvre dans l'établissement) »
> — CARMO v3.0, § 14.6.1, p. 95

Il prévoit explicitement le mode mixte : import des personnes depuis l'annuaire, **saisie des groupes directement dans l'outil** (§ 14.6.1.2, p. 96), et la création de groupes virtuels par l'enseignant (§ 17.1, reco #9.9).

---

## 6. Divisions, groupes, niveaux : ce qu'on peut en tirer

### Cardinalités vérifiées

| | Élève | Personnel |
|---|---|---|
| division | **exactement 1** (66 579 / 66 579, sans exception) | 0 à n |
| groupes | 0 à n | 0 à n |
| niveau | 1 | — |

La division étant unique par élève, la question « ce groupe est-il un sous-ensemble de cette classe ? » est mathématiquement bien posée.

### Reconstruire la hiérarchie

Deux méthodes, par ordre de fiabilité décroissante.

**a) Par les membres** — comparer les élèves du groupe à ceux de chaque division. Exacte, mais applicable **seulement aux ~10 % de groupes ayant des membres élèves déclarés**. Sur ce périmètre :

| Nature réelle du groupe | Part |
|---|---|
| sous-groupe inclus dans **une** division | 69,5 % |
| union de plusieurs divisions d'un **même niveau** | 26,6 % |
| union de divisions de **niveaux différents** | 3,9 % |

**b) Par le nom** — les établissements nomment souvent leurs groupes en préfixant la division :

```
301    →  301P1, 301P2, 301_ESP
3EME3  →  3EME3_3E3 P1, 3EME3G2
3B     →  3B_ESP2, 3BESP, 3BG1
```

Séparateurs observés : `P` (partie de classe, 8 069 cas), `_` (3 077), `G` (groupe, 2 818), espace (1 320), `-` (840), ou aucun.

Répartition des 10 618 groupes du flux :

| Cas | Part | Exemples |
|---|---|---|
| préfixé par une division de l'établissement | **55 %** | `301P1`, `3B_ESP2` |
| préfixé par le niveau seulement | **25 %** | `5MATHS3`, `6FRANC1`, `4LATIN` |
| aucun pattern | **20 %** | `CHORALE`, `ULIS`, `SECTION BASKET`, `FR_6E_PENELOPE` |

Point rassurant : sur les 5 837 groupes préfixés, **aucun cas d'ambiguïté** (jamais deux divisions candidates dans le même établissement).

⚠️ **Ces conventions ne sont pas normées.** CARMO précise que les libellés sont « fournis à titre indicatif seulement » — ils viennent de la saisie SIECLE/STS de chaque établissement. Les pourcentages ci-dessus sont une observation sur **une académie**, pas une garantie. Toute exploitation du nommage doit donc être configurable, et céder le pas à la méthode par membres quand elle est disponible.

### Cascade recommandée

1. inclusion ensembliste des élèves, si le groupe a des membres → parent exact ;
2. sinon, préfixe = division de l'établissement → 55 %, sans ambiguïté mesurée ;
3. sinon, préfixe = niveau → rattachement au niveau, axe de regroupement légitimé par CARMO (`EleveNiveau` : « les règles de gestion ou de distribution peuvent être différentes selon les niveaux ») ;
4. sinon, groupe transversal sans parent.

Signal complémentaire non encore évalué pour les groupes sans membres élèves : l'intersection des divisions (`GPEIEnsDiv`) des enseignants qui interviennent dans le groupe.

---

## 7. Deux façons d'importer, un seul moteur

Le flux GPEI n'est **pas** traité par une chaîne dédiée : il alimente le pipeline d'intégration AD commun à toutes les sources (CSV, Pronote, Kosmos, SIECLE, STS, export ENT/OpenENT, ASM…).

```
┌─ manuel : formulaire d'import ─┐
│                                 │──► 1. LECTURE ──► 2. RAPPROCHEMENT ──► 3. ÉCRITURE AD
└─ automatique : cron + SSH/SCP ─┘     parse le XML     apparie avec les    crée / modifie /
                                       vers un tableau  comptes AD          déplace, gère les
                                       normalisé        existants           classes et groupes
```

| Étage | Ce qui s'y passe |
|---|---|
| **1. Lecture** | parsing XML → tableau d'utilisateurs normalisé, tagué avec sa source d'origine |
| **2. Rapprochement** | appariement flux ↔ AD par identifiant pivot, puis par nom/prénom/naissance ; arbitrage manuel possible (associer, renommer, mettre à la corbeille) |
| **3. Écriture AD** | création, modification, déplacement de comptes ; mise à jour des appartenances aux classes et groupes ; génération du listing de mots de passe |

Le rapprochement s'appuie en priorité sur `GPEIPersonJointure`, stocké au premier import : c'est ce qui rend les imports suivants idempotents et permet de suivre un élève qui change d'établissement.

**Conséquence pratique** : ajouter une nouvelle source d'alimentation ne demande pas de réécrire l'intégration AD, seulement de fournir l'étage 1 (un parseur) et un matcher dédié.

---

## 8. Points de vigilance

- **Volume.** 130 Mo décompressés pour une seule académie ; le parsing en DOM charge tout en mémoire. Sur un serveur d'établissement modeste, c'est le goulot d'étranglement principal.
- **Traitement par lots.** L'import manuel s'exécute par tranches de temps avec relance automatique, pour ne pas dépasser le timeout PHP. Un import « tous établissements » peut durer très longtemps.
- **Dates de sortie.** Un élève dont la date de sortie est dépassée (avec une semaine d'anticipation) est basculé en corbeille plutôt que supprimé.
- **Suppressions.** Dans les Delta, `deleteRequest` ne fournit que l'identifiant — impossible de savoir de qui il s'agit sans l'état importé précédemment.
- **RGPD.** CARMO impose la minimisation : « seules les informations nécessaires au fonctionnement de la solution DOIVENT être exploitées ». Le flux contient plus de données qu'on n'en utilise (INE, MEF, téléphone…) ; ne persister que le nécessaire, et tracer toute donnée **dérivée** (comme une hiérarchie de groupes inférée) au même titre que les données sources.

---

## Références

**Cadre réglementaire**
- CARMO v3.0 (décembre 2018), § 14.6 « Alimentation des MxM et des outils de gestion de classe en données », p. 95-100 — dictionnaire de données (Tableau 9, p. 99), référentiels utilisables (§ 14.6.3), note 30 sur GPEI.
- CARMO v3.0, § 17.1 p. 107 — services de gestion de classe, création de groupes virtuels.

**Code legacy SambaEdu**
- `sambaedu/includes/gpei.inc.php` — `read_gpei_xml()` (parsing XML), `read_attrs()` (table des attributs), `sync_gpei()` (récupération SSH, sélection Complet/Delta, répartition de charge).
- `sambaedu/includes/ent.inc.php` — moteur d'intégration AD multi-sources : `import_ent()`, `match_ad_from_ent()`, `update_ad_from_ent()`.
- `sambaedu/annu/import_gpei.php` — import manuel (machine à états).
- `sambaedu/annu/sync_cron.php` — import automatisé.

**Outillage**
- `sambaedu/tools/gpei-import/` — lecteur GPEI autonome (sans dépendance à la stack), lecture en flux à mémoire constante. Couvre l'étage 1 uniquement.
