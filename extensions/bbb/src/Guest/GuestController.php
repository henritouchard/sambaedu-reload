<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Guest;

use SambaEdu\ExtBbb\Bbb\BbbApiClient;
use SambaEdu\ExtBbb\Env;
use SambaEdu\ExtBbb\Http\Request;
use SambaEdu\ExtBbb\Http\Response;
use SambaEdu\ExtBbb\Rooms\Room;
use SambaEdu\ExtBbb\Store;
use SambaEdu\ExtBbb\View;

/**
 * Story 57.3 — **LA SEULE SURFACE NON AUTHENTIFIÉE DE TOUTE L'EXTENSION.**
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  CE FICHIER EST LE SEUL ENDROIT OÙ UN ANONYME PARLE AU SERVEUR
 *
 *  Partout ailleurs, l'interlocuteur est nommé : il a traversé le SSO, son
 *  rôle vient de claims signés, et une faute d'autorisation se lit dans un
 *  journal avec un login en face. Ici, non. Tout ce que 57.2 a gagné en
 *  déplaçant la décision côté serveur doit donc tenir dans un contexte plus
 *  hostile, et quatre invariants portent la story :
 *
 *  1. **Aucun mot de passe BigBlueButton n'atteint le navigateur.** L'invité
 *     prouve qu'il connaît le mot de passe D'INVITATION — un secret propre au
 *     salon, distinct des mots de passe BBB. Le serveur fabrique lui-même
 *     l'URL de jonction signée, avec `attendee_pw` et rien d'autre. Il
 *     n'existe AUCUN chemin, dans ce fichier, qui atteigne `moderator_pw`.
 *  2. **La réponse de refus est indistincte.** Jeton inconnu, invitation
 *     révoquée, mot de passe faux, fenêtre d'échecs saturée : même page, même
 *     statut, zéro appel sortant. Sonder l'existence d'un salon depuis
 *     l'extérieur doit être impossible.
 *  3. **Aucun état par visiteur n'est ouvert, ni avant, ni après.** Ce
 *     contrôleur ne reçoit PAS de {@see \SambaEdu\ExtBbb\Http\SessionStore} :
 *     c'est le typage qui le prouve, pas une promesse en commentaire. Le
 *     parcours est sans état de bout en bout — le mot de passe EST la preuve,
 *     la redirection EST le résultat.
 *  4. **La comparaison est en temps constant, et bornée en tentatives.** SE4
 *     n'avait ni l'une ni l'autre : comparaison en clair, aucune limite
 *     (`visio/index.php:27`).
 *
 *  Le contre-modèle est nommé : le `CONF_HASH` legacy était **le login du
 *  créateur, en clair**, dans l'URL publique. Énumérable, un seul salon
 *  invitable par personne, aucune révocation. Ici : un jeton par salon, tiré
 *  de `random_bytes`, sans aucune sémantique, révocable et régénérable.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * **Pas de jeton anti-CSRF sur `POST /visio` — décision instruite, et son
 * risque résiduel NOMMÉ (review 57.3 #4).** Un invité n'a pas d'état serveur où
 * ancrer un jeton, et lui en ouvrir un pour ça violerait l'invariant n°3. La
 * preuve anti-forge est le mot de passe lui-même : un POST fabriqué ailleurs
 * qui ne le porte pas échoue AVANT tout appel sortant (l'ordre des opérations
 * ci-dessous est strict) et ne coûte qu'une lecture SQLite.
 *
 * ⚠️ Ce qui précède ne dit PAS que l'absence de jeton est sans effet. Une
 * première rédaction de ce docblock l'affirmait — « le résultat est une
 * redirection, pas une mutation silencieuse » — et c'était faux : un formulaire
 * tiers auto-soumis, portant un couple jeton+mot de passe VALIDES, fait bel et
 * bien atterrir un visiteur dans la conférence, sous un nom choisi par
 * l'attaquant. « Pas de mutation côté extension » n'est pas « pas d'effet pour
 * la victime ».
 *
 * Le risque résiduel est donc **assumé, pas nié** : il suppose que l'attaquant
 * connaisse déjà un couple valide — auquel cas il a de toute façon l'accès —
 * et se réduit à faire apparaître un tiers non consentant dans une conférence.
 * Nuisance, pas fuite de secret. Un jeton anti-CSRF ne lui retirerait rien
 * qu'il n'ait déjà ; il coûterait, lui, l'état serveur qu'on refuse à l'invité.
 *
 * **Jamais de `sleep()`.** Temporiser un attaquant sur un serveur HTTP intégré
 * mono-processus (décision D2), c'est bloquer tout l'établissement pour le punir.
 * La borne est un compteur, pas une attente.
 */
