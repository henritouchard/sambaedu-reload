<?php

declare(strict_types=1);

use App\Models\QuotaAuditLog;
use App\Models\QuotaRule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Story 63.4 — **LES QUATRE DÉFAUTS PAR PROFIL DEVIENNENT UN DÉFAUT D'INSTANCE.**
 * Une fois, ici, jamais ailleurs.
 *
 * ---------------------------------------------------------------------------
 * **CE QUE CETTE MIGRATION FERME.** Deux magasins ne se parlaient pas. L'écran des
 * réglages écrivait une clé de `system_settings` (`quota.defaults`, une grille de
 * quatre « profils » × deux partitions) que **PERSONNE ne lisait**. La résolution,
 * elle, lisait des lignes de `quota_rules` de type `default_*` que **seul l'import
 * legacy écrivait**. Sur une instance qui n'avait jamais joué cet import, tout le
 * monde était donc illimité — en silence — pendant que l'écran répondait
 * « Réglages enregistrés ».
 *
 * ---------------------------------------------------------------------------
 * **CETTE MIGRATION NE RÉTRÉCIT JAMAIS UN PLAFOND. C'EST SA RÈGLE PREMIÈRE.**
 *
 * Un plafond qui rétrécit **bloque des gens en écriture** sans que personne n'ait
 * cliqué — et il le fait sur **DEUX PLANS** : sur le système de fichiers, et sur le
 * cloud, dont le balayage de provisionnement réécrit le quota de chaque compte à
 * partir de cette même règle. Un plafond qui s'élargit, lui, ne bloque personne **et
 * ne consomme aucun disque** : un quota PLAFONNE, il n'ALLOUE pas. Entre les deux
 * erreurs possibles, une seule est réparable sans avoir mis des utilisateurs à
 * l'arrêt.
 *
 * ⇒ **La valeur retenue est LA PLUS LARGE parmi les règles par profil existantes**
 * (le plafond illimité, `0`, étant la plus large de toutes). Sur une instance seedée,
 * le défaut devient donc l'ex-valeur administrative, pas l'ex-valeur élève :
 * **personne ne perd de place**. L'administrateur resserre ensuite EXPLICITEMENT, en
 * voyant qui bascule — ce que la carte « Quotas » lui montre avant le clic.
 *
 * ⚠️ **Et parce qu'élargir n'est pas anodin non plus, ça se dit fort** : la carte
 * « Quotas » affiche EN PERMANENCE, tant qu'aucune valeur n'a été enregistrée à la
 * main, ce qui a été regroupé et avec quelles valeurs (clé de réglage
 * {@see self::COLLAPSE_NOTICE_KEY}). L'avertissement disparaît au premier
 * enregistrement manuel.
 * ---------------------------------------------------------------------------
 *
 * **LA TABLE DE DÉCISION, par partition et dans cet ordre :**
 *
 *  1. il existe au moins une règle `default_*` ACTIVE ⇒ **la plus large d'entre
 *     elles devient le défaut d'instance**. Une règle DÉSACTIVÉE n'est jamais
 *     reprise : elle n'avait aucun effet, et la ressusciter en défaut ACTIF poserait
 *     un plafond sur des comptes jusque-là illimités.
 *  2. sinon, la clé `quota.defaults` porte un plafond > 0 ⇒ **la plus large de ses
 *     cellules pour cette partition est reprise**, le plafond dur recalculé par le
 *     pourcentage de dépassement de cette cellule. C'est la SEULE intention que
 *     l'administrateur ait jamais exprimée, et la jeter laisserait l'instance dans
 *     l'état « illimité pour tout le monde » que la story existe pour fermer. **Ce
 *     cas est nommé distinctement** — au journal d'audit et au résumé — parce qu'il
 *     CHANGE le comportement : un plafond qui n'était appliqué à personne devient un
 *     plafond réel.
 *  3. sinon ⇒ **aucune règle n'est créée**. La résolution rend « illimité », comme
 *     avant.
 *
 * **AUCUNE APPLICATION EN MASSE.** La règle est écrite en base et RIEN n'est mis en
 * file : les plafonds système sont réécrits au prochain geste d'écriture ou de
 * recalcul, ou par le geste explicite de la carte « Quotas ». Appliquer d'un coup un
 * plafond qui n'avait jamais rien appliqué (cas 2) mettrait des comptes en
 * dépassement sans que personne n'ait cliqué.
 *
 * ⚠️ **MAIS LE PLAN CLOUD, LUI, N'ATTEND AUCUN CLIC.** Passer de « aucune règle » à
 * « une règle » fait basculer la gouvernance des plafonds cloud : le prochain
 * balayage de provisionnement RÉÉCRIRA le plafond de chaque compte de l'instance, y
 * compris ceux réglés à la main dans le produit cloud. C'est un effet réel de cette
 * migration ; il est donc NOMMÉ au résumé et porté par la ligne d'audit du défaut
 * créé. Le rapport de balayage compte désormais les plafonds qu'il modifie, pour que
 * l'effet se constate au lieu de se déduire.
 *
 * **CE QUI EST PERDU EST TRACÉ.** Les autres défauts par profil disparaissent, chacun
 * avec une ligne `quota_audit_logs` qui porte son type, sa partition et ses valeurs.
 * Le code ne peut pas deviner à la place de l'exploitant qu'un budget particulier
 * mérite d'être reconstitué : il ne peut que lui laisser de quoi le faire — en
 * **règle de groupe**, qui, elle, est explicite.
 *
 * **Transactionnelle et idempotente** : rejouée, elle ne trouve plus de ligne
 * `default_*`, ne recrée rien, n'écrase JAMAIS un défaut d'instance déjà posé (un
 * administrateur a pu le changer entre-temps), et ne touche à AUCUNE règle par
 * utilisateur ni par groupe.
 *
 * **La clé `quota.defaults` n'est pas effacée** : elle devient une donnée inerte que
 * plus aucun code ne lit. L'effacer aurait détruit la seule trace de ce que
 * l'administrateur avait saisi, au moment précis où cette trace sert à expliquer ce
 * qui vient d'être repris.
 * ---------------------------------------------------------------------------
 */
