<?php

/**
 * SID
 */

use BigBlueButton\BigBlueButton;
use BigBlueButton\Parameters\GetRecordingsParameters;
use BigBlueButton\Parameters\DeleteRecordingsParameters;
use BigBlueButton\Parameters\IsMeetingRunningParameters;
use BigBlueButton\Parameters\JoinMeetingParameters;

// use BigBlueButton\TestCase;
function config_bbb($config)
{
    $secret = explode(",", $config['bbb_secret']);
    $scalelite = explode(",", @$config['bbb_server_scalelite']);
    foreach (explode(",", $config['bbb_server_base_url']) as $key => $url) {
        $bbb[$key] = [
            'url' => $url,
            'secret' => $secret[$key],
            'scalelite' => $scalelite[$key] ?? 0
        ];
    }
    return $bbb;
}

/**
 * Retourne le formulaire de configuration
 *
 * @param string $bbb_url
 * @param string $bbb_secret
 * @return formulaire de configuration
 */
function form_config_bbb($bbb_url, $bbb_secret, $bbb_scalelite)
{
    $html = "<h1>Paramètres du/des serveur(s) BigBlueButton configuré(s)</h1>";
    $bbb_url_array = explode(",", $bbb_url);
    $bbb_secret_array = explode(",", $bbb_secret);
    $bbb_scalelite_array = explode(",", $bbb_scalelite);

    $html .= '<form class="form" action="" method="post">';
    $key = 0;
    /**
     * Si un ou des serveurs déjà définis
     */
    if ($bbb_url != "") {
        foreach ($bbb_url_array as $key => $url) {
            $scalelite = $bbb_scalelite_array[$key] ?? 0;
            $html .= 'URL du serveur BigBlueButton : ';
            $html .= '<input type="text" name="bbb_url[' . $key . ']" id="bbb_url[' . $key . ']" value="' . $url . '" size="50"  />';
            $html .= '<br />';
            $html .= 'Secret du serveur BigBlueButton : ';
            $html .= '<input type="text" name="bbb_secret[' . $key . ']" id="bbb_secret[' . $key . ']" value="' . $bbb_secret_array[$key] . '" size="50"  />';
            $html .= '<br />';
            $html .= 'Seuil de bascule pour serveur Scalelite ( 0 pour un serveur normal) : ';
            $html .= '<input type="text" name="bbb_scalelite[' . $key . ']" id="bbb_scalelite[' . $key . ']" value="' . $scalelite . '" size="3"  />';
            $html .= '<p><input color="red" type="submit" name="suppr[' . $key . ']" value="Supprimer ce serveur"/></p>';
            $html .= '<br />';
        }
    }
    /**
     * Ajout d'un serveur
     */
    $key += 1;
    $html .= "<h1>Ajouter un serveur BigBlueButton</h1>";
    $html .= 'URL du serveur BigBlueButton : 
    <input type="text" name="bbb_url[' . $key . ']" id="bbb_url[' . $key . ']" value="" size="50"  />
    <br />
    Secret du serveur BigBlueButton : 
    <input type="text" name="bbb_secret[' . $key . ']" id="bbb_secret[' . $key . ']" value="" size="50"  />
    <br />
    Seuil de bascule pour serveur Scalelite ( 0 pour un serveur normal) :
    <input type="text" name="bbb_scalelite[' . $key . ']" id="bbb_scalelite[' . $key . ']" value="' . $bbb_scalelite_array[$key] . '" size="3"  />
    <input type="submit" name="valider" value="Valider les modifications"/>
    </form>';
    return $html;
}

/**
 * Retourne le formulaire de création de visio
 *
 * @param array $config
 * @param unknown $name
 * @param unknown $sexe
 * @return string formulaire de création
 */
