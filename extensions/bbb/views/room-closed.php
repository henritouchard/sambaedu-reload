<?php

/**
 * Story 57.2 — « Ce salon n'est pas ouvert. »
 *
 * Ce n'est **pas une erreur**, c'est un état normal, et la page le dit avec les
 * mots de la situation : le salon existe, la personne y a droit, il n'a
 * simplement pas encore été démarré — ou il s'est refermé (quatre heures de
 * durée maximale, ou dernier participant parti).
 *
 * Le legacy tenait un miroir en mémoire de ses meetings actifs, avec des durées
 * de vie et un ramasse-miettes, ce qui produisait exactement l'inverse : des
 * salons affichés comme ouverts alors qu'ils ne l'étaient plus. Ici la question
 * est posée au serveur BigBlueButton au moment où elle se pose, et la réponse
 * est celle-ci.
 *
 * @var \SambaEdu\ExtBbb\Rooms\Room $room
 */
?>
<h1>Ce salon n'est pas ouvert</h1>

<div class="card">
    <p style="margin-top:0">
        <strong><?= bbb_e($room->name) ?></strong> n'accueille personne pour le moment.
        Attendez que <?= bbb_e($room->ownerName) ?> le démarre, puis réessayez.
    </p>
    <div class="actions">
        <a class="btn btn--primary" href="<?= bbb_e(bbb_url('/rooms')) ?>">Retour aux salons</a>
    </div>
</div>
