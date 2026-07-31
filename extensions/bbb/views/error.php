<?php

/**
 * Story 57.1 — La page d'erreur SOBRE, unique.
 *
 * Elle nomme le **code interne** du refus, jamais son détail. C'est le
 * compromis que le témoin SSO du core avait explicité et assumé : une extension
 * n'est pas un fournisseur d'identité, elle est un outil d'intégration, et un
 * administrateur qui n'a pas accès au journal du service a besoin d'un point
 * d'accroche. Ce qui reste FUSIONNÉ — et c'est la seule règle qui compte — est
 * le bucket de signature : `alg: none`, confusion d'algorithme, clé étrangère et
 * `kid` inconnu rendent tous le même code.
 *
 * Jamais de trace d'exécution, jamais de chemin de fichier, jamais de valeur de
 * jeton, jamais de secret.
 *
 * @var string $code
 * @var string $message
 * @var bool $canRetry
 */
?>
<h1><?= bbb_e($message) ?></h1>

<div class="card">
    <p style="margin-top:0" class="meta">
        Code de diagnostic : <span class="mono"><?= bbb_e($code) ?></span>
    </p>

    <div class="actions">
        <a class="btn" href="<?= bbb_e(bbb_url('/')) ?>">Accueil de l'extension</a>
        <?php if ($canRetry): ?>
            <a class="btn btn--primary" href="<?= bbb_e(bbb_url('/login')) ?>">Réessayer la connexion</a>
        <?php endif; ?>
    </div>
</div>
