# =============================================================================
# SessionStateFetch.ps1 — Point d'entree de la tache planifiee
# `SambaEduAgent-SessionFetch` (Story 24.3, Epic 24)
# =============================================================================
# Compte SYSTEM, declencheur « At log on » (any user) — enregistree par
# Install-SambaEduAgent.ps1. Mince par construction : tout le code (HTTP,
# rotation D5, enumeration CIM, cache per-user) vit dans SambaEduAgent.ps1,
# dot-source ci-dessous (un seul code pour le logon ET le cycle du service —
# decision n° 4 de la story).
#
# Ce que fait UNE execution :
#   1. enumere les sessions interactives (CIM — l'identite est resolue ici,
#      cote SYSTEM, jamais declaree par le user : anti-usurpation) ;
#   2. pour chaque session : GET /state?user=<login court> avec
#      l'If-None-Match DU contexte (poste, user) ;
#   3. 200 -> cache per-user cache\sessions\<SID>\{state.json,etag.txt}
#      (ACL : SYSTEM F, Administrators F, <SID> lecture seule) ;
#   4. sort. Le processus user (SessionCompanion.ps1) consomme le cache.
#
# NFR1 : cette tache s'execute EN PARALLELE de l'ouverture de session (tache
# planifiee asynchrone) — rien ici n'est dans le chemin synchrone du logon.
# Serveur injoignable -> log + sortie : la session vit sur son dernier cache,
# le rattrapage est le cycle du service (jamais de retry agressif).
# =============================================================================

#Requires -Version 5.1
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

# Dot-source de l'agent : definit les fonctions SANS demarrer la boucle
# (le point d'entree de SambaEduAgent.ps1 est garde par InvocationName).
. (Join-Path $PSScriptRoot 'SambaEduAgent.ps1')

try {
    $config = Read-AgentConfig
    Invoke-SessionStateFetch -Config $config
} catch {
    # Jamais d'erreur visible au logon : log local et sortie. Token absent
    # (poste non enrole) ou config manquante -> meme traitement.
    try {
        Write-AgentLog -Level ERROR -Message "SessionStateFetch en echec : $($_.Exception.Message)"
    } catch {
        # Meme le log a echoue : sortie silencieuse (rien ne doit bloquer).
    }
    exit 1
}
