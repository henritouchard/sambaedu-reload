<?php

/**
 * Story 57.1 — Serveurs BigBlueButton de l'établissement.
 *
 * Convention UX du projet reproduite ici : **libellé AU-DESSUS du champ**,
 * étoile rouge sur l'obligatoire, aide seulement quand elle apprend quelque
 * chose.
 *
 * ⚠️ Le secret n'est JAMAIS rendu dans cette page : ni en `value`, ni en
 * attribut, ni en commentaire. Seul un masque avec les quatre derniers
 * caractères permet à l'administrateur de reconnaître ce qu'il a saisi.
 *
 * @var list<array<string, mixed>> $servers
 * @var array<string, mixed>|null $editing
 * @var array<string, string> $errors
 * @var array<string, mixed> $old
 * @var list<array{type: string, message: string}> $flash
 * @var string $csrf
 */

use SambaEdu\ExtBbb\Admin\ServersController;

$formUrl = bbb_url('/admin/servers');
$isEditing = $editing !== null;

$value = static function (string $key, mixed $default = '') use ($old, $editing): mixed {
    if (array_key_exists($key, $old)) {
        return $old[$key];
    }

    if ($editing !== null && array_key_exists($key, $editing)) {
        return $editing[$key];
    }

    return $default;
};

$scaleliteChecked = array_key_exists('scalelite', $old)
    ? (bool) $old['scalelite']
    : ($isEditing && (int) ($editing['scalelite_threshold'] ?? 0) > 0);

$thresholdValue = array_key_exists('scalelite_threshold', $old)
    ? (string) $old['scalelite_threshold']
    : (string) ($isEditing && (int) ($editing['scalelite_threshold'] ?? 0) > 0 ? $editing['scalelite_threshold'] : '');
?>
<h1>Serveurs BigBlueButton</h1>
<p class="lead">
    Déclarez ici les serveurs de l'établissement. Le test de connexion émet un appel signé
    (<span class="mono">getMeetings</span>) : il éprouve l'adresse <em>et</em> le secret partagé.
</p>

<?php foreach ($flash as $entry): ?>
    <div class="alert alert--<?= bbb_e($entry['type']) ?>"><?= bbb_e($entry['message']) ?></div>
<?php endforeach; ?>