return new class extends Migration
{
    /** Les quatre types morts, cités ici — et NULLE PART ailleurs dans le code. */
    private const LEGACY_TYPES = ['default_eleve', 'default_prof', 'default_admin', 'default_itinerant'];

    /** La clé de réglage lue une DERNIÈRE fois, puis plus jamais. */
    private const LEGACY_SETTING_KEY = 'quota.defaults';

    /**
     * La clé où le regroupement est DÉPOSÉ pour la carte « Quotas », qui l'affiche
     * tant qu'aucune valeur n'a été enregistrée à la main.
     *
     * ⚠️ Elle ne contient volontairement PAS la chaîne de la clé historique : cette
     * clé-là est morte, et une clé voisine la ferait ressusciter aux yeux du grep
     * qui garde sa disparition.
     */
    private const COLLAPSE_NOTICE_KEY = 'quota.profils_regroupes';

    /** Le motif du cas 2, littéral figé : il doit se voir dans l'audit ET au résumé. */
    private const REPRISE_NOTICE = 'valeur reprise d\'un réglage qui n\'était appliqué à personne';

    /** Le motif du cas 1, littéral figé. */
    private const WIDEST_NOTICE = 'la plus large des règles par profil — aucun plafond n\'est rétréci';

    /** Le plan cloud, nommé dans l'audit du défaut créé. */
    private const CLOUD_PLANE_NOTICE = 'SE5 prend la gouvernance des plafonds cloud : le prochain balayage '
        .'de provisionnement réécrira le plafond de chaque compte, y compris ceux réglés à la main dans '
        .'l\'instance';

    /** Partition => clé de la grille de réglage historique. */
    private const PARTITIONS = [
        QuotaRule::PARTITION_HOME => 'home',
        QuotaRule::PARTITION_SAMBAEDU => 'sambaedu',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('quota_rules')) {
            return;
        }

        // La gouvernance des plafonds cloud se lit AVANT : elle bascule dès qu'une
        // règle active existe sur la partition des répertoires personnels.
        $governedBefore = $this->cloudQuotaGoverned();

        $lines = [];
        $collapsed = [];

        DB::transaction(function () use (&$lines, &$collapsed): void {
            $setting = $this->legacyGrid();

            foreach (self::PARTITIONS as $partition => $gridKey) {
                [$summary, $notice] = $this->collapsePartition($partition, $gridKey, $setting, ! $this->cloudQuotaGoverned());

                $lines[] = $summary;

                if ($notice !== null) {
                    $collapsed[$partition] = $notice;
                }
            }

            $this->publishCollapseNotice($collapsed);
        });

        if (! $governedBefore && $this->cloudQuotaGoverned()) {
            $lines[] = sprintf(
                '  PLAN CLOUD : SE5 prend désormais la gouvernance des plafonds cloud — le prochain '
                .'balayage de provisionnement réécrira le quota de %d compte(s), y compris ceux réglés '
                .'à la main dans l\'instance.',
                $this->accountCount(),
            );
        }

        $this->report(
            "[63.4] Défauts de quota par profil → défaut d'instance (la valeur la plus large est "
            ."retenue : aucun plafond n'est rétréci).\n"
            .implode("\n", $lines)
        );
    }

    /**
     * `down()` ne reconstruit PAS les quatre profils : ils n'étaient attachés à
     * rien, et les recréer supposerait de rejouer une devinette que le produit ne
     * porte plus. Redescendre laisse donc le défaut d'instance en place — la
     * résolution d'un code antérieur ne le lira pas, et l'instance retrouvera son
     * état d'avant : « illimité », c'est-à-dire exactement l'état que cette
     * migration corrige.
     *
     * ⚠️ **`up()` SUPPRIME des lignes réelles, et rien ici ne les rend.** Le filet
     * n'est pas dans ce code, il est dans le journal d'audit : chaque règle
     * abandonnée y a laissé ses valeurs sous `old_values`, `performed_by =
     * 'migration:63.4'`. Le chemin de restauration manuelle est écrit dans le
     * runbook QA (`docs/qa/domains/filesystem.md`, section « Story 63.4 ») — s'il
     * n'est pas écrit, il n'existe pas.
     */
    public function down(): void
    {
        // Volontairement vide — voir le docblock ci-dessus.
    }

    /**
     * SE5 gouverne-t-il les plafonds cloud ? Même lecture que le provisionnement :
     * la moindre règle active sur la partition des répertoires personnels suffit.
     */
    private function cloudQuotaGoverned(): bool
    {
        return DB::table('quota_rules')
            ->where('partition', QuotaRule::PARTITION_HOME)
            ->where('is_active', true)
            ->exists();
    }

    /** Le nombre de comptes que le balayage cloud toucherait. `0` si on ne sait pas. */
    private function accountCount(): int
    {
        if (! Schema::hasTable('users')) {
            return 0;
        }

        try {
            return (int) DB::table('users')->where('is_active', true)->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Le regroupement, DÉPOSÉ pour l'écran. Rien n'est écrit s'il n'y a rien à dire :
     * un avertissement permanent sur une instance qui n'a rien perdu serait du bruit.
     *
     * @param  array<string, array{fondus: list<string>, retenu: string, origine: string}>  $collapsed
     */
    private function publishCollapseNotice(array $collapsed): void
    {
        if ($collapsed === [] || ! Schema::hasTable('system_settings')) {
            return;
        }

        $fondus = [];

        foreach ($collapsed as $notice) {
            foreach ($notice['fondus'] as $valeur) {
                $fondus[] = $valeur;
            }
        }

        if ($fondus === []) {
            return;
        }

        $payload = json_encode([
            'fondus' => array_values(array_unique($fondus)),
            'partitions' => $collapsed,
        ], JSON_UNESCAPED_UNICODE);

        $existing = DB::table('system_settings')->where('key', self::COLLAPSE_NOTICE_KEY)->exists();

        if ($existing) {
            DB::table('system_settings')
                ->where('key', self::COLLAPSE_NOTICE_KEY)
                ->update(['value' => $payload, 'updated_at' => now()]);

            return;
        }

        DB::table('system_settings')->insert([
            'key' => self::COLLAPSE_NOTICE_KEY,
            'value' => $payload,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Une partition : on décide, on écrit, on trace ce qu'on abandonne.
     *
     * @param  array<string, mixed>  $setting
     * @return array{0: string, 1: array{fondus: list<string>, retenu: string, origine: string}|null}
     */
    private function collapsePartition(
        string $partition,
        string $gridKey,
        array $setting,
        bool $cloudGovernanceFlips,
    ): array {
        $legacy = DB::table('quota_rules')
            ->where('partition', $partition)
            ->whereIn('type', self::LEGACY_TYPES)
            ->get();

        // ⚠️ **UNE RÈGLE DÉSACTIVÉE NE SE REPREND PAS.** Elle n'avait aucun effet ;
        // en faire un défaut d'instance ACTIF poserait un plafond sur des comptes
        // jusque-là illimités — l'exact contraire de la règle de cette migration.
        $active = $legacy->filter(fn ($row) => (bool) $row->is_active === true);

        $retained = $this->widestRule($active);
        $origin = $retained === null ? null : self::WIDEST_NOTICE;

        if ($retained === null) {
            $fromSetting = $this->fromLegacyGrid($setting, $gridKey);

            if ($fromSetting !== null) {
                $retained = $fromSetting;
                $origin = self::REPRISE_NOTICE;
            }
        }

        $summary = sprintf('  %s : ', $partition);

        // ① Le défaut d'instance. Jamais écrasé s'il existe déjà : rejouée, la
        //    migration ne défait pas ce qu'un administrateur a réglé depuis.
        $existing = DB::table('quota_rules')
            ->where('partition', $partition)
            ->where('type', QuotaRule::TYPE_DEFAULT)
            ->first();

        if ($existing !== null) {
            $summary .= sprintf(
                'défaut d\'instance DÉJÀ posé (%d/%d Mo), conservé tel quel',
                (int) $existing->quota_soft_mb,
                (int) $existing->quota_hard_mb,
            );
        } elseif ($retained === null) {
            $summary .= 'aucune valeur à retenir — pas de défaut d\'instance (résolution : illimité)';
        } else {
            $newValues = [
                'quota_soft_mb' => $retained['soft'],
                'quota_hard_mb' => $retained['hard'],
                'origine' => $origin,
            ];

            // ⚠️ LE PLAN CLOUD, PORTÉ PAR LA LIGNE D'AUDIT ELLE-MÊME. Le résumé au
            //    journal se perd ; la ligne d'audit, non.
            if ($cloudGovernanceFlips && $partition === QuotaRule::PARTITION_HOME) {
                $newValues['plan_cloud'] = self::CLOUD_PLANE_NOTICE;
            }

            $id = DB::table('quota_rules')->insertGetId([
                'type' => QuotaRule::TYPE_DEFAULT,
                'target' => null,
                'partition' => $partition,
                'quota_soft_mb' => $retained['soft'],
                'quota_hard_mb' => $retained['hard'],
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit(
                QuotaAuditLog::ACTION_CREATE,
                QuotaRule::TYPE_DEFAULT,
                $partition,
                null,
                $newValues,
                $id,
            );

            $summary .= sprintf(
                'défaut d\'instance %d/%d Mo (%s)',
                $retained['soft'],
                $retained['hard'],
                $origin,
            );
        }

        // ② Ce qui est abandonné, tracé une ligne par règle, puis retiré.
        $abandoned = [];
        $fondus = [];

        foreach ($legacy as $row) {
            $this->audit(
                QuotaAuditLog::ACTION_DELETE,
                (string) $row->type,
                $partition,
                [
                    'type' => (string) $row->type,
                    'quota_soft_mb' => (int) $row->quota_soft_mb,
                    'quota_hard_mb' => (int) $row->quota_hard_mb,
                    'is_active' => (bool) $row->is_active,
                ],
                [
                    'remplacé_par' => QuotaRule::TYPE_DEFAULT,
                    'reconstitution' => 'un budget particulier se repose en règle de groupe',
                ],
                (int) $row->id,
            );

            $abandoned[] = sprintf(
                '%s (%d/%d Mo%s)',
                (string) $row->type,
                (int) $row->quota_soft_mb,
                (int) $row->quota_hard_mb,
                (bool) $row->is_active ? '' : ', désactivée',
            );

            if ((bool) $row->is_active === true) {
                $fondus[] = sprintf('%d/%d Mo', (int) $row->quota_soft_mb, (int) $row->quota_hard_mb);
            }
        }

        if ($legacy->isNotEmpty()) {
            DB::table('quota_rules')
                ->whereIn('id', $legacy->pluck('id')->all())
                ->delete();
        }

        $summary .= $abandoned === []
            ? ' ; aucune règle abandonnée'
            : ' ; REGROUPÉES : '.implode(', ', $abandoned)
                .' — un budget particulier se repose en règle de groupe';

        $notice = null;

        if ($retained !== null && count($fondus) > 1) {
            $notice = [
                'fondus' => array_values($fondus),
                'retenu' => sprintf('%d/%d Mo', $retained['soft'], $retained['hard']),
                'origine' => (string) $origin,
            ];
        }

        return [$summary, $notice];
    }

    /**
     * **LA PLUS LARGE**, et l'illimité (`0`) est la plus large de toutes.
     *
     * Prendre le maximum n'alloue rien : un quota PLAFONNE. Prendre le minimum, en
     * revanche, bloquerait en écriture des comptes qui ne l'étaient pas — sur le
     * système de fichiers ET sur le cloud — sans que personne n'ait cliqué.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $rules
     * @return array{soft:int, hard:int}|null
     */
    private function widestRule($rules): ?array
    {
        if ($rules->isEmpty()) {
            return null;
        }

        foreach ($rules as $row) {
            if ((int) $row->quota_hard_mb === 0) {
                return ['soft' => 0, 'hard' => 0];
            }
        }

        $widest = null;

        foreach ($rules as $row) {
            if ($widest === null || (int) $row->quota_hard_mb > $widest['hard']) {
                $widest = ['soft' => (int) $row->quota_soft_mb, 'hard' => (int) $row->quota_hard_mb];
            }
        }

        return $widest;
    }

    /**
     * La grille historique, lue une DERNIÈRE fois. Selon le moteur, la colonne
     * remonte en chaîne ou déjà décodée : on ne suppose ni l'un ni l'autre.
     *
     * @return array<string, mixed>
     */
    private function legacyGrid(): array
    {
        if (! Schema::hasTable('system_settings')) {
            return [];
        }

        $raw = DB::table('system_settings')
            ->where('key', self::LEGACY_SETTING_KEY)
            ->value('value');

        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        return is_array($raw) ? $raw : [];
    }

    /**
     * **LA PLUS LARGE cellule de la grille** pour cette partition, converties en
     * couple souple/dur — même règle que pour les règles : on n'y rétrécit rien.
     *
     * ⚠️ Ici, `0` signifie « non saisi », pas « illimité » : la grille était un
     * formulaire, et un champ laissé vide n'exprime aucune intention. Une grille
     * entièrement à zéro ne fabrique donc AUCUN plafond.
     *
     * @param  array<string, mixed>  $setting
     * @return array{soft:int, hard:int}|null
     */
    private function fromLegacyGrid(array $setting, string $gridKey): ?array
    {
        $widest = null;

        foreach ($setting as $cells) {
            if (! is_array($cells)) {
                continue;
            }

            $cell = $cells[$gridKey] ?? null;

            if (! is_array($cell)) {
                continue;
            }

            $soft = (int) ($cell['soft_mb'] ?? 0);

            if ($soft <= 0) {
                continue;
            }

            $overage = max(0, min(100, (int) ($cell['overage_percent'] ?? 20)));
            $hard = (int) round($soft * (1 + $overage / 100));

            if ($widest === null || $hard > $widest['hard']) {
                $widest = ['soft' => $soft, 'hard' => $hard];
            }
        }

        return $widest;
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    private function audit(
        string $action,
        string $targetType,
        string $partition,
        ?array $old,
        ?array $new,
        ?int $ruleId,
    ): void {
        if (! Schema::hasTable('quota_audit_logs')) {
            return;
        }

        DB::table('quota_audit_logs')->insert([
            'quota_rule_id' => $action === QuotaAuditLog::ACTION_DELETE ? null : $ruleId,
            'action' => $action,
            'performed_by' => 'migration:63.4',
            'target_type' => $targetType,
            'target_name' => null,
            'partition' => $partition,
            'old_values' => $old === null ? null : json_encode($old, JSON_UNESCAPED_UNICODE),
            'new_values' => $new === null ? null : json_encode($new, JSON_UNESCAPED_UNICODE),
            'fs_applied' => false,
            'fs_error' => null,
            'created_at' => now(),
        ]);
    }

    /** Journal gardé : cette migration doit rester traversable sans application bootée. */
    private function report(string $message): void
    {
        try {
            Log::info($message);
        } catch (\Throwable) {
            // Pas de journal disponible : la migration reste la bonne réponse.
        }
    }
};