function form_create_bbb($config, $name, $sexe)
{
    $visib = $_POST['visib'] ?? '';
    $classes = $_POST['classes'] ?? [];
    $action = $_POST['valider'] ?? "";
    $username = $_POST['username'] ?? '';
    $meetingName = $_POST['meetingName'] ?? '';
    $secret = $_POST['secret'] ?? '';
    $GuestPolicy = $_POST['GuestPolicy'] ?? '';

    $html = "";
    if (! empty($action)) {
        $dest = 'action="/bbb/launch.php" method="post" '; // target="_blank"';
        $valider = "Lancer la visioconférence";
        $readonly = "readonly";
    } else {
        $dest = 'action="" method="post" target=""';
        $valider = "Préparer la visioconférence";
        $readonly = "";
    }
    $selected = "";
    if (empty($readonly)) {
        if ($username == "") {
            if ($sexe == 'M') {
                $username = "M $name";
            } elseif ($sexe == 'F') {
                $username = "Mme $name";
            } else
                $username = $name;
        }
        if ($meetingName == "") {
            $meetingName = "Cours de " . $username;
        }
        $html .= "<h1>Création d'une visioconférence</h1>";
        $html .= "<h2>Préparez puis lancez votre salon</h2>";
        $html .= "<p>Vous pouvez modifier les paramètres ci-dessous</p>";
        $html .= '<form class="form" ' . $dest . '>';
        $html .= '<p class="username">';
        $html .= '<label for="username">Nom de l\'administrateur du salon : </label>';
        $html .= '<input type="text" name="username" id="username" value="' . $username . '" />';
        $html .= '</p>';
        $html .= '<p class="meeting">';
        $html .= '<label for="meetingName">Nom du salon : </label>';
        $html .= '<input type="text" name="meetingName" id="meetingName" value="' . $meetingName . '"  />';
        $html .= '<p class="visibility">';
        $html .= '<label for="visibility">Limiter la visibilité du salon à : </label>';
        $html .= '<SELECT name="visib" onchange="submit()" >';
        $html .= '<OPTION value="etab" >l\'établissement';
        if ($visib == "classe") {
            $selected = "SELECTED";
        } else {
            $selected = "";
        }
        $html .= '<OPTION value="classe" ' . $selected . '>des classes';
        if ($visib == "private") {
            $selected = "SELECTED";
        } else {
            $selected = "";
        }
        $html .= '<OPTION value="private" ' . $selected . '>des personnels de l\'établissement ';
        if ($visib == "world") {
            $selected = "SELECTED";
        } else {
            $selected = "";
        }
        $html .= '<OPTION value="world" ' . $selected . '>tous les établissements';
        $html .= '</SELECT>';
        if ($visib == "classe") {
            $html .= choix_classe_bbb($config);
        }
        $html .= '<p class="secret">';
        $html .= '<label for="secret">Indiquez un mot de passe si vous souhaitez inviter des participants extérieurs à l\'établissement : </label>';
        $html .= '<input type="text" name="secret" id="secret" value="' . $secret . '" />';
        $html .= '</p>';

        $html .= '<p class="GuestPolicy">';
        $html .= "L'entrée des participants doit être validée par le(s) modérateur(s) (salle d'attente) : ";
        $html .= '<input type="checkbox" name="GuestPolicy" id="GuestPolicy"  >';
        $html .= '</p>';

        $html .= '<p class="submit"><input type="submit" name="valider" value="' . $valider . '"/></p></form>';
    } else {

        $html .= "<h1>Lancement de la visioconférence</h1>";
        $html .= '<form class="form" ' . $dest . '  >';
        $html .= '<p class="username">';
        $html .= '<label for="username">Nom de l\'administrateur du salon : </label>';
        $html .= '<input type="text" name="username" id="username" value="' . $username . '" ' . $readonly . ' />';
        $html .= '</p>';
        $html .= '<p class="meeting">';
        $html .= '<label for="meetingName">Nom du salon : </label>';
        $html .= '<input type="text" name="meetingName" id="meetingName" value="' . $meetingName . '" ' . $readonly . ' />';
        $html .= '<input type="hidden" name="visib" id="visib" value="' . $visib . '" />';
        if ($visib == "etab") {
            $html .= "<p>Salon visible par toutes les classes.";
        } elseif ($visib == "classe") {
            $html .= "<p>Visibilité du salon limitée pour : <ul>";
            foreach ($classes as $key => $value) {
                $html .= "<li>" . $value . "</li>";
                $html .= '<input type="hidden" name="classes[' . $key . ']"  value="' . $value . '"  />';
            }
            $html .= "</ul>";
        } elseif ($visib == "private") {
            $html .= "<p>Salon visible par tous les personnels de l'établissement.";
        }
        if (! empty($secret)) {
            $bbb_secret = explode(',', $config['bbb_secret']) ?? '';
            $bbb_url = explode(',', $config['bbb_server_base_url']) ?? '';
            $login = $config['login'];
            $CONF_HASH = $login;
            rand(100, 999) . "-" . rand(100, 999);
            $URL_VISIO = "https://" . $_SERVER['HTTP_HOST'] . "/visio/?salon=$CONF_HASH";
            $html .= '<p class="secret">';
            $html .= '<label for="secret">Vos invités peuvent rejoindre votre conférence à cette adresse permanente : </label>';
            $html .= '<b>' . $URL_VISIO . '</b>';
            $html .= '<label for="secret">en indiquant le mot de passe : </label>';
            $html .= '<input type="text" name="secret" id="secret" value="' . $secret . '" ' . $readonly . '/>';
            $html .= '</p>';
            $html .= '<input type="hidden" name="CONF_HASH"  value="' . $CONF_HASH . '" />';
        }

        if (! empty($GuestPolicy)) {
            $html .= '<input type="hidden" name="GuestPolicy"  value="1" />';
            $html .= '<label for="secret">' . "L'entrée des participants doit être validée par le(s) modérateur(s) (salle d'attente)";
        }
        $html .= '<p class="submit"><input type="submit" name="valider" value="' . $valider . '"   /></p></form>';
    }
    $html .= "<h2>Les cours sont limités à <b>4 heures</b>.</h2>";
    return $html;
}