<div class="card">
    <h2><?= $isEditing ? 'Modifier le serveur' : 'Ajouter un serveur' ?></h2>

    <form method="post" action="<?= bbb_e($formUrl) ?>">
        <input type="hidden" name="_token" value="<?= bbb_e($csrf) ?>">
        <input type="hidden" name="action" value="<?= $isEditing ? 'update' : 'create' ?>">
        <?php if ($isEditing): ?>
            <input type="hidden" name="id" value="<?= bbb_e((string) $editing['id']) ?>">
        <?php endif; ?>

        <div class="field">
            <label for="base_url">URL du serveur<span class="required" aria-hidden="true">*</span></label>
            <input type="text" id="base_url" name="base_url" required
                   value="<?= bbb_e((string) $value('base_url')) ?>"
                   placeholder="https://bbb.example.net/bigbluebutton/api">
            <span class="hint">Adresse de l'API, sans paramètre. Le schéma <span class="mono">http</span> est accepté mais déconseillé.</span>
            <?php if (isset($errors['base_url'])): ?>
                <span class="hint" style="color:var(--color-error)"><?= bbb_e($errors['base_url']) ?></span>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="secret">Secret partagé<?php if (! $isEditing): ?><span class="required" aria-hidden="true">*</span><?php endif; ?></label>
            <input type="password" id="secret" name="secret" autocomplete="new-password"
                   <?= $isEditing ? '' : 'required' ?>>
            <span class="hint">
                <?= $isEditing
                    ? 'Laissez vide pour conserver le secret actuel.'
                    : 'Valeur de bbb_secret sur le serveur BigBlueButton. Elle n\'est jamais réaffichée.' ?>
            </span>
            <?php if (isset($errors['secret'])): ?>
                <span class="hint" style="color:var(--color-error)"><?= bbb_e($errors['secret']) ?></span>
            <?php endif; ?>
        </div>

        <div class="checkline">
            <input type="checkbox" id="scalelite" name="scalelite" value="1" <?= $scaleliteChecked ? 'checked' : '' ?>>
            <label for="scalelite">Ce serveur est un Scalelite</label>
        </div>

        <div class="field">
            <label for="scalelite_threshold">Seuil de charge Scalelite</label>
            <input type="number" id="scalelite_threshold" name="scalelite_threshold" min="1" step="1"
                   value="<?= bbb_e($thresholdValue) ?>">
            <span class="hint">
                Valeur FIXE, jamais mesurée : un Scalelite répartit lui-même la charge, SambaEdu ne le sonde pas.
                Sans Scalelite, la charge est mesurée par appel API.
            </span>
            <?php if (isset($errors['scalelite_threshold'])): ?>
                <span class="hint" style="color:var(--color-error)"><?= bbb_e($errors['scalelite_threshold']) ?></span>
            <?php endif; ?>
        </div>

        <?php if ($isEditing): ?>
            <div class="checkline">
                <input type="checkbox" id="enabled" name="enabled" value="1"
                       <?= (bool) ($editing['enabled'] ?? true) ? 'checked' : '' ?>>
                <label for="enabled">Serveur actif</label>
            </div>
        <?php endif; ?>

        <div class="actions">
            <button type="submit" class="btn btn--primary"><?= $isEditing ? 'Enregistrer' : 'Ajouter' ?></button>
            <?php if ($isEditing): ?>
                <a class="btn" href="<?= bbb_e($formUrl) ?>">Annuler</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="card">
    <h2>Serveurs déclarés</h2>

    <?php if ($servers === []): ?>
        <p class="meta" style="margin-bottom:0">Aucun serveur déclaré pour le moment.</p>
    <?php else: ?>
        <div class="table-scroll">
            <table>
                <thead>
                <tr>
                    <th scope="col">Adresse</th>
                    <th scope="col">Secret</th>
                    <th scope="col">Type</th>
                    <th scope="col">État</th>
                    <th scope="col">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($servers as $server): ?>
                    <tr>
                        <td class="mono"><?= bbb_e((string) $server['base_url']) ?></td>
                        <td class="mono"><?= bbb_e(ServersController::maskSecret((string) $server['secret'])) ?></td>
                        <td>
                            <?php if ((int) $server['scalelite_threshold'] > 0): ?>
                                Scalelite · seuil <?= bbb_e((string) $server['scalelite_threshold']) ?>
                            <?php else: ?>
                                BigBlueButton
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?= $server['enabled'] ? 'badge--on' : 'badge--off' ?>">
                                <?= $server['enabled'] ? 'actif' : 'inactif' ?>
                            </span>
                        </td>
                        <td>
                            <div class="actions">
                                <form method="post" action="<?= bbb_e($formUrl) ?>">
                                    <input type="hidden" name="_token" value="<?= bbb_e($csrf) ?>">
                                    <input type="hidden" name="action" value="test">
                                    <input type="hidden" name="id" value="<?= bbb_e((string) $server['id']) ?>">
                                    <button type="submit" class="btn btn--small">Tester</button>
                                </form>

                                <a class="btn btn--small"
                                   href="<?= bbb_e($formUrl . '?edit=' . (int) $server['id']) ?>">Modifier</a>

                                <form method="post" action="<?= bbb_e($formUrl) ?>">
                                    <input type="hidden" name="_token" value="<?= bbb_e($csrf) ?>">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= bbb_e((string) $server['id']) ?>">
                                    <button type="submit" class="btn btn--small">
                                        <?= $server['enabled'] ? 'Désactiver' : 'Activer' ?>
                                    </button>
                                </form>

                                <form method="post" action="<?= bbb_e($formUrl) ?>"
                                      onsubmit="return confirm('Supprimer définitivement ce serveur ?');">
                                    <input type="hidden" name="_token" value="<?= bbb_e($csrf) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= bbb_e((string) $server['id']) ?>">
                                    <button type="submit" class="btn btn--small btn--danger">Supprimer</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
