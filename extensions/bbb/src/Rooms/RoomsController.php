<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Rooms;

use SambaEdu\ExtBbb\Bbb\BbbApiClient;
use SambaEdu\ExtBbb\Bbb\RoomMeeting;
use SambaEdu\ExtBbb\Env;
use SambaEdu\ExtBbb\Http\Csrf;
use SambaEdu\ExtBbb\Http\Request;
use SambaEdu\ExtBbb\Http\Response;
use SambaEdu\ExtBbb\Http\SessionStore;
use SambaEdu\ExtBbb\Identity;
use SambaEdu\ExtBbb\Store;
use SambaEdu\ExtBbb\Url;
use SambaEdu\ExtBbb\View;

/**
 * Story 57.2 — **LES SALONS : L'AUTORISATION CHANGE DE CAMP.**
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  CE QUE CE FICHIER EXISTE POUR TUER
 *
 *  SE4 n'avait AUCUNE autorisation côté serveur. Sa « visibilité » n'était
 *  qu'un filtre d'affichage : le formulaire de jonction postait `meetingId`,
 *  `attendedPW` **et** `moderatorPW` en champs cachés, dans le HTML servi à
 *  tout le monde ; et le lancement donnait le mot de passe modérateur à
 *  **tout non-élève**, sur n'importe quel salon, même créé par un autre
 *  professeur. Il suffisait de lire la source d'une page pour entrer
 *  modérateur dans le cours d'un collègue.
 *
 *  Ici, trois règles, et elles ne souffrent aucune exception :
 *
 *  1. **La décision se rejoue à CHAQUE requête**, à partir de la table et des
 *     claims de l'identité — jamais d'un champ de formulaire. Le client dit
 *     seulement « le salon <jeton> », et il ne dit rien d'autre.
 *  2. **Aucun mot de passe BigBlueButton ne traverse le navigateur** : ni dans
 *     le HTML, ni dans une URL de page, ni dans un champ caché, ni dans un
 *     journal. La seule sortie est l'URL de jonction signée, fabriquée ici et
 *     posée dans un `Location:`.
 *  3. **Le rôle dans la conférence découle de la table** : créateur ⇒
 *     modérateur ; toute autre personne autorisée — élève, professeur
 *     co-membre de la classe, administratif sur un salon d'établissement ⇒
 *     participant.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * **Refus INDISTINCT.** Un jeton inconnu et un salon qu'on n'a pas le droit de
 * voir rendent exactement la même réponse. Distinguer les deux offrirait un
 * oracle : on saurait qu'un salon existe sans y avoir droit.
 *
 * **Les actions qui appellent un serveur BigBlueButton sont des POST**, jamais
 * des GET. Un GET serait préchargé au survol d'un lien par un navigateur ou un
 * antivirus, et ouvrirait des meetings tout seul — sur un serveur HTTP intégré
 * mono-processus, c'est aussi un moyen de bloquer l'extension entière.
 */
final class RoomsController
{
    /**
     * **Qui peut créer — décision instruite.** Le besoin exprimé, comme l'AC,
     * ne parle que du professeur. Le claim de rôle est un scalaire à profil
     * métier prioritaire : un professeur qui administre reste `prof`. Ouvrir la
     * création à `admin` fabriquerait des salons sans classes possibles (les
     * groupes d'un administrateur pur sont vides ou ne sont que des équipes),
     * pour un besoin que personne n'a formulé. Élargir un jour = changer cette
     * constante, et rien d'autre.
     */
    public const CREATOR_ROLE = 'prof';

    /** Un nom de salon est un intitulé, pas un texte. */
    public const MAX_NAME_LENGTH = 100;

    private const FLASH = 'rooms.flash';

    private const CSRF = 'rooms.csrf';

    public function __construct(
        private readonly Store $store,
        private readonly BbbApiClient $api,
        private readonly View $view,
        private readonly Env $env,
    ) {
    }