function choix_classe_bbb($config)
{
    $list_classe = list_classes_etab_fast($config);
    // usort($list_classe, 'triClasseParName');
    $html = "";
    $classes_choix = $_POST['classes'] ?? [];

    if (count($list_classe) > 15) {
        $size = 15;
    } else {
        $size = count($list_classe);
    }
    if (count($list_classe) > 0) {
        $html .= "<p>";
        $html .= "Choisissez les classes : ";
        $html .= "<p><select size=\"" . $size . "\" name=\"classes[]\" multiple>\n";
        foreach ($list_classe as $classes) {
            if (in_array($classes, $classes_choix))
                $selected = "SELECTED";
            else
                $selected = "";
            $html .= "<option value=\"" . $classes . "\"  $selected >" . $classes . "</option>\n";
        }
        $html .= "</select>\n";
    }
    return $html;
}

/**
 */

/**
 * Pour chaque serveur BBB on stocke les infos des meetings
 * on utilise apcu pour cache
 *
 * A chaque commencement de meeting on rajoute la clé dans le tableau et on stocke à nouveau
 *
 * Toute les 20 secondes le ramasse miette enlève les meetings fermés ou coupés
 */
function load_meeting_info($config, $bbb_list, $cron = false)
{
    $clean_meetings = false;
    $meeting_info = apcu_fetch('meeting_info');
    if ($meeting_info === false || $cron) {
        $meeting_info = [];
        $cpt = 0;
        foreach ($bbb_list as $key => $value) {
            // putenv("BBB_SECRET=" . $value['secret']);
            // putenv("BBB_SERVER_BASE_URL=" . $value['url']);
            if (server_bbb_is_up($config, $value['url'])) {
                $opt = [
                    'cookies' => true
                ];
                curl_proxy_options($config, $opt);
                $bbb = new BigBlueButton($value['url'], $value['secret'], $opt);
                $response = $bbb->getMeetings();
                if ($response->getReturnCode() == 'SUCCESS') {
                    foreach ($response->getRawXml()->meetings->meeting as $meeting) {
                        $meeting_info[$cpt] = infos_meeting_bbb($meeting);
                        $meeting_info[$cpt]['server'] = $value['url'];
                        $cpt += 1;
                    }
                } else {
                    unset($meeting_info[$cpt]);
                }
            }
        }
        apcu_store('meeting_info', $meeting_info, 1200);
    } else {
        /*
         * Passage du ramasse miette pour enlever les
         * conférences terminées
         */
        $meeting_info_garbage_collector = apcu_fetch('meeting_info_garbage_collector');
        if ($meeting_info_garbage_collector === false) {
            $meeting_tri = [];
            foreach ($meeting_info as $key => $meeting) {
                $meetingServer = $meeting['server'];
                $meeting_tri[$meetingServer][$key] = $meeting;
            }
            foreach ($meeting_tri as $meetingServer => $meetings) {

                /*
                 * Search bbb server key
                 */
                $key2 = array_search($meetingServer, array_column($bbb_list, 'url'));
                $meetingServerSecret = $bbb_list[$key2]['secret'];
                // putenv("BBB_SECRET=$meetingServerSecret");
                // putenv("BBB_SERVER_BASE_URL=$meetingServer");
                /*
                 * on ne teste pas les scalelites sauf en cron.
                 */
                if (server_bbb_is_up($config, $meetingServer) && ($bbb_list[$key2]['scalelite'] == 0 || $cron)) {
                    $opt = [
                        'cookies' => true
                    ];
                    curl_proxy_options($config, $opt);
                    $bbb = new BigBlueButton($meetingServer, $meetingServerSecret, $opt);
                    foreach ($meetings as $key => $meeting) {
                        $meetingId = $meeting['meetingID'];
                        $is_meeting_running = new IsMeetingRunningParameters($meetingId);
                        $response = $bbb->IsMeetingRunning($is_meeting_running);
                        /*
                         * Si le metting ne tourne plus,
                         * incrémente is_running
                         * on efface si ça dépasse ... 4
                         */
                        if (($response->getReturnCode() == 'FAILED') || (! $response->isRunning())) {
                            // meeting not found or already closed
                            if (isset($meeting['is_running']) && ($meeting['is_running'] > 2)) {
                                // unset($meeting_info[$key]);
                                /*
                                 * Plutôt qu'un unset on reload toute les confs
                                 */
                                $clean_meetings = true;
                            } else {
                                if (! isset($meeting_info[$key]['is_running'])) {
                                    $meeting_info[$key]['is_running'] = 0;
                                }
                                $meeting_info[$key]['is_running'] += 1;
                            }
                        } else {
                            $meeting_info[$key]['is_running'] = 0;
                        }
                    }
                }
            }
            if ($clean_meetings) {
                /*
                 * S'il faut nettoyer les salons
                 * par exemple si un ou plusieurs semblent fermés
                 * Alors on supprime le cache pour ces 2 variables
                 * et on retourne un appel récusrsif
                 * qui ne passera donc pas par là
                 */
                apcu_delete('meeting_info');
                apcu_delete('meeting_info_garbage_collector');
                return load_meeting_info($config, $bbb_list);
            } else {
                apcu_store('meeting_info', $meeting_info, 600);
                apcu_store('meeting_info_garbage_collector', "done", 10);
            }
        }
    }
    return $meeting_info;
}