final class GuestController
{
    /**
     * Un nom d'invité est un nom, pas un texte. Borné pour la même raison que
     * le nom d'un salon : ce qui traverse jusqu'à la conférence doit rester
     * lisible dans la liste des participants.
     */
    public const MAX_NAME_LENGTH = 50;

    /**
     * **Le suffixe n'est pas décoratif.** Un externe ne doit pas pouvoir se
     * présenter dans la conférence sous l'apparence d'un membre de
     * l'établissement : il peut choisir son nom, il ne peut pas choisir de ne
     * pas être annoncé comme invité.
     */
    public const GUEST_SUFFIX = ' (invité)';

    /** Échecs de mot de passe tolérés par fenêtre, POUR UN SALON DONNÉ. */
    public const MAX_FAILURES = 10;

    /** Durée de la fenêtre d'échecs, en secondes (quinze minutes). */
    public const WINDOW_SECONDS = 900;

    /**
     * La valeur FACTICE comparée lorsque le jeton est inconnu.
     *
     * Sans elle, un jeton inconnu ne déclencherait aucune comparaison, et
     * l'absence de ce travail se lirait dans le temps de réponse : l'oracle
     * qu'on refuse de donner reviendrait par la petite porte. Sa forme est
     * celle d'un vrai mot de passe d'invitation (huit caractères du même
     * alphabet) ; sa valeur ne correspond à aucun salon, et n'a pas à être
     * secrète — elle n'est jamais acceptée, puisque le jeton l'est encore moins.
     */
    private const DECOY_PASSWORD = 'ZZZZZZZZ';

    public function __construct(
        private readonly Store $store,
        private readonly BbbApiClient $api,
        private readonly View $view,
        private readonly Env $env,
    ) {
    }

    /**
     * @param  int|null  $now  Point d'injection de TEST uniquement (la fenêtre
     *                         d'échecs se raisonne mal sans horloge maîtrisée).
     */
    public function handle(Request $request, ?int $now = null): Response
    {
        return strtoupper($request->method) === 'POST'
            ? $this->join($request, $now ?? time())
            : $this->form($request->query('g'), '', []);
    }

    // =====================================================================
    // GET /visio — le formulaire, TOUJOURS le même
    // =====================================================================

    /**
     * ⚠️ **Le même formulaire, que le jeton existe ou non.** Répondre « ce lien
     * n'existe pas » dès le GET donnerait gratuitement, et sans même tenter un
     * mot de passe, la réponse à la seule question qu'un attaquant se pose. La
     * validité d'un jeton ne se révèle jamais sans le mot de passe.
     *
     * Zéro appel sortant : la page ne sait rien du serveur de visioconférence,
     * et n'a rien à lui demander.
     *
     * @param  array<string, string>  $errors
     */
    private function form(string $token, string $name, array $errors, int $status = 200): Response
    {
        return Response::html(
            $this->view->page('guest-form', [
                'token' => $token,
                'name' => $name,
                'errors' => $errors,
                'maxNameLength' => self::MAX_NAME_LENGTH,
            ], 'Rejoindre une visioconférence', $this->env, null),
            $status,
        );
    }

    // =====================================================================
    // POST /visio — L'ORDRE DES OPÉRATIONS EST LA SÉCURITÉ DE LA STORY
    // =====================================================================

