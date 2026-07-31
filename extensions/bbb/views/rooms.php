<?php

/**
 * Story 57.2 — Les salons de visioconférence.
 *
 * ⚠️ **Aucun mot de passe de salon ne peut apparaître ici** : l'objet
 * {@see \SambaEdu\ExtBbb\Rooms\Room} passé à cette vue ne les porte pas. Ce
 * n'est pas une discipline de rédaction, c'est une propriété du type. Le seul
 * identifiant rendu est le jeton PUBLIC, et il ne sert qu'à désigner un salon
 * dans une action ; le serveur re-décide de tout à la réception.
 *
 * ⚠️ Toutes les actions sont des POST : un lien GET qui ouvrirait un meeting
 * serait déclenché par le simple préchargement d'un navigateur.
 *
 * Convention UX du projet : libellé AU-DESSUS du champ, étoile sur
 * l'obligatoire, aide seulement quand elle apprend quelque chose.
 *
 * Story 57.3 — La zone d'invitation externe s'affiche pour les salons du
 * créateur, et pour eux seuls. Le lien public et le mot de passe d'invitation y
 * apparaissent en clair : c'est leur RAISON D'ÊTRE, le professeur doit pouvoir
 * les recopier ou les dicter. Ils n'existent nulle part ailleurs dans la page,
 * et surtout pas dans la liste « Salons accessibles » — le contrôleur ne les a
 * lus que pour `$mine`.
 *
 * @var list<\SambaEdu\ExtBbb\Rooms\Room> $mine
 * @var list<\SambaEdu\ExtBbb\Rooms\Room> $others
 * @var array<string, array{password: string, url: string}> $invitations
 * @var bool $canCreate
 * @var bool $canSeeRecordings
 * @var list<string> $groups
 * @var array<string, string> $errors
 * @var array<string, mixed> $old
 * @var list<array{type: string, message: string}> $flash
 * @var string $csrf
 */

use SambaEdu\ExtBbb\Rooms\Room;

$roomsUrl = bbb_url('/rooms');
$startUrl = bbb_url('/rooms/start');
$joinUrl = bbb_url('/rooms/join');
$deleteUrl = bbb_url('/rooms/delete');
$guestEnableUrl = bbb_url('/rooms/guest/enable');
$guestRevokeUrl = bbb_url('/rooms/guest/revoke');

$oldName = isset($old['name']) && is_string($old['name']) ? $old['name'] : '';
$oldVisibility = isset($old['visibility']) && is_string($old['visibility'])
    ? $old['visibility']
    : Room::VISIBILITY_ETAB;
$oldGroups = isset($old['groups']) && is_array($old['groups']) ? $old['groups'] : [];

$visibilityChoices = [
    Room::VISIBILITY_ETAB => ['Tout l\'établissement', 'Toute personne connectée à SambaEdu peut le rejoindre.'],
    Room::VISIBILITY_CLASSE => ['Une ou plusieurs de mes classes', 'Seuls leurs membres le voient.'],
    Room::VISIBILITY_PRIVATE => ['Privé', 'Vous seul le voyez et le rejoignez.'],
];

// La liste « classes » disparaît de l'écran quand les claims n'en portent
// aucune. La garde SERVEUR, elle, ne disparaît jamais : masquer une option
// n'est pas la refuser.
if ($groups === []) {
    unset($visibilityChoices[Room::VISIBILITY_CLASSE]);
}

$openedAt = static function (?string $iso): string {
    if ($iso === null || $iso === '') {
        return 'jamais ouvert';
    }

    $timestamp = strtotime($iso);

    return $timestamp === false ? $iso : date('d/m/Y à H:i', $timestamp);
};
?>
<h1>Salons</h1>
<p class="lead">
    Un salon est durable : vous le créez une fois, puis vous l'ouvrez à chaque séance.
    Vos élèves ne le voient que lorsqu'ils y ont droit, et n'ont jamais de mot de passe à saisir.
</p>

<?php if ($canSeeRecordings): ?>
    <div class="actions">
        <a class="btn" href="<?= bbb_e(bbb_url('/recordings')) ?>">Enregistrements</a>
    </div>