/**
 * Retourne la liste des salons de visio
 *
 * @param unknown $meetings
 *            array
 * @return formulaire de création
 */
function liste_meetings2join_bbb($config, $login, $bbb_list, $hash)
{
    $meeting_info = load_meeting_info($config, $bbb_list);

    $html_etab = "";
    $html_world = "";
    $html_classes = "";
    $html_private = "";

    foreach ($meeting_info as $key => $meeting) {
        $meetingName = $meeting['meetingName'];
        $meetingId = $meeting['meetingID'];
        $meetingServer = $meeting['server'];
        $attendeePW = $meeting['attendeePW'];
        $moderatorPW = $meeting['moderatorPW'];
        if (count(explode('-', $meetingId)) > 1) {
            $hash_etab_meeting = explode('-', $meetingId)[1];
        } else {
            $hash_etab_meeting = "";
        }
        if (stripos($meetingId, "etab") === 0 && $hash_etab_meeting == $hash) {
            $html_etab .= '<form class="form" action="/bbb/launch.php" method="post" target="_blank">';
            $html_etab .= "<li><b>$meetingName</b> ";
            $html_etab .= '<input type="hidden" name="meetingId" value=' . $meetingId . '>';
            $html_etab .= '<input type="hidden" name="attendedPW" value=' . $attendeePW . '>';
            $html_etab .= '<input type="hidden" name="moderatorPW" value=' . $moderatorPW . '>';
            $html_etab .= '<input type="hidden" name="bbbServer" value=' . $meetingServer . '>';
            $html_etab .= '<input type="submit" name="valider" value="Rejoindre ce salon"/>';
            $html_etab .= "</li></form>";
        } elseif (stripos($meetingId, "classe") === 0 && $hash_etab_meeting == $hash) {
            $list_classe = list_classes_etab_fast($config);
            $classeInMeeting = [];
            foreach ($list_classe as $classes) {
                if (in_array(md5($classes), explode("-", $meetingId))) {
                    $classeInMeeting[] = $classes;
                }
            }
            /**
             * On affiche tous les meetings pour les nons eleves
             * Seulement ceux des classes pour les élèves
             */

            if (! is_eleve($config, $login) || in_array(list_classes($config, search_user($config, $login))[0], $classeInMeeting)) {
                $classeInMeeting = implode(" ", $classeInMeeting);
                $html_classes .= '<form class="form" action="/bbb/launch.php" method="post" target="_blank">';
                $html_classes .= "<li><b>$meetingName</b> ";
                $html_classes .= '<input type="hidden" name="meetingId" value=' . $meetingId . '>';
                $html_classes .= '<input type="hidden" name="attendedPW" value=' . $attendeePW . '>';
                $html_classes .= '<input type="hidden" name="moderatorPW" value=' . $moderatorPW . '>';
                $html_classes .= '<input type="hidden" name="bbbServer" value=' . $meetingServer . '>';
                $html_classes .= '<input type="submit" name="valider" value="Rejoindre ce salon"/>';
                $html_classes .= '<p><FONT size="0.5em">Limité à :' . $classeInMeeting . '</FONT></p>';
                $html_classes .= "</li></form>";
            }
        } elseif (stripos($meetingId, "world") === 0) {
            $html_world .= '<form class="form" action="/bbb/launch.php" method="post" target="_blank">';
            $html_world .= "<li><b>$meetingName</b> ";
            $html_world .= '<input type="hidden" name="meetingId" value=' . $meetingId . '>';
            $html_world .= '<input type="hidden" name="attendedPW" value=' . $attendeePW . '>';
            $html_world .= '<input type="hidden" name="moderatorPW" value=' . $moderatorPW . '>';
            $html_world .= '<input type="hidden" name="bbbServer" value=' . $meetingServer . '>';
            $html_world .= '<input type="submit" name="valider" value="Rejoindre ce salon"/>';
            $html_world .= "</li></form>";
        } elseif (stripos($meetingId, "private") === 0 && (! is_eleve($config, $login))) {
            $html_private .= '<form class="form" action="/bbb/launch.php" method="post" target="_blank">';
            $html_private .= "<li><b>$meetingName</b> ";
            $html_private .= '<input type="hidden" name="meetingId" value=' . $meetingId . '>';
            $html_private .= '<input type="hidden" name="attendedPW" value=' . $attendeePW . '>';
            $html_private .= '<input type="hidden" name="moderatorPW" value=' . $moderatorPW . '>';
            $html_private .= '<input type="hidden" name="bbbServer" value=' . $meetingServer . '>';
            $html_private .= '<input type="submit" name="valider" value="Rejoindre ce salon"/>';
            $html_private .= "</li></form>";
        }
    }
    $html = "<h1>Visioconférences en cours</h1>";
    if (! empty($html_classes)) {
        $html .= '<center><h2>Visioconférences de l\'établissement limitées à des classes :</h2></center>';
        $html .= $html_classes;
    }
    if (! empty($html_etab)) {
        $html .= '<center><h2>Visioconférences de l\'établissement :</h2></center>';
        $html .= $html_etab;
    }
    if (! empty($html_world)) {
        $html .= '<center><h2>Visioconférences publiques  :</h2></center>';
        $html .= $html_world;
    }
    if (! empty($html_private)) {
        $html .= '<center><h2>Visioconférences privées  :</h2></center>';
        $html .= $html_private;
    }
    $html .= "";
    return $html;
}

