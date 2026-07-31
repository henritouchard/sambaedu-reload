<?php

/**
 * Story 57.3 — « La visioconférence n'est pas encore ouverte », côté invité.
 *
 * On n'arrive ici qu'APRÈS avoir donné le bon mot de passe : afficher le nom du
 * salon et celui de son organisateur n'apprend donc rien à un inconnu.
 *
 * ⚠️ **Aucun rafraîchissement automatique.** Le legacy servait cette page avec
 * un en-tête `Refresh: 15`, ce qui transformait chaque invité laissé sur un
 * onglet en générateur d'appels sortants toutes les quinze secondes — sur un
 * serveur HTTP intégré mono-processus, c'est un déni de service qu'on
 * s'inflige soi-même. Ici, c'est le visiteur qui décide de réessayer.
 *
 * @var \SambaEdu\ExtBbb\Rooms\Room $room
 * @var string $token
 * @var string $message
 */
?>
<h1>La visioconférence n'est pas encore ouverte</h1>

<div class="card">
    <p style="margin-top:0">
        <?php if ($message !== ''): ?>
            <?= bbb_e($message) ?>
        <?php else: ?>
            <strong><?= bbb_e($room->name) ?></strong> n'accueille personne pour le moment.
            Attendez que <?= bbb_e($room->ownerName) ?> ouvre la séance, puis réessayez.
        <?php endif; ?>
    </p>
    <div class="actions">
        <a class="btn btn--primary" href="<?= bbb_e(bbb_url('/visio?g=' . rawurlencode($token))) ?>">Réessayer</a>
    </div>
</div>