    public function handle(Request $request, SessionStore $session): Response
    {
        // L'identité est relue ICI, à chaque requête. Elle n'est jamais acquise
        // une fois pour toutes à la connexion : elle périme, et un rôle devenu
        // invalide ne rend plus aucune identité du tout.
        $identity = Identity::fromSessionStore($session);

        if ($identity === null) {
            return Response::redirect('/login');
        }

        if (strtoupper($request->method) !== 'POST') {
            return $this->index($session, $identity);
        }

        if (! (new Csrf(self::CSRF))->matches($request, $session)) {
            return $this->errorPage($identity, 'bbb.rooms.csrf', 'Formulaire expiré ou invalide.', 403);
        }

        return match ($request->routePath()) {
            '/rooms/start' => $this->start($request, $session, $identity),
            '/rooms/join' => $this->join($request, $session, $identity),
            '/rooms/delete' => $this->delete($request, $session, $identity),
            default => $this->create($request, $session, $identity),
        };
    }

    // =====================================================================
    // Liste
    // =====================================================================

    /**
     * `GET /rooms` — **rendue depuis SQLite SEULEMENT.**
     *
     * Aucun appel BigBlueButton : afficher la liste ne doit jamais dépendre de
     * la santé d'un tiers. Le legacy interrogeait tous ses serveurs à
     * l'affichage, avec un cache pour amortir la note — et un cache à
     * réconcilier. Ici, `last_started_at` est un repère daté, pas un état.
     */
    private function index(SessionStore $session, Identity $identity, int $status = 200): Response
    {
        return $this->render($session, $identity, [], [], $status);
    }

    /**
     * @param  array<string, string>  $errors
     * @param  array<string, mixed>  $old
     */
    private function render(
        SessionStore $session,
        Identity $identity,
        array $errors,
        array $old,
        int $status = 200,
    ): Response {
        $rooms = $this->store->roomsVisibleTo($identity);

        $mine = [];
        $others = [];

        foreach ($rooms as $room) {
            if ($room->isOwnedBy($identity->sub)) {
                $mine[] = $room;
            } else {
                $others[] = $room;
            }
        }

        return Response::html(
            $this->view->page('rooms', [
                'mine' => $mine,
                'others' => $others,
                'canCreate' => $this->canCreate($identity),
                'groups' => $identity->groups,
                'errors' => $errors,
                'old' => $old,
                'flash' => $this->takeFlash($session),
                'csrf' => (new Csrf(self::CSRF))->token($session),
            ], 'Salons', $this->env, $identity),
            $status,
        );
    }

    // =====================================================================
    // Création
    // =====================================================================

    private function create(Request $request, SessionStore $session, Identity $identity): Response
    {
        if (! $this->canCreate($identity)) {
            return $this->errorPage(
                $identity,
                'bbb.rooms.forbidden',
                'Seuls les professeurs peuvent créer un salon.',
                403,
            );
        }

        $name = trim($request->input('name'));
        $visibility = $request->input('visibility');
        $submitted = $request->inputList('groups');

        $errors = [];

        if ($name === '') {
            $errors['name'] = 'Le nom du salon est obligatoire.';
        } elseif (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            $errors['name'] = sprintf('Le nom du salon ne doit pas dépasser %d caractères.', self::MAX_NAME_LENGTH);
        }

        if (! in_array($visibility, Room::VISIBILITIES, true)) {
            $errors['visibility'] = 'Choisissez qui pourra voir ce salon.';
        }

        $groups = [];

        if ($visibility === Room::VISIBILITY_CLASSE) {
            $groups = array_values(array_unique($submitted));

            if ($groups === []) {
                $errors['groups'] = 'Choisissez au moins une classe ou une équipe.';
            }

            foreach ($groups as $group) {
                // ═══════════════════════════════════════════════════════════
                //  LA GARDE QUI PORTE L'AC : le `<select>` ne décide de RIEN.
                //
                //  Un groupe soumis qui n'est pas dans les groupes de CETTE
                //  identité est refusé EXPLICITEMENT, jamais filtré en
                //  silence : une valeur inventée n'est pas une faute de
                //  frappe, c'est une tentative — et l'utilisateur légitime,
                //  lui, ne peut pas la produire.
                // ═══════════════════════════════════════════════════════════
                if (! in_array($group, $identity->groups, true)) {
                    $errors['groups'] = 'Vous ne pouvez ouvrir un salon que pour vos propres classes et équipes.';

                    break;
                }
            }
        }

        if ($errors !== []) {
            // Rendu DIRECT plutôt que redirection : la saisie est conservée.
            return $this->render($session, $identity, $errors, [
                'name' => $name,
                'visibility' => $visibility,
                'groups' => $submitted,
            ], 422);
        }

        $this->store->addRoom($name, $identity->sub, $identity->name, $visibility, $groups);

        $this->flash($session, 'success', 'Salon créé. Il s\'ouvrira quand vous le démarrerez.');

        return Response::redirect('/rooms');
    }