/**
 * Liste de tous les meetings pour l'admin
 * uniquement visible dans la partie administration
 *
 * @param unknown $bbb
 * @param unknown $secret
 * @return string
 */
function liste_meetings_servers_bbb($config, $bbb_list)
{
    $cpt = 0;

    $meeting_info = [];
    foreach ($bbb_list as $key => $value) {
        // putenv("BBB_SECRET=" . $value['secret']);
        // putenv("BBB_SERVER_BASE_URL=" . $value['url']);
        if (server_bbb_is_up($config, $value['url'])) {
            $opt = [
                'cookies' => true
            ];
            curl_proxy_options($config, $opt);
            $bbb = new BigBlueButton($value['url'], $value['secret'], $opt);
            $response = $bbb->getMeetings();
            if ($response->getReturnCode() == 'SUCCESS') {
                foreach ($response->getRawXml()->meetings->meeting as $meeting) {
                    $meeting_info[$cpt] = infos_meeting_bbb($meeting);
                    $meeting_info[$cpt]['server'] = $value['url'];
                    $cpt += 1;
                }
            } else {
                unset($meeting_info[$cpt]);
            }
        }
    }

    $nb_users = 0;
    $html = "<table class='table-bordered'>";
    $html .= "<tr><th>Serveur</th><th>Nom du salon</th><th>Ouverture du salon</th><th>Nb</th><th>Modérateurs</th><th>Participants</th></tr>";
    foreach ($meeting_info as $key => $meeting) {
        $html .= "<tr><td>" . $meeting['server'] . "</td>";
        $html .= "<td>" . $meeting['meetingName'] . "</td>";
        $html .= "<td>" . date("\l\\e d/m \à H:i", round($meeting['createTime'] / 1000)) . "</td><td>" . $meeting['participantCount'] . "</td>";
        $html .= "<td>" . $meeting['moderator'] . "</td><td>" . $meeting['attendee'] . "</td></tr>";
    }
    $html .= "</table>";
    return $html;
}

