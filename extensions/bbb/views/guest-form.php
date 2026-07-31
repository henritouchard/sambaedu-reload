<?php

/**
 * Story 57.3 — Le formulaire de l'invité externe. **Page PUBLIQUE.**
 *
 * ⚠️ C'est la seule page de l'extension qu'un anonyme peut atteindre. Elle est
 * rendue à l'identique pour un jeton valide et pour un jeton inventé : la
 * validité d'un lien ne se révèle jamais sans le mot de passe.
 *
 * ⚠️ Le mot de passe demandé ici est celui de L'INVITATION, pas un mot de passe
 * BigBlueButton. Les mots de passe BBB ne quittent jamais le serveur — l'objet
 * qui alimente cette vue ne les a même pas.
 *
 * Convention UX du projet : libellé AU-DESSUS du champ, étoile sur l'obligatoire.
 *
 * @var string $token
 * @var string $name
 * @var array<string, string> $errors
 * @var int $maxNameLength
 */
?>
<h1>Rejoindre une visioconférence</h1>

<div class="card">
    <p style="margin-top:0">
        Vous avez été invité à une visioconférence SambaEdu. Indiquez le nom sous lequel vous
        souhaitez apparaître, puis le mot de passe qui vous a été communiqué avec le lien.
        <strong>Aucun compte n'est nécessaire.</strong>
    </p>

    <form method="post" action="<?= bbb_e(bbb_url('/visio')) ?>">
        <input type="hidden" name="g" value="<?= bbb_e($token) ?>">

        <div class="field">
            <label for="name">Votre nom<span class="required" aria-hidden="true">*</span></label>
            <input type="text" id="name" name="name" required autocomplete="name"
                   maxlength="<?= bbb_e((string) $maxNameLength) ?>" value="<?= bbb_e($name) ?>">
            <span class="hint">Il sera affiché aux participants, suivi de la mention « invité ».</span>
            <?php if (isset($errors['name'])): ?>
                <span class="hint" style="color:var(--color-error)"><?= bbb_e($errors['name']) ?></span>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="password">Mot de passe du salon<span class="required" aria-hidden="true">*</span></label>
            <input type="password" id="password" name="password" required autocomplete="off">
        </div>

        <div class="actions">
            <button type="submit" class="btn btn--primary">Rejoindre la visioconférence</button>
        </div>
    </form>
</div>
