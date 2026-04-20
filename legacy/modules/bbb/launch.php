<?php

/*
 * Launch meeting
 */
include "config.inc.php";
require "ldap.inc.php";
require_once ("functions.inc.php");
require_once ("traitement_data.inc.php");
$config = get_config();
include 'admin_ui.inc.php';
$html = "";
admin_header_html($config, $html);
admin_topbar_html($config, $html);
admin_menu_html($config, $html);
$html .= header_authorize($config);
include "ihm.inc.php";
include "bbb.inc.php";
require "../vendor/autoload.php";
use BigBlueButton\BigBlueButton;
use BigBlueButton\Parameters\CreateMeetingParameters;
use BigBlueButton\Parameters\JoinMeetingParameters;

$bbb_list = config_bbb($config);
if (empty($bbb_list)) {
    echo $html;
    echo "Le module BigBlueButton n'est pas configuré. Contactez l'administrateur.";
} else {

    $action = $_POST['valider'] ?? "";
    $meetingId = $_POST['meetingId'] ?? "";
    $attendedPW = $_POST['attendedPW'] ?? "";
    $moderatorPW = $_POST['moderatorPW'] ?? "";
    $visibility = $_POST['visib'] ?? "";
    $bbbServer = $_POST['bbbServer'] ?? "";
    $classes = $_POST['classes'] ?? [];
    $secret = $_POST['secret'] ?? [];
    $CONF_HASH = $_POST['CONF_HASH'] ?? [];
    $GuestPolicy = $_POST['GuestPolicy'] ?? "";

    curl_proxy_options($config, $curlopts);
    if (! empty($action) && count($bbb_list) > 0 && empty($meetingId)) {
        /*
         * Création d'un meeting ($meetingId est vide)
         */

        /*
         * On recherche le serveur le moins chargé !
         *
         */
        $serveur_min = 0;
        $up = false;
        foreach ($bbb_list as $key => $value) {
            if (server_bbb_is_up($config, $value['url'])) {
                $response[$key] = load_server_bbb($config, $value);
                if ($response[$serveur_min]['nb_users'] >= $response[$key]['nb_users']) {
                    $serveur_min = $key;
                }
                $up = true;
            }
        }
        if (! $up) {
            echo $html;
            return "Aucun serveur n'est disponible ... Merci de contacter l'administrateur.";
        }
        // putenv("BBB_SECRET=" . $bbb_list[$serveur_min]['secret']);
        // putenv("BBB_SERVER_BASE_URL=" . $bbb_list[$serveur_min]['url']);

        $meetingName = $_POST['meetingName'] ?? "";
        $username = $_POST['username'] ?? "";
        $urlLogout = "https://" . $_SERVER['HTTP_HOST']; // "https://www.sambaedu.org/";

        $bbb = new BigBlueButton($bbb_list[$serveur_min]['url'], $bbb_list[$serveur_min]['secret'], $curlopts);
        // $bbb->setCurlOpts($curlopts);
        /*
         * Génération des mots de passe
         */
        $attendee_password = rand(100, 999);
        $moderator_password = rand(1000, 9999);
        /*
         * ID du meeting
         * etab||world||classe +"-"+ hash du ldap_base_dn + "-" + hash du login + "-" + hash des classes + "-" + 3random + "-" + +random
         */
        $login = $config['login'];
        /**
         * hash du ldapdn
         */
        if ($visibility == "etab") {
            $id_beg = "etab-" . md5($config['ldap_base_dn']);
        } elseif ($visibility == "world") {
            $id_beg = "world-" . md5($config['ldap_base_dn']);
        } elseif ($visibility == "classe") {
            $id_beg = "classe-" . md5($config['ldap_base_dn']);
        } elseif ($visibility == "private") {
            $id_beg = "private-" . md5($config['ldap_base_dn']);
        }
        /**
         * hash des classes
         */
        $classes_hash = "";
        foreach ($classes as $key => $value) {
            $classes_hash = $classes_hash . "-" . md5($value);
        }
        $classes_hash = $classes_hash . "-";
        /**
         * forme le meetingID
         */
        $meetingId = $id_beg . "-" . md5($login) . $classes_hash . rand(100, 999) . "-" . rand(100, 999);
        /*
         * Message d'accueil dans la boîte de chat
         */

        $WelcomeMessage = "$meetingName <br /> Une visio propuls&eacute;e par Samba&Eacute;du";
        /*
         * On stocke les paramètres pour les invités
         * 4 heures si l'option est choisie
         *
         */
        if ((! empty($secret)) && (! empty($CONF_HASH))) {
            $visio_ext = apcu_fetch('visio_ext');
            $visio_ext[$CONF_HASH]['meetingId'] = $meetingId;
            $visio_ext[$CONF_HASH]['secret'] = $secret;
            apcu_store('visio_ext', $visio_ext, 240 * 60);
            $URL_GUESS = "https://" . $_SERVER['HTTP_HOST'] . "/visio/?salon=$CONF_HASH";
            $WelcomeMessage .= "<br /><br />Pour rejoindre cette conférence : <br /> $URL_GUESS ";
        }

        /*
         * Paramètres du meeting
         */
        $createMeetingParams = new CreateMeetingParameters($meetingId, $meetingName . " de " . $username);
        $createMeetingParams->setAttendeePassword($attendee_password);
        $createMeetingParams->setModeratorPassword($moderator_password);
        $createMeetingParams->setCopyright("Samba&Eacute;du 4");
        $createMeetingParams->setWelcomeMessage($WelcomeMessage);
        $createMeetingParams->setLogoutUrl($urlLogout);
        $createMeetingParams->setAllowStartStopRecording(true);
        $createMeetingParams->setRecord(true);

        /*
         * paramètres incontournables
         */
        $createMeetingParams->setAllowModsToEjectCameras(true);
        $createMeetingParams->setBreakoutRoomsEnabled(true);
        $createMeetingParams->setBreakoutRoomsRecord(true);
        $createMeetingParams->setBreakoutRoomsPrivateChatEnabled(true);
        $createMeetingParams->setMeetingLayout("");
        /*
         * Salle D'attente
         */
        if ($GuestPolicy == "1") {
            $createMeetingParams->setGuestPolicy("ASK_MODERATOR");
        } else {
            $createMeetingParams->setGuestPolicy("ALWAYS_ACCEPT");
        }
        /*
         * Tchat privés désactivés
         */
        $createMeetingParams->setLockSettingsDisablePrivateChat(true);

        /**
         * $createMeetingParams->addPresentation(['https://www.sambaedu.org/IMG/pdf/diapo_visio_se.pdf']);
         */
        /**
         * Par défaut, les meetings durent 4heures
         */
        $createMeetingParams->setDuration(240);
        /*
         * $createMeetingParams->setLogoutUrl($urlLogout);
         * if ($isRecordingTrue) {
         * $createMeetingParams->setRecord(true);
         * $createMeetingParams->setAllowStartStopRecording(true);
         * $createMeetingParams->setAutoStartRecording(true);
         * }
         */
        $response = $bbb->createMeeting($createMeetingParams);
        if (preg_match("/FAILED/", $response->getReturnCode())) {
            echo $html;
            // @TODO blacklister le serveur pour en essayer un autre
            return "Impossible de créer le salon... Merci de contacter l'administrateur.<br><pre>" . print_r($response) . "</pre>";
        } else {
            // Send a join meeting request
            $joinParams = new JoinMeetingParameters($meetingId, $username, $moderator_password);
            //
            // automatic redirection
            $joinParams->setRedirect(true);
            header('Status: 301 Moved Permanently', false, 301);
            header('Location:' . $bbb->getJoinMeetingURL($joinParams));

            /*
             * On renseigne les infos du meeting dans la variable
             * meeting_info
             * pour éviter de recharger apcu qui est couteux
             */
            $meeting_info = load_meeting_info($config, $bbb_list);
            $CPT = count($meeting_info);
            $meeting_info[$CPT]['meetingName'] = $meetingName . " de " . $username;
            $meeting_info[$CPT]['meetingID'] = $meetingId;
            $meeting_info[$CPT]['attendeePW'] = $attendee_password;
            $meeting_info[$CPT]['moderatorPW'] = $moderator_password;
            $meeting_info[$CPT]['server'] = $bbb_list[$serveur_min]['url'];
            $meeting_info[$CPT]['moderator'] = $username;
            /*
             * on stocke tout ça dans la variable apcu
             */
            apcu_store('meeting_info', $meeting_info, 1200);
        }
    } elseif (! empty($action) && count($bbb_list) > 0 && ! empty($meetingId) && ! empty($attendedPW)) {
        /*
         * Jonction d'un meeting ($meetingId est renséigné)
         */
        $login = $config['login'];
        $user = search_user($config, $login);
        $fullName = $user['fullname'];
        $PW = $attendedPW;
        if (! is_eleve($config, $login)) {
            $PW = $moderatorPW;
        }
        /**
         * On rejoint le serveur BBB passé en POST
         */
        $key = array_search($bbbServer, array_column($bbb_list, 'url'));
        // putenv("BBB_SECRET=" . $bbb_list[$key]['secret']);
        // putenv("BBB_SERVER_BASE_URL=" . $bbb_list[$key]['url']);

        $bbb = new BigBlueButton($bbb_list[$key]['url'], $bbb_list[$key]['secret'], $curlopts);
        // $bbb->setCurlOpts($curlopts);

        $joinParams = new JoinMeetingParameters($meetingId, $fullName, $PW);
        $joinParams->setRedirect(true);
        header('Status: 301 Moved Permanently', false, 301);
        header('Location:' . $bbb->getJoinMeetingURL($joinParams));
    }
}
?>