/**
 * Information sur le meeting
 *
 * @param unknown $meetings
 * @return array|string
 */
function infos_meeting_bbb($meeting)
{
    $ret['meetingName'] = (string) $meeting->meetingName;
    $ret['createTime'] = (int) $meeting->createTime;
    $ret['participantCount'] = (int) $meeting->participantCount;
    $ret['meetingID'] = (string) $meeting->meetingID;
    $ret['attendeePW'] = (string) $meeting->attendeePW;
    $ret['moderatorPW'] = (string) $meeting->moderatorPW;
    $ret['moderator'] = "";
    $ret['attendee'] = "";
    foreach ($meeting->attendees->attendee as $attendee) {
        if ($attendee->role == "MODERATOR") {
            if (empty($ret['moderator'])) {
                $ret['moderator'] = (string) $attendee->fullName;
            } else {
                $ret['moderator'] = $ret['moderator'] . "," . $attendee->fullName;
            }
        } elseif (empty($ret['attendee'])) {
            $ret['attendee'] = (string) $attendee->fullName;
        } else {
            $ret['attendee'] = $ret['attendee'] . "," . $attendee->fullName;
        }
    }
    return $ret;
}

/**
 * Renvoie la charge de chaque serveur
 */
function info_servers_bbb($config, $bbb_list)
{
    $ret = "<ul>";
    foreach ($bbb_list as $key => $value) {
        if (server_bbb_is_up($config, $value['url'])) {
            $response = load_server_bbb($config, $value);
            if ($response['nb_meetings'] != 0) {
                $ret .= "<li>Sur le serveur <b>" . $value['url'] . "</b> :  " . $response['nb_meetings'] . " conférences totalisant " . $response['nb_users'] . " utilisateurs.</li>\n";
            } else {
                $ret .= "<li>Sur le serveur <b>" . $value['url'] . "</b> :   aucune conférence.</li>\n";
            }
        } else {
            $ret .= "<li>Le serveur " . $value['url'] . " n'est pas joignable</li>\n";
        }
    }
    $ret .= "</ul>\n";
    return $ret;
}

/**
 * Compte le nombre d'utilisateurs et de meetings sur le serveur appelé
 * Retourne un tableau contenant :
 * ['nb_meetings'] et ['nb_users']
 */