    // =====================================================================
    // Démarrage — l'acte du créateur
    // =====================================================================

    /**
     * `POST /rooms/start` — **« démarrer OU entrer », et c'est le même bouton.**
     *
     * `createMeeting` est idempotent côté BigBlueButton : le même identifiant
     * avec les mêmes mots de passe rend `SUCCESS` sur un meeting déjà vivant.
     * C'est cette propriété de l'API qui remplace, à elle seule, le miroir en
     * mémoire du legacy et son ramasse-miettes — il n'y a aucun état de
     * fonctionnement à réconcilier, donc aucun état à désynchroniser.
     */
    private function start(Request $request, SessionStore $session, Identity $identity): Response
    {
        $room = $this->ownedRoom($request, $identity);

        if ($room === null) {
            return $this->notFound($identity);
        }

        $server = $this->store->firstEnabledServer();

        if ($server === null) {
            $this->flash(
                $session,
                'error',
                'Aucun serveur de visioconférence configuré — prévenez l\'administrateur.',
            );

            return Response::redirect('/rooms');
        }

        $secrets = $this->store->roomSecrets($room->id);

        if ($secrets === null) {
            return $this->notFound($identity);
        }

        $baseUrl = (string) $server['base_url'];
        $secret = (string) $server['secret'];

        // Verrou d'état relâché AVANT le réseau (review 57.2 #1) : le tenir
        // pendant les 8 s de borne bloquerait les autres onglets de la même
        // personne, et immobiliserait un worker de plus que nécessaire.
        $session->close();

        $result = $this->api->createMeeting($baseUrl, $secret, new RoomMeeting(
            meetingId: $room->token,
            name: $room->name,
            attendeePassword: $secrets['attendee'],
            moderatorPassword: $secrets['moderator'],
            logoutUrl: Url::absolute($this->env, '/rooms'),
        ));

        if (! $result->isOk()) {
            $this->flash($session, 'error', $result->message);

            return Response::redirect('/rooms');
        }

        // Le serveur du salon n'est mémorisé qu'après un démarrage RÉUSSI :
        // pointer un serveur qui n'a rien ouvert ferait attendre les élèves
        // devant une porte qui n'existe pas.
        $this->store->markStarted($room->id, (int) $server['id']);

        return $this->redirectToConference($baseUrl, $secret, $room, $identity, $secrets['moderator']);
    }

    // =====================================================================
    // Jonction — l'acte de tous les autres
    // =====================================================================

