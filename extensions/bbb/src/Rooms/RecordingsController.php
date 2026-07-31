<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Rooms;

use SambaEdu\ExtBbb\Bbb\BbbApiClient;
use SambaEdu\ExtBbb\Bbb\RecordingItem;
use SambaEdu\ExtBbb\Env;
use SambaEdu\ExtBbb\Http\Csrf;
use SambaEdu\ExtBbb\Http\Request;
use SambaEdu\ExtBbb\Http\Response;
use SambaEdu\ExtBbb\Http\SessionStore;
use SambaEdu\ExtBbb\Identity;
use SambaEdu\ExtBbb\Store;
use SambaEdu\ExtBbb\View;

/**
 * Story 57.3 — **LES ENREGISTREMENTS : « LES MIENS » EST UNE COLONNE, PAS UN
 * DÉCODAGE.**
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  L'ANTI-LEGACY, EN TROIS INVERSIONS
 *
 *  SE4 appelait `getRecordings()` **sans aucun filtre** sur chaque serveur,
 *  ramenait tout l'historique de l'établissement, le mettait en cache une
 *  demi-heure, puis triait en PHP : `explode('-', $meetingID)[2] == md5(login)`.
 *  Le segment n°2 n'était le bon que pour les salons SANS classes — un salon de
 *  classe décalait l'index, et le professeur ne retrouvait pas ses propres
 *  enregistrements. Bug réel, documenté (carte §5).
 *
 *  1. **La requête part FILTRÉE** par les jetons de MES salons : moins de
 *     données sur le fil, et aucun cache à réconcilier — il n'y a pas de cache
 *     du tout.
 *  2. **La propriété vient de `rooms.owner_sub`**, une colonne, lue en SQL. Il
 *     n'y a plus rien à décoder, donc plus rien à décoder de travers.
 *  3. **La réponse est RE-FILTRÉE quand même.** Le `meetingID` de la requête
 *     est un paramètre qu'on ENVOIE, pas une garantie qu'on reçoit : un serveur
 *     qui l'ignorerait rendrait les enregistrements de tout l'établissement. La
 *     défense en profondeur coûte ici une comparaison de chaînes.
 *
 *  Et le serveur interrogé vient de `rooms.server_id`, posé au démarrage par
 *  57.2 — jamais d'un champ caché du navigateur, comme le faisait
 *  `bbb/records.php:38`.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * **`GET /recordings` est le seul GET de l'extension à faire des appels
 * sortants, et c'est assumé.** La règle de 57.2 — « les pages lisent SQLite, les
 * actes appellent le réseau » — visait les mutations : un `createMeeting`
 * déclenché par un préchargement de navigateur ouvrirait des conférences tout
 * seul. Lister des enregistrements ne mute RIEN, l'appel est borné, et le verrou
 * d'état est relâché avant. En contrepartie, aucun attribut de préchargement
 * n'est posé sur le lien qui mène ici.
 *
 * **Classe sœur de {@see RoomsController}, même patron.** Les gardes, le refus
 * indistinct, le jeton anti-CSRF et le mécanisme de message flash sont ceux de
 * 57.2, volontairement à l'identique : deux modèles d'autorisation dans une même
 * extension, c'est un de trop.
 */
final class RecordingsController
{
    /**
     * **Accès : `prof` STRICT — décision instruite.** L'AC ne parle que du
     * professeur, et la matrice de 57.2 a déjà tranché que le profil métier
     * prime : un professeur qui administre reste `prof`. Un élève n'a rien à
     * faire ici ; un administrateur pur n'a pas de salon, donc rien à y voir —
     * et lui ouvrir la page en ferait un chemin d'accès aux cours des autres
     * que personne n'a demandé.
     */
    public const VIEWER_ROLE = 'prof';

    /** Même espace de clé que les salons : les deux pages sont le même parcours. */
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
        $identity = Identity::fromSessionStore($session);

        if ($identity === null) {
            return Response::redirect('/login');
        }

        if ($identity->role !== self::VIEWER_ROLE) {
            // La garde SERVEUR ne dépend pas de l'affichage : le lien
            // « Enregistrements » n'apparaît que pour un professeur, et cela ne
            // vaut rien tant que l'URL directe n'est pas refusée ici.
            return $this->errorPage(
                $identity,
                'bbb.recordings.forbidden',
                'Seuls les professeurs accèdent aux enregistrements.',
                403,
            );
        }

        if (strtoupper($request->method) !== 'POST') {
            return $this->index($session, $identity);
        }

        if (! (new Csrf(self::CSRF))->matches($request, $session)) {
            return $this->errorPage($identity, 'bbb.recordings.csrf', 'Formulaire expiré ou invalide.', 403);
        }