function load_server_bbb($config, $bbb)
{
    if ($bbb['scalelite'] != 0) {
        $i = 0;
        if ($meeting_info = apcu_fetch("meeting_info")) {
            foreach ($meeting_info as $key => $meeting) {
                if ($meeting['server'] == $bbb['url']) {
                    $i ++;
                }
            }
        }
        $ret['nb_meetings'] = $i;
        $ret['nb_users'] = $bbb['scalelite'];
    } else {
        // putenv("BBB_SECRET=" . $bbb['secret']);
        // putenv("BBB_SERVER_BASE_URL=" . $bbb['url']);
        $opt = [
            'cookies' => true
        ];
        curl_proxy_options($config, $opt);
        $bbb = new BigBlueButton($bbb['url'], $bbb['secret'], $opt);
        $response = $bbb->getMeetings();
        if ($response->getReturnCode() == 'SUCCESS') {
            $meetings = $response->getRawXml()->meetings->meeting;
            $nb_users = 0;
            foreach ($meetings as $meeting) {
                $nb_users += $meeting->participantCount;
            }
            $ret['nb_meetings'] = count($meetings);
            $ret['nb_users'] = $nb_users;
        } else {
            $ret['nb_meetings'] = 0;
            $ret['nb_users'] = 0;
        }
    }
    return $ret;
}

/**
 * Retourne le tableau des enregistrements
 *
 * @param array $bbb_url
 * @param array $bbb_secret
 * @param string $hash
 * @return array $records
 */
function liste_records_bbb($config, $bbb_list, $hash)
{
    $cpt = 0;
    $record = [];
    foreach ($bbb_list as $key => $value) {
        if (server_bbb_is_up($config, $value['url'])) {
            // putenv("BBB_SECRET=" . $value['secret']);
            // putenv("BBB_SERVER_BASE_URL=" . $value['url']);
            $recordingParams = new GetRecordingsParameters();
            curl_proxy_options($config, $curlopts);
            $bbb = new BigBlueButton($value['url'], $value['secret'], $curlopts);
            $response = $bbb->getRecordings($recordingParams);
            if ($response->getReturnCode() == 'SUCCESS') {
                foreach ($response->getRawXml()->recordings->recording as $recording) {
                    if (stripos($recording->meetingID, "world") === 0 || in_array("$hash", explode('-', $recording->meetingID))) {
                        $record[$cpt]['meetingID'] = (string) $recording->meetingID;
                        $record[$cpt]['recordID'] = (string) $recording->recordID;
                        $record[$cpt]['startTime'] = (int) $recording->startTime;
                        $record[$cpt]['server'] = (string) $value['url'];
                        $record[$cpt]['url'] = (string) $recording->playback->format->url;
                        $record[$cpt]['meetingName'] = (string) $recording->metadata->meetingName;
                        $record[$cpt]['recordName'] = (string) $recording->metadata->name;
                        $record[$cpt]['name'] = (string) $recording->name;
                        $record[$cpt]['duration'] = (int) $recording->playback->format->length;
                        $cpt += 1;
                    }
                }
            }
        }
    }
    /**
     * Tri du tableau par startTime
     */
    usort($record, 'triParStartTime');
    return $record;
}

/**
 *
 * @param array $config,
 * @param string $login
 * @param string $bbb_list
 * @param string $hash
 * @return string $html
 */