<?php endif; ?>

<?php foreach ($flash as $entry): ?>
    <div class="alert alert--<?= bbb_e($entry['type']) ?>"><?= bbb_e($entry['message']) ?></div>
<?php endforeach; ?>

<?php if ($canCreate): ?>
    <div class="card">
        <h2>Créer un salon</h2>

        <form method="post" action="<?= bbb_e($roomsUrl) ?>">
            <input type="hidden" name="_token" value="<?= bbb_e($csrf) ?>">

            <div class="field">
                <label for="name">Nom du salon<span class="required" aria-hidden="true">*</span></label>
                <input type="text" id="name" name="name" required maxlength="100"
                       value="<?= bbb_e($oldName) ?>" placeholder="Cours de mathématiques">
                <?php if (isset($errors['name'])): ?>
                    <span class="hint" style="color:var(--color-error)"><?= bbb_e($errors['name']) ?></span>
                <?php endif; ?>
            </div>

            <div class="field">
                <label>Qui peut voir ce salon<span class="required" aria-hidden="true">*</span></label>
                <?php foreach ($visibilityChoices as $value => [$label, $help]): ?>
                    <div class="checkline">
                        <input type="radio" id="visibility-<?= bbb_e($value) ?>" name="visibility"
                               value="<?= bbb_e($value) ?>" <?= $oldVisibility === $value ? 'checked' : '' ?>>
                        <label for="visibility-<?= bbb_e($value) ?>">
                            <?= bbb_e($label) ?> <span class="meta"><?= bbb_e($help) ?></span>
                        </label>
                    </div>
                <?php endforeach; ?>
                <?php if (isset($errors['visibility'])): ?>
                    <span class="hint" style="color:var(--color-error)"><?= bbb_e($errors['visibility']) ?></span>
                <?php endif; ?>
            </div>

            <?php if ($groups !== []): ?>
                <div class="field">
                    <label>Mes classes et équipes</label>
                    <?php foreach ($groups as $index => $group): ?>
                        <div class="checkline">
                            <input type="checkbox" id="group-<?= bbb_e((string) $index) ?>" name="groups[]"
                                   value="<?= bbb_e($group) ?>"
                                   <?= in_array($group, $oldGroups, true) ? 'checked' : '' ?>>
                            <label for="group-<?= bbb_e((string) $index) ?>"><?= bbb_e($group) ?></label>
                        </div>
                    <?php endforeach; ?>
                    <span class="hint">
                        À cocher uniquement pour la visibilité « une ou plusieurs de mes classes ».
                    </span>
                    <?php if (isset($errors['groups'])): ?>
                        <span class="hint" style="color:var(--color-error)"><?= bbb_e($errors['groups']) ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="actions">
                <button type="submit" class="btn btn--primary">Créer le salon</button>
            </div>
        </form>
    </div>
<?php endif; ?>