        return $this->delete($request, $session, $identity);
    }

    // =====================================================================
    // Liste
    // =====================================================================

    private function index(SessionStore $session, Identity $identity): Response
    {
        // Message flash et jeton anti-CSRF lus AVANT le réseau : ce sont les
        // deux seules écritures d'état de la page, et les faire d'abord évite
        // de reprendre le verrou après l'avoir relâché.
        $flash = $this->takeFlash($session);
        $csrf = (new Csrf(self::CSRF))->token($session);

        $rooms = $this->store->roomsOwnedBy($identity->sub);

        /** @var array<string, string> $names  jeton public => nom du salon */
        $names = [];

        /** @var array<int, list<string>> $byServer */
        $byServer = [];

        foreach ($rooms as $room) {
            $names[$room->token] = $room->name;

            // Un salon jamais démarré n'a pas de serveur : il ne peut rien
            // avoir enregistré, et il ne coûte donc aucun appel.
            if ($room->serverId !== null) {
                $byServer[$room->serverId][] = $room->token;
            }
        }

        $items = [];
        $errors = [];

        foreach ($byServer as $serverId => $tokens) {
            $server = $this->store->server($serverId);

            if ($server === null || $server['enabled'] !== true) {
                // Cohérent avec le « pas ouvert » du join de 57.2 : un serveur
                // retiré ou désactivé n'est pas interrogé. Les enregistrements
                // réapparaîtront si l'administrateur le réactive — rien n'est
                // perdu, rien n'est supprimé.
                $errors[] = 'Un serveur de visioconférence a été retiré ou désactivé : '
                    . 'les enregistrements des salons qu\'il hébergeait ne sont pas listés.';

                continue;
            }

            // Verrou d'état relâché avant CHAQUE appel sortant (règle posée par
            // la review 57.2 #1) : avec deux serveurs, deux relâchements.
            $session->close();

            $result = $this->api->getRecordings(
                (string) $server['base_url'],
                (string) $server['secret'],
                $tokens,
            );

            if (! $result->isOk()) {
                // Un serveur injoignable n'empêche pas d'afficher les autres.
                $errors[] = $result->message;

                continue;
            }

            foreach ($result->items as $item) {
                // ═══════════════════════════════════════════════════════════
                //  LA RE-VÉRIFICATION QUI ENTERRE `explode('-', …)[2]`
                //
                //  Ce qui n'est pas un salon À MOI n'entre pas dans la liste,
                //  quoi que le serveur ait renvoyé.
                // ═══════════════════════════════════════════════════════════
                if (isset($names[$item->meetingId])) {
                    $items[] = $item;
                }
            }
        }

        // Tri par date de début, du plus récent au plus ancien. Comparateur
        // ENTIER — le legacy passait à `usort` une fonction qui rendait un
        // booléen, ce qui produit un ordre arbitraire (défaut §9.10).
        usort($items, static fn (RecordingItem $a, RecordingItem $b): int => $b->startTime <=> $a->startTime);

        return Response::html(
            $this->view->page('recordings', [
                'items' => $items,
                'names' => $names,
                'errors' => array_values(array_unique($errors)),
                'flash' => $flash,
                'csrf' => $csrf,
            ], 'Enregistrements', $this->env, $identity),
        );
    }

    // =====================================================================
    // Suppression — la propriété se PROUVE, elle ne se déclare pas
    // =====================================================================

    /**
     * `POST /recordings/delete` — quatre gardes, dans cet ordre, et la
     * suppression n'est que la cinquième.
     *
     * Le champ `record` du formulaire est une DEMANDE, pas une autorisation.
     * SE4 prenait de surcroît le serveur cible dans un champ caché non vérifié
     * (`bbb/records.php:38`) : n'importe qui pouvait faire supprimer n'importe
     * quel enregistrement sur n'importe quel serveur.
     */
    private function delete(Request $request, SessionStore $session, Identity $identity): Response
    {
        // 1. Le salon est-il à moi ? Sinon : le 404 indistinct de 57.2, et
        //    surtout aucun appel sortant.
        $room = $this->ownedRoom($request, $identity);

        if ($room === null) {
            return $this->notFound($identity);
        }

        $recordId = $request->input('record');

        if ($recordId === '') {
            $this->flash($session, 'error', 'Aucun enregistrement désigné.');

            return Response::redirect('/recordings');
        }

        // 2. Le serveur du salon est-il encore là, et actif ?
        $server = $room->serverId !== null ? $this->store->server($room->serverId) : null;

        if ($server === null || $server['enabled'] !== true) {
            $this->flash(
                $session,
                'error',
                'Le serveur de visioconférence de ce salon n\'est plus disponible : suppression impossible.',
            );

            return Response::redirect('/recordings');
        }

        $baseUrl = (string) $server['base_url'];
        $secret = (string) $server['secret'];

        // 3. L'enregistrement existe-t-il, et appartient-il bien à CE salon ?
        //    La question est posée au serveur qui détient la réponse, pas au
        //    navigateur qui a envoyé la demande.
        $session->close();

        $found = $this->api->getRecordings($baseUrl, $secret, [], $recordId);

        if (! $found->isOk()) {
            $this->flash($session, 'error', $found->message);

            return Response::redirect('/recordings');
        }

        $belongs = false;

        foreach ($found->items as $item) {
            if ($item->recordId === $recordId && $item->meetingId === $room->token) {
                $belongs = true;

                break;
            }
        }

        if (! $belongs) {
            // AUCUN appel de suppression n'est émis. Un identifiant étranger
            // collé dans le formulaire ne détruit rien.
            $this->flash(
                $session,
                'error',
                'Cet enregistrement n\'appartient pas à ce salon : rien n\'a été supprimé.',
            );

            return Response::redirect('/recordings');
        }

        // 4. Et seulement maintenant, l'acte irréversible.
        $session->close();

        $result = $this->api->deleteRecording($baseUrl, $secret, $recordId);

        $this->flash(
            $session,
            $result->isOk() ? 'success' : 'error',
            $result->isOk() ? 'Enregistrement supprimé.' : $result->message,
        );

        return Response::redirect('/recordings');
    }

    // =====================================================================

    /** Le salon désigné, à condition d'en être le créateur. Sinon : rien du tout. */
    private function ownedRoom(Request $request, Identity $identity): ?Room
    {
        $room = $this->store->roomByToken($request->input('token'));

        return $room !== null && $room->isOwnedBy($identity->sub) ? $room : null;
    }

    /** LA réponse indistincte, identique à celle des salons. */
    private function notFound(Identity $identity): Response
    {
        return $this->errorPage(
            $identity,
            'bbb.rooms.not_found',
            'Ce salon n\'existe pas, ou ne vous est pas accessible.',
            404,
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
