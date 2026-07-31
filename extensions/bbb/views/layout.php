<?php

/**
 * Story 57.1 — Layout AUTONOME de l'extension.
 *
 * Une extension n'a pas la barre de navigation de SE5 : elle est un site à part,
 * hébergé sous `/ext/bbb`, relié au lanceur par un lien de retour explicite
 * (FR16). La seule URL de SE5 qu'elle connaisse est son issuer OIDC — jamais une
 * adresse codée en dur.
 *
 * @var string $title
 * @var string $content
 * @var \SambaEdu\ExtBbb\Env $env
 * @var \SambaEdu\ExtBbb\Identity|null $identity
 */

use SambaEdu\ExtBbb\Url;

$backToSambaEdu = Url::backToSambaEdu($env);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= bbb_e($title) ?> — Visioconférences</title>
    <link rel="stylesheet" href="<?= bbb_e(bbb_url('/assets/app.css')) ?>">
    <script src="<?= bbb_e(bbb_url('/assets/theme.js')) ?>"></script>
</head>
<body>
<header class="topbar">
    <a class="topbar__brand" href="<?= bbb_e(bbb_url('/')) ?>">
        <span class="topbar__mark" aria-hidden="true">◉</span>
        Visioconférences
    </a>
    <span class="topbar__spacer"></span>
    <?php if ($identity !== null): ?>
        <span class="topbar__user"><?= bbb_e($identity->name) ?> · <?= bbb_e($identity->role) ?></span>
        <?php if ($identity->isAdmin()): ?>
            <a class="btn btn--small" href="<?= bbb_e(bbb_url('/admin/servers')) ?>">Serveurs BBB</a>
        <?php endif; ?>
        <a class="btn btn--small" href="<?= bbb_e(bbb_url('/logout')) ?>">Se déconnecter</a>
    <?php endif; ?>
    <button type="button" class="btn btn--small" data-theme-toggle hidden>Thème</button>
</header>

<main>
    <?= $content ?>
</main>

<footer>
    <span>SambaEdu 5 — extension « Visioconférences »</span>
    <span class="topbar__spacer"></span>
    <?php if ($backToSambaEdu !== '/'): ?>
        <a href="<?= bbb_e($backToSambaEdu) ?>">← Retour à SambaEdu</a>
    <?php endif; ?>
</footer>
</body>
</html>
