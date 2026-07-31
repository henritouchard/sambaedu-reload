<?php

/**
 * Story 57.3 — Les enregistrements du professeur.
 *
 * ⚠️ Le nom affiché est celui du SALON, résolu depuis la table par son jeton —
 * jamais le `meetingName` rapporté par BigBlueButton. Le serveur distant décrit
 * ce qu'il a enregistré ; c'est SambaEdu qui sait comment le professeur a
 * appelé son salon, et qui l'a créé.
 *
 * ⚠️ La lecture se fait CHEZ BigBlueButton : le lien pointe l'URL de playback
 * qu'il a rendue, ouverte dans un nouvel onglet. L'extension ne proxifie rien,
 * ne rediffuse rien, ne stocke rien.
 *
 * @var list<\SambaEdu\ExtBbb\Bbb\RecordingItem> $items
 * @var array<string, string> $names  jeton public du salon => nom du salon
 * @var list<string> $errors
 * @var list<array{type: string, message: string}> $flash
 * @var string $csrf
 */

$deleteUrl = bbb_url('/recordings/delete');

$startedAt = static function (int $timestamp): string {
    return $timestamp <= 0 ? 'date inconnue' : date('d/m/Y à H:i', $timestamp);
};

$duration = static function (int $minutes): string {
    if ($minutes <= 0) {
        return '—';
    }

    return $minutes < 60
        ? $minutes . ' min'
        : intdiv($minutes, 60) . ' h ' . str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT);
};
?>
<h1>Enregistrements</h1>
<p class="lead">
    Les séances enregistrées de vos salons. Elles sont conservées par le serveur de
    visioconférence : les supprimer ici les supprime là-bas, définitivement.
</p>

<?php foreach ($flash as $entry): ?>
    <div class="alert alert--<?= bbb_e($entry['type']) ?>"><?= bbb_e($entry['message']) ?></div>
<?php endforeach; ?>

<?php foreach ($errors as $error): ?>
    <div class="alert alert--error"><?= bbb_e($error) ?></div>
<?php endforeach; ?>

<div class="card">
    <?php if ($items === []): ?>
        <p class="meta" style="margin-bottom:0">
            Aucun enregistrement pour le moment. Un enregistrement apparaît ici une fois que le
            serveur de visioconférence a fini de le préparer, ce qui peut prendre un moment après
            la fin de la séance.
        </p>
    <?php else: ?>
        <div class="table-scroll">
            <table>
                <thead>
                <tr>
                    <th scope="col">Salon</th>
                    <th scope="col">Séance du</th>
                    <th scope="col">Durée</th>
                    <th scope="col">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?= bbb_e($names[$item->meetingId] ?? '') ?></td>
                        <td class="meta"><?= bbb_e($startedAt($item->startedAt())) ?></td>
                        <td class="meta"><?= bbb_e($duration($item->lengthMinutes)) ?></td>
                        <td>
                            <div class="actions">
                                <a class="btn btn--small btn--primary" target="_blank" rel="noopener"
                                   href="<?= bbb_e($item->playbackUrl) ?>">Lire</a>

                                <form method="post" action="<?= bbb_e($deleteUrl) ?>"
                                      onsubmit="return confirm('Supprimer définitivement cet enregistrement ? Il sera effacé du serveur de visioconférence.');">
                                    <input type="hidden" name="_token" value="<?= bbb_e($csrf) ?>">
                                    <input type="hidden" name="token" value="<?= bbb_e($item->meetingId) ?>">
                                    <input type="hidden" name="record" value="<?= bbb_e($item->recordId) ?>">
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

<div class="actions">
    <a class="btn" href="<?= bbb_e(bbb_url('/rooms')) ?>">← Retour aux salons</a>
</div>
