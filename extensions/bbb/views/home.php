<?php

/**
 * Story 57.1 — La racine `/`, qui est aussi **LA SONDE DE SANTÉ**.
 *
 * `ExtensionHealthService` frappe `GET http://127.0.0.1:<port>/` toutes les 5
 * minutes (connexion 2 s, total 3 s) et considère joignable TOUTE réponse HTTP,
 * 4xx et 5xx comprises. Aucun endpoint `/health` n'existe ni n'est requis.
 *
 * Corollaire tenu ici : cette page ne fait AUCUN appel BBB, AUCUN appel OIDC
 * bloquant, aucun travail long. Elle n'ouvre même pas la base — un fichier
 * SQLite corrompu doit rendre une page, pas une connexion pendue.
 *
 * @var \SambaEdu\ExtBbb\Identity|null $identity
 * @var bool $provisioned
 */
?>
<h1>Visioconférences</h1>

<?php if ($identity === null): ?>

    <p class="lead">
        Les visioconférences de l'établissement, adossées à vos serveurs BigBlueButton.
        Connectez-vous avec votre compte SambaEdu.
    </p>

    <div class="card">
        <?php if ($provisioned): ?>
            <p style="margin-top:0">Aucune session ouverte sur cette extension.</p>
            <a class="btn btn--primary" href="<?= bbb_e(bbb_url('/login')) ?>">Se connecter</a>
        <?php else: ?>
            <p style="margin-top:0">
                Cette extension n'a pas encore reçu ses identifiants de connexion. Elle doit être
                installée depuis la bibliothèque d'extensions de SambaEdu, qui lui transmet son
                environnement.
            </p>
            <p class="meta" style="margin-bottom:0">Le service répond : c'est ce que vérifie la sonde de santé.</p>
        <?php endif; ?>
    </div>

<?php else: ?>

    <p class="lead">Bonjour <?= bbb_e($identity->name) ?>.</p>

    <div class="card">
        <h2>Votre profil</h2>
        <p class="meta" style="margin-top:0">
            Identifiant <span class="mono"><?= bbb_e($identity->sub) ?></span> · rôle
            <strong><?= bbb_e($identity->role) ?></strong>
        </p>

        <?php if ($identity->groups !== []): ?>
            <p class="meta" style="margin-bottom:0.2rem">Classes et équipes</p>
            <ul class="chips">
                <?php foreach ($identity->groups as $group): ?>
                    <li class="chip"><?= bbb_e($group) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="meta" style="margin-bottom:0">Aucune classe ni équipe transmise.</p>
        <?php endif; ?>
    </div>

    <?php if ($identity->isAdmin()): ?>
        <div class="card">
            <h2>Administration</h2>
            <p style="margin-top:0">Déclarez ici les serveurs BigBlueButton de l'établissement.</p>
            <a class="btn btn--primary" href="<?= bbb_e(bbb_url('/admin/servers')) ?>">Serveurs BBB</a>
        </div>
    <?php endif; ?>

    <div class="card card--muted">
        <h2>Salons</h2>
        <p class="meta" style="margin:0">
            La préparation et la tenue des salons arrivent dans une prochaine version de l'extension.
        </p>
    </div>

<?php endif; ?>