function html_liste_records_bbb($config, $login, $bbb_list, $hash)
{
    /*
     * On utilise apcu pour cache l'ensemble
     * des enregistrements des serveur
     * pendant 20 minutes
     */
    // On gagne BEAUCOUP de temps en factorisant ce test
    $is_admin = have_right($config, SE_ADMIN);
    $is_not_eleve = ! is_eleve($config, $login);

    // mise en cache du tableau de la liste des records
    $records = apcu_fetch('liste_records_bbb');
    if ($records === false) {
        $records = liste_records_bbb($config, $bbb_list, $hash);
        apcu_add('liste_records_bbb', $records, 1800);
    }

    $html = "<h1> Enregistrements disponibles</h1>";
    $html .= "";
    $html .= "<ul>";

    /*
     * on récupère la liste des classes
     */
    $list_classe = list_classes_etab_fast($config);

    foreach ($records as $key => $value) {
        apcu_delete_multi("#bbb#");
        $meetingId = $value['meetingID'];
        $classeInMeeting = [];
        foreach ($list_classe as $classes) {
            if (in_array(md5($classes), explode("-", $meetingId))) {
                $classeInMeeting[] = $classes;
            }
        }
        /**
         * On affiche les records
         * etab : pour tous
         * world : pour tous
         * classe : si l'élève est dedans
         * (29/04/2021) : Pour les autres : uniquement pour les créateurs de la visio
         */
        if (explode("-", $value['meetingID'])[2] == md5($login) || $is_admin || stripos($meetingId, "world") === 0 || stripos($meetingId, "etab") === 0 || in_array(list_classes($config, search_user($config, $login))[0], $classeInMeeting)) {
            $html .= "<li> ";
            $html .= date("\l\\e d/m \à H:i", round($value['startTime'] / 1000));
            $html .= " <a href=\"" . $value['url'] . "\">";
            $html .= $value['meetingName'];
            $html .= "</a> (";
            $html .= $value['duration'];
            $html .= "min.) ";
            /**
             * On permet la suppression pour admin ou pour le créateur
             */
            if (explode("-", $value['meetingID'])[2] == md5($login) || $is_admin) {
                $html .= '<form style="display: inline-block;" class="form" action="" method="post" target="">';
                $html .= '<input type="hidden" name="bbbServer" value=' . $value['server'] . '>';
                $html .= '<input type="hidden" name="recordID" value=' . $value['recordID'] . '>';
                $html .= '<input type="submit" name="supprimer" value="Supprimer définitivement"/>';
                $html .= "</form>";
            }
            $html .= "</li>";
        }
    }
    $html .= "</ul>";
    return $html;
}

function triParStartTime($a, $b)
{
    return $a['startTime'] / 1000 > $b['startTime'] / 1000;
}

function remove_record_bbb($config, $bbb_list, $recordID, $bbbServer)
{
    $html = "";
    /**
     * On trouve le bon serveur/secret
     */
    $secret = $bbb_list[array_search($bbbServer, array_column($bbb_list, 'url'))]['secret'];
    // putenv("BBB_SECRET=$secret");
    // putenv("BBB_SERVER_BASE_URL=$bbbServer");
    $opt = [
        'cookies' => true
    ];
    curl_proxy_options($config, $opt);
    $bbb = new BigBlueButton($bbbServer, $secret, $opt);
    $deleteRecordingsParams = new DeleteRecordingsParameters($recordID);
    $response = $bbb->deleteRecordings($deleteRecordingsParams);

    if ($response->getReturnCode() == 'SUCCESS') {
        // recording deleted
        $html .= "Suppression faite";
        /**
         * En cas de suppression on vide le cache *
         */
        if (apcu_fetch('liste_records_bbb')) {
            apcu_delete('liste_records_bbb');
        }
    } else {
        // something wrong
        $html .= "Problème à la suppression...";
    }
    return $html;
}

/**
 * Server is up ?
 */
function server_bbb_is_up($config, $bbb)
{
    $opt = [
        'cookies' => true
    ];
    curl_proxy_options($config, $opt);
    $client = new GuzzleHttp\Client($opt);
    try {
        $response = $client->request('GET', $bbb, [
            'connect_timeout' => 2
        ]);
        if ($response) {
            return true;
        } else {
            return false;
        }
    } catch (\GuzzleHttp\Exception\TransferException $e) {
        return false;
    }

    /*
     *
     * if (! empty($config['server_proxy'])) {
     * $context = "";
     * $proxy = "tcp://" . $config['proxy_address'] . ":" . $config['proxy_port'];
     * $context = stream_context_create(array(
     * 'http' => array(
     * 'proxy' => $proxy,
     * 'timeout' => 1,
     * )
     * ));
     * $F = @fopen($bbb, "r", false, $context);
     * } else {
     * $context = stream_context_create(array(
     * 'http' => array(
     * 'timeout' => 1,
     * )
     * ));
     * $F = @fopen($bbb, "r");
     * }
     * if ($F) {
     * fclose($F);
     * return true;
     * } else
     * return false;
     */
}
?>
