<?php

/**
 * Story 57.3 — **LE refus de la route publique, et il n'en existe qu'un.**
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  CETTE VUE NE REÇOIT AUCUNE VARIABLE, ET C'EST TOUT LE SUJET
 *
 *  Quatre causes mènent ici — jeton inconnu, invitation révoquée, mot de passe
 *  faux, fenêtre de tentatives saturée — et elles doivent rendre la MÊME
 *  réponse, octet pour octet. Le moyen le plus sûr d'y parvenir n'est pas de
 *  rédiger prudemment : c'est de n'avoir rien à rédiger. Aucune donnée de la
 *  requête n'entre ici, donc rien ne peut varier.
 *
 *  Distinguer les causes offrirait un oracle : on saurait, sans mot de passe et
 *  depuis l'extérieur de l'établissement, qu'un salon existe. Y compris pour la
 *  fenêtre saturée — un « trop de tentatives » distinct dirait « ce jeton est
 *  bon », ce qui est exactement l'information qu'on refuse de donner.
 * ══════════════════════════════════════════════════════════════════════════
 */
?>
<h1>Accès refusé</h1>

<div class="card">
    <p style="margin-top:0">Lien ou mot de passe incorrect.</p>
    <p class="meta" style="margin-bottom:0">
        Vérifiez le lien et le mot de passe qui vous ont été communiqués, puis ouvrez à nouveau
        le lien pour réessayer. Si le problème persiste, demandez à la personne qui vous a invité
        de vous en transmettre de nouveaux.
    </p>
</div>