<div class="card">
    <h2>Mes salons</h2>

    <?php if ($mine === []): ?>
        <p class="meta" style="margin-bottom:0">
            <?= $canCreate
                ? 'Vous n\'avez pas encore créé de salon.'
                : 'Vous n\'avez aucun salon : seuls les professeurs peuvent en créer.' ?>
        </p>
    <?php else: ?>
        <div class="table-scroll">
            <table>
                <thead>
                <tr>
                    <th scope="col">Salon</th>
                    <th scope="col">Visible par</th>
                    <th scope="col">Dernière ouverture</th>
                    <th scope="col">Invitation externe</th>
                    <th scope="col">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($mine as $room): ?>
                    <?php $invitation = $invitations[$room->token] ?? null; ?>
                    <tr>
                        <td><?= bbb_e($room->name) ?></td>
                        <td><?= bbb_e($room->visibilityLabel()) ?></td>
                        <td class="meta"><?= bbb_e($openedAt($room->lastStartedAt)) ?></td>
                        <td>
                            <?php if ($invitation === null): ?>
                                <p class="meta" style="margin-top:0">Aucune invitation active.</p>
                                <form method="post" action="<?= bbb_e($guestEnableUrl) ?>">
                                    <input type="hidden" name="_token" value="<?= bbb_e($csrf) ?>">
                                    <input type="hidden" name="token" value="<?= bbb_e($room->token) ?>">
                                    <button type="submit" class="btn btn--small">Activer l'invitation</button>
                                </form>
                            <?php else: ?>
                                <p class="meta" style="margin-top:0">
                                    Lien à transmettre :<br>
                                    <code style="word-break:break-all"><?= bbb_e($invitation['url']) ?></code>
                                </p>
                                <p class="meta">
                                    Mot de passe : <strong><code><?= bbb_e($invitation['password']) ?></code></strong>
                                </p>
                                <div class="actions">
                                    <form method="post" action="<?= bbb_e($guestEnableUrl) ?>"
                                          onsubmit="return confirm('Régénérer le lien et le mot de passe ? L\'ancien lien cessera immédiatement de fonctionner.');">
                                        <input type="hidden" name="_token" value="<?= bbb_e($csrf) ?>">
                                        <input type="hidden" name="token" value="<?= bbb_e($room->token) ?>">
                                        <button type="submit" class="btn btn--small">Régénérer</button>
                                    </form>

                                    <form method="post" action="<?= bbb_e($guestRevokeUrl) ?>"
                                          onsubmit="return confirm('Révoquer l\'invitation ? Le lien public cessera immédiatement de fonctionner.');">
                                        <input type="hidden" name="_token" value="<?= bbb_e($csrf) ?>">
                                        <input type="hidden" name="token" value="<?= bbb_e($room->token) ?>">
                                        <button type="submit" class="btn btn--small btn--danger">Révoquer</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="actions">
                                <form method="post" action="<?= bbb_e($startUrl) ?>">
                                    <input type="hidden" name="_token" value="<?= bbb_e($csrf) ?>">
                                    <input type="hidden" name="token" value="<?= bbb_e($room->token) ?>">
                                    <button type="submit" class="btn btn--small btn--primary">Démarrer ou entrer</button>
                                </form>

                                <form method="post" action="<?= bbb_e($deleteUrl) ?>"
                                      onsubmit="return confirm('Supprimer définitivement ce salon ? Son invitation externe cessera de fonctionner, et ses enregistrements ne seront plus accessibles depuis SambaEdu.');">
                                    <input type="hidden" name="_token" value="<?= bbb_e($csrf) ?>">
                                    <input type="hidden" name="token" value="<?= bbb_e($room->token) ?>">
                                    <button type="submit" class="btn btn--small btn--danger">Supprimer</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="meta">
            « Dernière ouverture » est un repère daté, pas un état : un salon se referme tout seul
            au bout de quatre heures, ou lorsque le dernier participant s'en va.
        </p>
        <p class="meta" style="margin-bottom:0">
            L'invitation externe permet à des personnes sans compte SambaEdu — parents, intervenants —
            de rejoindre la séance en participant. Transmettez-leur le lien <em>et</em> le mot de passe.
            Vous pouvez les régénérer ou les révoquer à tout moment : l'ancien lien meurt aussitôt.
        </p>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Salons accessibles</h2>

    <?php if ($others === []): ?>
        <p class="meta" style="margin-bottom:0">Aucun salon ne vous est ouvert pour le moment.</p>
    <?php else: ?>
        <div class="table-scroll">
            <table>
                <thead>
                <tr>
                    <th scope="col">Salon</th>
                    <th scope="col">Ouvert par</th>
                    <th scope="col">Visible par</th>
                    <th scope="col">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($others as $room): ?>
                    <tr>
                        <td><?= bbb_e($room->name) ?></td>
                        <td><?= bbb_e($room->ownerName) ?></td>
                        <td><?= bbb_e($room->visibilityLabel()) ?></td>
                        <td>
                            <form method="post" action="<?= bbb_e($joinUrl) ?>">
                                <input type="hidden" name="_token" value="<?= bbb_e($csrf) ?>">
                                <input type="hidden" name="token" value="<?= bbb_e($room->token) ?>">
                                <button type="submit" class="btn btn--small btn--primary">Rejoindre</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