    private function join(Request $request, int $now): Response
    {
        $token = $request->input('g');
        $name = trim($request->input('name'));
        $submitted = $request->input('password');

        // ── 1. Validation LOCALE du nom ──────────────────────────────────
        //
        // Elle passe en premier parce qu'elle ne dépend en RIEN du jeton : son
        // message est le même pour un lien valide et pour un lien inventé,
        // donc elle n'apprend rien à personne.
        $errors = [];

        if ($name === '') {
            $errors['name'] = 'Indiquez le nom sous lequel vous souhaitez apparaître.';
        } elseif (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            $errors['name'] = sprintf('Le nom ne doit pas dépasser %d caractères.', self::MAX_NAME_LENGTH);
        }

        if ($errors !== []) {
            return $this->form($token, $name, $errors, 422);
        }

        // ── 2. Résolution du jeton d'invitation ──────────────────────────
        $room = $this->store->roomByGuestToken($token);
        $expected = $room !== null ? $this->store->guestPassword($room->id) : null;

        // ── 3. Comparaison en TEMPS CONSTANT, factice si le jeton est inconnu
        //
        // Le résultat est calculé AVANT de savoir s'il servira : c'est ce qui
        // rend le travail identique dans les deux cas.
        $matches = hash_equals($expected ?? self::DECOY_PASSWORD, $submitted);

        if ($room === null || $expected === null) {
            // Jeton inconnu OU invitation révoquée — deux causes, et l'invité
            // ne peut pas les distinguer. Une révocation rend d'ailleurs
            // littéralement le jeton inconnu : c'est le même chemin de code.
            return $this->refused();
        }

        // ── 4. La fenêtre d'échecs, consultée AVANT de répondre ──────────
        //
        // ⚠️ Saturée ⇒ refus indistinct **même si le mot de passe est le bon**.
        // C'est le prix du non-oracle : un message « trop de tentatives »
        // distinct confirmerait l'existence du salon, ce que quatre-vingt-dix
        // pour cent de ce fichier existe pour empêcher. Les jetons INCONNUS ne
        // sont ni comptés ni limités — leur espace fait 128 bits, l'énumération
        // est sans espoir ; la seule chose devinable est le mot de passe d'un
        // jeton connu, et c'est elle qui est gardée.
        if ($this->store->guestFailuresInWindow($room->id, $now, self::WINDOW_SECONDS) >= self::MAX_FAILURES) {
            return $this->refused();
        }

        if (! $matches) {
            $this->store->recordGuestFailure($room->id, $now, self::WINDOW_SECONDS);

            return $this->refused();
        }

        $this->store->resetGuestFailures($room->id);

        // ── 5. ALORS SEULEMENT, le réseau ────────────────────────────────
        //
        // Aucune requête non authentifiée par le mot de passe n'atteint cette
        // ligne. C'est ce qui empêche la route publique de devenir un moyen
        // commode de faire travailler le serveur de visioconférence — ou de
        // saturer les quatre workers du serveur intégré.
        $server = $room->serverId !== null ? $this->store->server($room->serverId) : null;

        if ($server === null || $server['enabled'] !== true) {
            // Salon jamais démarré, serveur retiré ou désactivé : « pas encore
            // ouvert », et ça ne coûte aucun appel.
            return $this->closed($room, $token);
        }

        $secrets = $this->store->roomSecrets($room->id);

        if ($secrets === null) {
            return $this->closed($room, $token);
        }

        $baseUrl = (string) $server['base_url'];
        $secret = (string) $server['secret'];

        $running = $this->api->isMeetingRunning($baseUrl, $secret, $room->token);

        if (! $running->answered()) {
            return $this->closed(
                $room,
                $token,
                'Le service de visioconférence ne répond pas pour le moment. Réessayez dans un instant.',
            );
        }

        if (! $running->running) {
            return $this->closed($room, $token);
        }

        // ── 6. L'URL de jonction, fabriquée ICI, avec `attendee_pw` ──────
        //
        // Fabrication LOCALE et signée : rien ne sort, et le mot de passe
        // n'apparaît que dans l'en-tête `Location:` que le navigateur suit
        // immédiatement. Le mot de passe modérateur, lui, n'est pas même lu.
        return Response::redirectTo(
            $this->api->joinUrl($baseUrl, $secret, $room->token, $name . self::GUEST_SUFFIX, $secrets['attendee'])
        );
    }

    // =====================================================================

    /**
     * **LE refus, et il n'en existe qu'un.**
     *
     * Cette page ne reflète RIEN de la requête : ni le jeton soumis, ni le nom,
     * ni la cause. C'est ce qui la rend identique octet pour octet dans les
     * quatre cas — une garantie qu'aucune relecture attentive ne peut donner,
     * mais qu'un test peut affirmer.
     *
     * Le coût est assumé : pour réessayer, l'invité rouvre le lien qu'on lui a
     * transmis. C'est deux clics contre un oracle d'existence.
     */
    private function refused(): Response
    {
        return Response::html(
            $this->view->page('guest-refused', [], 'Accès refusé', $this->env, null),
            403,
        );
    }

    /** « Pas encore ouvert » — un état NORMAL, jamais une erreur, et sans auto-rafraîchissement. */
    private function closed(Room $room, string $token, string $message = ''): Response
    {
        return Response::html(
            $this->view->page('guest-closed', [
                'room' => $room,
                'token' => $token,
                'message' => $message,
            ], 'Visioconférence non ouverte', $this->env, null),
        );
    }
}