    private function join(Request $request, SessionStore $session, Identity $identity): Response
    {
        $room = $this->store->roomByToken($request->input('token'));

        // Jeton inconnu ET salon non visible : MÊME réponse, même code, même
        // page. Et surtout : aucun appel sortant n'a encore été fait — un refus
        // ne doit pas se trahir par le temps qu'il met.
        if ($room === null || ! $room->isVisibleTo($identity)) {
            return $this->notFound($identity);
        }

        $server = $room->serverId !== null ? $this->store->server($room->serverId) : null;

        // Salon jamais démarré, ou serveur supprimé / désactivé depuis :
        // « pas ouvert ». C'est un état NORMAL, pas une erreur — et il ne coûte
        // aucun appel.
        if ($server === null || $server['enabled'] !== true) {
            return $this->closedPage($room, $identity);
        }

        $baseUrl = (string) $server['base_url'];
        $secret = (string) $server['secret'];

        // Idem `start()` : rien ne justifie de tenir le verrou d'état pendant un
        // aller-retour réseau (review 57.2 #1).
        $session->close();

        $running = $this->api->isMeetingRunning($baseUrl, $secret, $room->token);

        if (! $running->answered()) {
            $this->flash($session, 'error', $running->message);

            return Response::redirect('/rooms');
        }

        if (! $running->running) {
            return $this->closedPage($room, $identity);
        }

        $secrets = $this->store->roomSecrets($room->id);

        if ($secrets === null) {
            return $this->notFound($identity);
        }

        // ═══════════════════════════════════════════════════════════════════
        //  LA MATRICE DE RÔLE, ET ELLE TIENT EN UNE LIGNE
        //
        //  Créateur ⇒ modérateur. Tout le reste ⇒ participant, y compris un
        //  professeur co-membre de la classe et un administratif sur un salon
        //  d'établissement. Le « tout non-élève est modérateur » du legacy est
        //  MORT ici, et il n'y a pas d'endroit où le ressusciter par mégarde :
        //  le mot de passe se choisit à partir de la ligne du salon, pas du
        //  profil de la personne.
        // ═══════════════════════════════════════════════════════════════════
        $password = $room->isOwnedBy($identity->sub) ? $secrets['moderator'] : $secrets['attendee'];

        return $this->redirectToConference($baseUrl, $secret, $room, $identity, $password);
    }

    // =====================================================================
    // Suppression
    // =====================================================================

    private function delete(Request $request, SessionStore $session, Identity $identity): Response
    {
        $room = $this->ownedRoom($request, $identity);

        if ($room === null) {
            return $this->notFound($identity);
        }

        $this->store->deleteRoom($room->id);

        $this->flash($session, 'success', 'Salon supprimé.');

        return Response::redirect('/rooms');
    }

    // =====================================================================

    /**
     * L'ULTIME sortie d'un mot de passe de salon : un en-tête de redirection.
     *
     * ⚠️ L'URL n'est ni journalisée, ni affichée, ni conservée. Le nom affiché
     * dans la conférence est celui de l'identité — jamais une saisie de
     * l'utilisateur, sans quoi n'importe qui pourrait se présenter comme
     * n'importe qui.
     */
    private function redirectToConference(
        string $baseUrl,
        string $secret,
        Room $room,
        Identity $identity,
        string $password,
    ): Response {
        return Response::redirectTo(
            $this->api->joinUrl($baseUrl, $secret, $room->token, $identity->name, $password)
        );
    }

    /** Le salon désigné, à condition d'en être le créateur. Sinon : rien du tout. */
    private function ownedRoom(Request $request, Identity $identity): ?Room
    {
        $room = $this->store->roomByToken($request->input('token'));

        return $room !== null && $room->isOwnedBy($identity->sub) ? $room : null;
    }

    private function canCreate(Identity $identity): bool
    {
        return $identity->role === self::CREATOR_ROLE;
    }

    /** LA réponse indistincte : même code, même message, même statut. */
    private function notFound(Identity $identity): Response
    {
        return $this->errorPage(
            $identity,
            'bbb.rooms.not_found',
            'Ce salon n\'existe pas, ou ne vous est pas accessible.',
            404,
        );
    }

    private function closedPage(Room $room, Identity $identity): Response
    {
        return Response::html(
            $this->view->page('room-closed', ['room' => $room], 'Salon fermé', $this->env, $identity),
        );
    }

    private function errorPage(Identity $identity, string $code, string $message, int $status): Response
    {
        return Response::html(
            $this->view->page('error', [
                'code' => $code,
                'message' => $message,
                'canRetry' => false,
            ], 'Accès refusé', $this->env, $identity),
            $status,
        );
    }

    // ── État de page ─────────────────────────────────────────────────────

    private function flash(SessionStore $session, string $type, string $message): void
    {
        /** @var list<array{type: string, message: string}> $current */
        $current = (array) $session->get(self::FLASH, []);
        $current[] = ['type' => $type, 'message' => $message];
        $session->put(self::FLASH, $current);
    }

    /** @return list<array{type: string, message: string}> */
    private function takeFlash(SessionStore $session): array
    {
        /** @var list<array{type: string, message: string}> $current */
        $current = (array) $session->get(self::FLASH, []);
        $session->forget(self::FLASH);

        return $current;
    }
}
