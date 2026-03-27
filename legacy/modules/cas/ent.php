<?php
/**
 * @version $Id$
 * @author Thomas Crespin <thomas.crespin@sesamath.net>
 * @copyright Thomas Crespin 2009-2022
 *
 * ****************************************************************************************************
 * SACoche <https://sacoche.sesamath.net> - Suivi d’Acquisitions de Compétences
 * © Thomas Crespin pour Sésamath <https://www.sesamath.net> - Tous droits réservés.
 * Logiciel placé sous la licence libre Affero GPL 3 <https://www.gnu.org/licenses/agpl-3.0.html>.
 * ****************************************************************************************************
 *
 * Ce fichier est une partie de SACoche.
 *
 * SACoche est un logiciel libre ; vous pouvez le redistribuer ou le modifier suivant les termes
 * de la “GNU Affero General Public License” telle que publiée par la Free Software Foundation :
 * soit la version 3 de cette licence, soit (à votre gré) toute version ultérieure.
 *
 * SACoche est distribué dans l’espoir qu’il vous sera utile, mais SANS AUCUNE GARANTIE :
 * sans même la garantie implicite de COMMERCIALISABILITÉ ni d’ADÉQUATION À UN OBJECTIF PARTICULIER.
 * Consultez la Licence Publique Générale GNU Affero pour plus de détails.
 *
 * Vous devriez avoir reçu une copie de la Licence Publique Générale GNU Affero avec SACoche ;
 * si ce n’est pas le cas, consultez : <http://www.gnu.org/licenses/>.
 *
 */

/**
 * Sous-tableau avec les différents formats de csv d’import
 */
$tab_csv_format = array();
$tab_csv_format[''] = array(
    'csv_infos' => FALSE,
    'csv_entete' => 0,
    'csv_ordre' => TRUE,
    'csv_nom' => 0,
    'csv_prenom' => 0,
    'csv_id_ent' => 0,
    'csv_id_sconet' => NULL
); // Pas d’import de fichier : présence d’un webservices
$tab_csv_format['perso'] = array(
    'csv_infos' => TRUE,
    'csv_entete' => 1,
    'csv_ordre' => TRUE,
    'csv_nom' => 1,
    'csv_prenom' => 2,
    'csv_id_ent' => 0,
    'csv_id_sconet' => NULL
); // Y compris LCS
$tab_csv_format['atos'] = array(
    'csv_infos' => TRUE,
    'csv_entete' => 1,
    'csv_ordre' => TRUE,
    'csv_nom' => 2,
    'csv_prenom' => 3,
    'csv_id_ent' => 0,
    'csv_id_sconet' => 4
);
$tab_csv_format['envole_orleans-tours'] = array(
    'csv_infos' => TRUE,
    'csv_entete' => 0,
    'csv_ordre' => TRUE,
    'csv_nom' => 1,
    'csv_prenom' => 2,
    'csv_id_ent' => 0,
    'csv_id_sconet' => NULL
);
$tab_csv_format['esup'] = array(
    'csv_infos' => TRUE,
    'csv_entete' => 2,
    'csv_ordre' => TRUE,
    'csv_nom' => 2,
    'csv_prenom' => 3,
    'csv_id_ent' => 0,
    'csv_id_sconet' => NULL
);
$tab_csv_format['itop'] = array(
    'csv_infos' => TRUE,
    'csv_entete' => 1,
    'csv_ordre' => TRUE,
    'csv_nom' => 1,
    'csv_prenom' => 2,
    'csv_id_ent' => 0,
    'csv_id_sconet' => NULL
);
$tab_csv_format['itslearning'] = array(
    'csv_infos' => TRUE,
    'csv_entete' => 1,
    'csv_ordre' => TRUE,
    'csv_nom' => 2,
    'csv_prenom' => 3,
    'csv_id_ent' => 4,
    'csv_id_sconet' => NULL
);
$tab_csv_format['gestibase'] = array(
    'csv_infos' => TRUE,
    'csv_entete' => 1,
    'csv_ordre' => TRUE,
    'csv_nom' => 1,
    'csv_prenom' => 2,
    'csv_id_ent' => 0,
    'csv_id_sconet' => 3
);
$tab_csv_format['kosmos'] = array(
    'csv_infos' => TRUE,
    'csv_entete' => 1,
    'csv_ordre' => FALSE,
    'csv_nom' => 'Nom',
    'csv_prenom' => 'Prénom',
    'csv_id_ent' => 'Identifiant ENT',
    'csv_id_sconet' => NULL
);
$tab_csv_format['laclasse'] = array(
    'csv_infos' => TRUE,
    'csv_entete' => 1,
    'csv_ordre' => TRUE,
    'csv_nom' => 1,
    'csv_prenom' => 2,
    'csv_id_ent' => 3,
    'csv_id_sconet' => NULL
);
$tab_csv_format['liberscol'] = array(
    'csv_infos' => TRUE,
    'csv_entete' => 1,
    'csv_ordre' => TRUE,
    'csv_nom' => 0,
    'csv_prenom' => 1,
    'csv_id_ent' => 2,
    'csv_id_sconet' => NULL
);
$tab_csv_format['logica'] = array(
    'csv_infos' => TRUE,
    'csv_entete' => 1,
    'csv_ordre' => TRUE,
    'csv_nom' => 3,
    'csv_prenom' => 4,
    'csv_id_ent' => 0,
    'csv_id_sconet' => 2
);
$tab_csv_format['netocentre'] = array(
    'csv_infos' => TRUE,
    'csv_entete' => 1,
    'csv_ordre' => TRUE,
    'csv_nom' => 2,
    'csv_prenom' => 3,
    'csv_id_ent' => 0,
    'csv_id_sconet' => NULL
);
$tab_csv_format['pentila'] = array(
    'csv_infos' => TRUE,
    'csv_entete' => 1,
    'csv_ordre' => TRUE,
    'csv_nom' => 0,
    'csv_prenom' => 1,
    'csv_id_ent' => 5,
    'csv_id_sconet' => NULL
);
$tab_csv_format['provence'] = array(
    'csv_infos' => TRUE,
    'csv_entete' => 1,
    'csv_ordre' => TRUE,
    'csv_nom' => 1,
    'csv_prenom' => 2,
    'csv_id_ent' => 3,
    'csv_id_sconet' => NULL
);
$tab_csv_format['reunion'] = array(
    'csv_infos' => TRUE,
    'csv_entete' => 1,
    'csv_ordre' => TRUE,
    'csv_nom' => 0,
    'csv_prenom' => 1,
    'csv_id_ent' => 2,
    'csv_id_sconet' => NULL
);
$tab_csv_format['scolastance'] = array(
    'csv_infos' => TRUE,
    'csv_entete' => 1,
    'csv_ordre' => TRUE,
    'csv_nom' => 2,
    'csv_prenom' => 3,
    'csv_id_ent' => 4,
    'csv_id_sconet' => NULL
);
$tab_csv_format['sopra'] = array(
    'csv_infos' => TRUE,
    'csv_entete' => 1,
    'csv_ordre' => TRUE,
    'csv_nom' => 3,
    'csv_prenom' => 4,
    'csv_id_ent' => 2,
    'csv_id_sconet' => NULL
); // éventuellement 17 pour les élèves, mais pas pour les autres profils
$tab_csv_format['toutatice'] = array(
    'csv_infos' => TRUE,
    'csv_entete' => 0,
    'csv_ordre' => TRUE,
    'csv_nom' => 0,
    'csv_prenom' => 1,
    'csv_id_ent' => 2,
    'csv_id_sconet' => NULL
);
$tab_csv_format['webeducation'] = array(
    'csv_infos' => TRUE,
    'csv_entete' => 1,
    'csv_ordre' => TRUE,
    'csv_nom' => 3,
    'csv_prenom' => 4,
    'csv_id_ent' => 5,
    'csv_id_sconet' => 1
);

/**
 * Sous-tableau avec les différents paramétrages de serveurs CAS
 */
$tab_serveur_cas = array();
$tab_serveur_cas[''] = array(
    'serveur_secure' => FALSE,
    'serveur_host_subdomain' => '',
    'serveur_host_domain' => '',
    'serveur_port' => 443,
    'serveur_root' => '',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['cel_creteil'] = array(
    'serveur_secure' => FALSE,
    'serveur_host_subdomain' => '*',
    'serveur_host_domain' => 'ac-creteil.fr',
    'serveur_port' => 8443,
    'serveur_root' => '',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['cloe_dijon'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => '*',
    'serveur_host_domain' => 'ac-dijon.fr',
    'serveur_port' => 8443,
    'serveur_root' => '',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);

$tab_serveur_cas['ecoledirecte'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'api',
    'serveur_host_domain' => 'ecoledirecte.com',
    'serveur_port' => 443,
    'serveur_root' => 'v3/cas',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);

$tab_serveur_cas['gestibase'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'auth',
    'serveur_host_domain' => 'ient.fr',
    'serveur_port' => 443,
    'serveur_root' => 'cas',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);

$tab_serveur_cas['envole_ardennes'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => '*',
    'serveur_host_domain' => 'ac-reims.fr',
    'serveur_port' => 8443,
    'serveur_root' => '',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['envole_orleans-tours_18'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'seshat',
    'serveur_host_domain' => 'ac-orleans-tours.fr',
    'serveur_port' => 8443,
    'serveur_root' => '',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['envole_orleans-tours_28'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'seshat',
    'serveur_host_domain' => 'ac-orleans-tours.fr',
    'serveur_port' => 8443,
    'serveur_root' => '',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['envole_orleans-tours_36'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'seshat',
    'serveur_host_domain' => 'ac-orleans-tours.fr',
    'serveur_port' => 8443,
    'serveur_root' => '',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['envole_orleans-tours_41'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'seshat',
    'serveur_host_domain' => 'ac-orleans-tours.fr',
    'serveur_port' => 8443,
    'serveur_root' => '',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['envole_orleans-tours_45'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'seshat',
    'serveur_host_domain' => 'ac-orleans-tours.fr',
    'serveur_port' => 8443,
    'serveur_root' => '',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);

$tab_serveur_cas['enoe_besancon'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => '*',
    'serveur_host_domain' => 'ac-besancon.fr',
    'serveur_port' => 8443,
    'serveur_root' => '',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);

$tab_serveur_cas['entlibre_ent04'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => '',
    'serveur_host_domain' => 'ent04.fr',
    'serveur_port' => 443,
    'serveur_root' => 'cas',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['entlibre_ent77'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'ent77',
    'serveur_host_domain' => 'seine-et-marne.fr',
    'serveur_port' => 443,
    'serveur_root' => 'cas',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['entlibre_essonne'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'www',
    'serveur_host_domain' => 'moncollege-ent.essonne.fr',
    'serveur_port' => 443,
    'serveur_root' => 'cas',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['entlibre_hauts-de-france'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => '',
    'serveur_host_domain' => 'enthdf.fr',
    'serveur_port' => 443,
    'serveur_root' => 'cas',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['entlibre_martinique'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'colibri',
    'serveur_host_domain' => 'ac-martinique.fr',
    'serveur_port' => 443,
    'serveur_root' => 'cas',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['entlibre_monlycee'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'ent',
    'serveur_host_domain' => 'iledefrance.fr',
    'serveur_port' => 443,
    'serveur_root' => 'cas',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['entlibre_normandie'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'ent',
    'serveur_host_domain' => 'l-educdenormandie.fr',
    'serveur_port' => 443,
    'serveur_root' => 'cas',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['entlibre_nouvelle-aquitaine'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'mon',
    'serveur_host_domain' => 'lyceeconnecte.fr',
    'serveur_port' => 443,
    'serveur_root' => 'cas',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['entlibre_poitiers'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'ent',
    'serveur_host_domain' => 'poitou-charentes.fr',
    'serveur_port' => 443,
    'serveur_root' => 'auth',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['entlibre_pcn'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'ent',
    'serveur_host_domain' => 'parisclassenumerique.fr',
    'serveur_port' => 443,
    'serveur_root' => 'cas',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['entlibre_somme'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'college',
    'serveur_host_domain' => 'entsomme.fr',
    'serveur_port' => 443,
    'serveur_root' => 'cas',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['entlibre_somme_ecole'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'ecole',
    'serveur_host_domain' => 'entsomme.fr',
    'serveur_port' => 443,
    'serveur_root' => 'cas',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);

$tab_serveur_cas['esup_montpellier'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'www',
    'serveur_host_domain' => 'environnementnumeriquedetravail.fr',
    'serveur_port' => 443,
    'serveur_root' => 'cas',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['icart_poitiers'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => '*',
    'serveur_host_domain' => 'ac-poitiers.fr',
    'serveur_port' => '*',
    'serveur_root' => '',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);

$tab_serveur_cas['itop_alsace'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'www',
    'serveur_host_domain' => 'entea.fr',
    'serveur_port' => 443,
    'serveur_root' => 'cas',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['itop_auvergne'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => '',
    'serveur_host_domain' => 'entauvergne.fr',
    'serveur_port' => 443,
    'serveur_root' => 'cas',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['itop_agora06'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'www',
    'serveur_host_domain' => 'agora06.fr',
    'serveur_port' => 443,
    'serveur_root' => 'cas',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['itop_enc92'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'www',
    'serveur_host_domain' => 'enc92.fr',
    'serveur_port' => 443,
    'serveur_root' => 'cas',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['itop_enteduc'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'cas',
    'serveur_host_domain' => 'enteduc.fr',
    'serveur_port' => 443,
    'serveur_root' => 'cas',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['itop_isere'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'www',
    'serveur_host_domain' => 'colleges-isere.fr',
    'serveur_port' => 443,
    'serveur_root' => 'cas',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['itop_marne'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'www',
    'serveur_host_domain' => 'ent-marne.fr',
    'serveur_port' => 443,
    'serveur_root' => 'cas',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['itop_place'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'www',
    'serveur_host_domain' => 'ent-place.fr',
    'serveur_port' => 443,
    'serveur_root' => 'cas',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['itop_rca'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'www',
    'serveur_host_domain' => 'ent-lycees.fr',
    'serveur_port' => 443,
    'serveur_root' => 'cas',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['itop_valdoise'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'ent95',
    'serveur_host_domain' => 'valdoise.fr',
    'serveur_port' => 443,
    'serveur_root' => 'cas',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['itop_yvelines'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'ecollege',
    'serveur_host_domain' => 'yvelines.fr',
    'serveur_port' => 443,
    'serveur_root' => 'cas',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);

$tab_serveur_cas['itop_oze_1d'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'www',
    'serveur_host_domain' => 'oze1d.net',
    'serveur_port' => 443,
    'serveur_root' => 'cas',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['itop_oze_2d'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'www',
    'serveur_host_domain' => 'oze2d.net',
    'serveur_port' => 443,
    'serveur_root' => 'cas',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['itop_oze_alsace'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'oze',
    'serveur_host_domain' => 'entea.fr',
    'serveur_port' => 443,
    'serveur_root' => 'cas',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['itop_oze_enc92'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'enc',
    'serveur_host_domain' => 'hauts-de-seine.fr',
    'serveur_port' => 443,
    'serveur_root' => 'cas',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['itop_oze_ent90'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'www',
    'serveur_host_domain' => 'entcolleges90.fr',
    'serveur_port' => 443,
    'serveur_root' => 'cas',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['itop_oze_isere'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'ent',
    'serveur_host_domain' => 'colleges-isere.fr',
    'serveur_port' => 443,
    'serveur_root' => 'cas',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['itop_oze_marne'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'oze',
    'serveur_host_domain' => 'ent-marne.fr',
    'serveur_port' => 443,
    'serveur_root' => 'cas',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['itop_oze_place'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'oze',
    'serveur_host_domain' => 'ent-place.fr',
    'serveur_port' => 443,
    'serveur_root' => 'cas',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['itop_oze_rca'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'oze',
    'serveur_host_domain' => 'ent-lycees.fr',
    'serveur_port' => 443,
    'serveur_root' => 'cas',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['itop_oze_valdoise'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'ent',
    'serveur_host_domain' => 'valdoise.fr',
    'serveur_port' => 443,
    'serveur_root' => 'cas',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['itop_oze_yvelines'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'ozecollege',
    'serveur_host_domain' => 'yvelines.fr',
    'serveur_port' => 443,
    'serveur_root' => 'cas',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);

$tab_serveur_cas['itslearning_93'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'fdi93',
    'serveur_host_domain' => 'itslfr-aws.com',
    'serveur_port' => 443,
    'serveur_root' => 'access',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['itslearning_caen'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'fip',
    'serveur_host_domain' => 'itslearning.com',
    'serveur_port' => 443,
    'serveur_root' => 'SP/bn',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['itslearning_elyco'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'cas3',
    'serveur_host_domain' => 'e-lyco.fr',
    'serveur_port' => 443,
    'serveur_root' => 'access',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['itslearning_corse'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'cas',
    'serveur_host_domain' => 'itslearning.com',
    'serveur_port' => 443,
    'serveur_root' => 'cas-prod',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['itslearning_individuel'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'cas',
    'serveur_host_domain' => 'itslearning.com',
    'serveur_port' => 443,
    'serveur_root' => 'cas-asp',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['itslearning_lasalle'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'cas',
    'serveur_host_domain' => 'itslearning.com',
    'serveur_port' => 443,
    'serveur_root' => 'cas-entlasalle',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);

$tab_serveur_cas['kosmos_01'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'cas',
    'serveur_host_domain' => 'colleges.ain.fr',
    'serveur_port' => 443,
    'serveur_root' => '',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['kosmos_agora06'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'cas',
    'serveur_host_domain' => 'agora06.fr',
    'serveur_port' => 443,
    'serveur_root' => '',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['kosmos_arsene76'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'cas',
    'serveur_host_domain' => 'arsene76.fr',
    'serveur_port' => 443,
    'serveur_root' => '',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['kosmos_creuse'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'cas',
    'serveur_host_domain' => 'entcreuse.fr',
    'serveur_port' => 443,
    'serveur_root' => '',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['kosmos_cybercolleges42'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'cas',
    'serveur_host_domain' => 'cybercolleges42.fr',
    'serveur_port' => 443,
    'serveur_root' => '',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['kosmos_ecollege31'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'cas.ecollege',
    'serveur_host_domain' => 'haute-garonne.fr',
    'serveur_port' => 443,
    'serveur_root' => '',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['kosmos_grand-est'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'cas',
    'serveur_host_domain' => 'monbureaunumerique.fr',
    'serveur_port' => 443,
    'serveur_root' => '',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['kosmos_moncollege95'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'cas',
    'serveur_host_domain' => 'moncollege.valdoise.fr',
    'serveur_port' => 443,
    'serveur_root' => '',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['kosmos_savoirsnumeriques62'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'cas',
    'serveur_host_domain' => 'savoirsnumeriques62.fr',
    'serveur_port' => 443,
    'serveur_root' => '',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['kosmos_webcollege93'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'cas',
    'serveur_host_domain' => 'webcollege.seinesaintdenis.fr',
    'serveur_port' => 443,
    'serveur_root' => '',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['kosmos_aucollege84'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'cas',
    'serveur_host_domain' => 'aucollege84.vaucluse.fr',
    'serveur_port' => 443,
    'serveur_root' => '',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['kosmos_eclat-bfc'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'cas',
    'serveur_host_domain' => 'eclat-bfc.fr',
    'serveur_port' => 443,
    'serveur_root' => '',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['kosmos_eure'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'cas',
    'serveur_host_domain' => 'ent27.fr',
    'serveur_port' => 443,
    'serveur_root' => '',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['kosmos_occitanie'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'cas',
    'serveur_host_domain' => 'mon-ent-occitanie.fr',
    'serveur_port' => 443,
    'serveur_root' => '',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['kosmos_auvergne-rhone-alpes'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'cas',
    'serveur_host_domain' => 'ent.auvergnerhonealpes.fr',
    'serveur_port' => 443,
    'serveur_root' => '',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);

$tab_serveur_cas['laclasse'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'www',
    'serveur_host_domain' => 'laclasse.com',
    'serveur_port' => 443,
    'serveur_root' => 'sso',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['lareunion'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'seshat',
    'serveur_host_domain' => 'ac-reunion.fr',
    'serveur_port' => 8443,
    'serveur_root' => '',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['lea_hautenormandie'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'nero',
    'serveur_host_domain' => 'l-educdenormandie.fr',
    'serveur_port' => 443,
    'serveur_root' => 'cas',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['liberscol'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'cas',
    'serveur_host_domain' => 'ent-liberscol.fr',
    'serveur_port' => 443,
    'serveur_root' => '',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['logica_elie'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'ent',
    'serveur_host_domain' => 'limousin.fr',
    'serveur_port' => 443,
    'serveur_root' => 'connexion',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['netocentre_orleanstours'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'lycees',
    'serveur_host_domain' => 'netocentre.fr',
    'serveur_port' => 443,
    'serveur_root' => 'cas',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['netocentre_touraine-eschool'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'ent',
    'serveur_host_domain' => 'netocentre.fr',
    'serveur_port' => 443,
    'serveur_root' => 'cas',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['netocentre_chercan'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'ent',
    'serveur_host_domain' => 'netocentre.fr',
    'serveur_port' => 443,
    'serveur_root' => 'cas',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['netocentre_colleges41'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'ent',
    'serveur_host_domain' => 'netocentre.fr',
    'serveur_port' => 443,
    'serveur_root' => 'cas',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['netocentre_eureliens_28'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'ent',
    'serveur_host_domain' => 'netocentre.fr',
    'serveur_port' => 443,
    'serveur_root' => 'cas',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['netocentre_mon-e-college_36'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'ent',
    'serveur_host_domain' => 'netocentre.fr',
    'serveur_port' => 443,
    'serveur_root' => 'cas',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['netocentre_mon-e-college_45'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'ent',
    'serveur_host_domain' => 'netocentre.fr',
    'serveur_port' => 443,
    'serveur_root' => 'cas',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);

$tab_serveur_cas['provence'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'seshat',
    'serveur_host_domain' => 'ac-aix-marseille.fr',
    'serveur_port' => 8443,
    'serveur_root' => '',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['envole_84'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'connect',
    'serveur_host_domain' => 'ac-aix-marseille.fr',
    'serveur_port' => 8443,
    'serveur_root' => '',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);

$tab_serveur_cas['toutatice'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'www',
    'serveur_host_domain' => 'toutatice.fr',
    'serveur_port' => 443,
    'serveur_root' => 'idp/profile/cas',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);

// Obsolète mais à ne pas supprimer car utilisé par le tableau $tab_connexion_info[] ci-après.
$tab_serveur_cas['entlibre_picardie'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'ent',
    'serveur_host_domain' => 'picardie.fr',
    'serveur_port' => 443,
    'serveur_root' => 'cas',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['itop_oise'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'ent',
    'serveur_host_domain' => 'oise.fr',
    'serveur_port' => 443,
    'serveur_root' => 'cas',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['itop_lille'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'www',
    'serveur_host_domain' => 'savoirsnumeriques5962.fr',
    'serveur_port' => 443,
    'serveur_root' => 'cas',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['itslearning_02'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'cas',
    'serveur_host_domain' => 'itslearning.com',
    'serveur_port' => 443,
    'serveur_root' => 'cas-ent02',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['itslearning_04'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'cas',
    'serveur_host_domain' => 'itslearning.com',
    'serveur_port' => 443,
    'serveur_root' => 'cas-ent04',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['itslearning_52'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'cas',
    'serveur_host_domain' => 'itslearning.com',
    'serveur_port' => 443,
    'serveur_root' => 'cas-enthautemarne',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['itslearning_90'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'cas',
    'serveur_host_domain' => 'itslearning.com',
    'serveur_port' => 443,
    'serveur_root' => 'cas-ent90',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['itslearning_calvados_catho'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'cas',
    'serveur_host_domain' => 'itslearning.com',
    'serveur_port' => 443,
    'serveur_root' => 'cas-enteccalvados',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['itslearning_nantes_primaire'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'cas',
    'serveur_host_domain' => 'itslearning.com',
    'serveur_port' => 443,
    'serveur_root' => 'cas-eprimo',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['kosmos_monecollege93'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'cas',
    'serveur_host_domain' => 'monecollege.fr',
    'serveur_port' => 443,
    'serveur_root' => '',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['kosmos_elyco'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'cas',
    'serveur_host_domain' => 'e-lyco.fr',
    'serveur_port' => 443,
    'serveur_root' => '',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['kosmos_entmip'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'cas',
    'serveur_host_domain' => 'entmip.fr',
    'serveur_port' => 443,
    'serveur_root' => '',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['kosmos_savoirsnumeriques5962'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'cas',
    'serveur_host_domain' => 'savoirsnumeriques5962.fr',
    'serveur_port' => 443,
    'serveur_root' => '',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['logica_arsene76'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'ent',
    'serveur_host_domain' => 'arsene76.fr',
    'serveur_port' => 443,
    'serveur_root' => 'connexion',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['logica_celia'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'www',
    'serveur_host_domain' => 'ent-celia.fr',
    'serveur_port' => 443,
    'serveur_root' => 'connexion',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['pentila_cartabledesavoie'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'www',
    'serveur_host_domain' => 'cartabledesavoie.com',
    'serveur_port' => 443,
    'serveur_root' => 'cas',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['pentila_nero'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'ent',
    'serveur_host_domain' => 'pentilanero.fr',
    'serveur_port' => 443,
    'serveur_root' => 'cas',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['scolastance_02'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'cas',
    'serveur_host_domain' => 'scolastance.com',
    'serveur_port' => 443,
    'serveur_root' => 'cas-ent02',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['scolastance_04'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'cas',
    'serveur_host_domain' => 'cg04.fr',
    'serveur_port' => 443,
    'serveur_root' => 'cas-ent04',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['scolastance_alsace'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'cas',
    'serveur_host_domain' => 'scolastance.com',
    'serveur_port' => 443,
    'serveur_root' => 'cas-alsace',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);
$tab_serveur_cas['scolastance_auvergne'] = array(
    'serveur_secure' => TRUE,
    'serveur_host_subdomain' => 'cas',
    'serveur_host_domain' => 'scolastance.com',
    'serveur_port' => 443,
    'serveur_root' => 'cas-auvergne',
    'serveur_url_login' => '',
    'serveur_url_logout' => '',
    'serveur_url_validate' => ''
);

/**
 * Tableau avec les modes d’identification possibles
 */
$tab_connexion_mode = array(
    'normal' => 'Local',
    'cas' => 'Serveur CAS',
    'shibboleth' => 'Shibboleth'
);

/**
 * Tableau avec les informations relatives à chaque connecteur
 * Attention : penser aussi à vérifier que cela n’impacte pas sesamath/_inc/classes/EntConventions.php (ENT des installations académiques).
 */
$tab_connexion_info = array();
$tab_connexion_info['normal']['|sacoche'] = array(
    'txt' => 'Connexion avec les identifiants enregistrés dans SACoche',
    'obsolete' => 0
);
$tab_connexion_info['cas']['|perso'] = array(
    'txt' => 'Configuration CAS manuelle',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => NULL
) + $tab_csv_format['perso'] + $tab_serveur_cas[''];
$tab_connexion_info['cas']['|ecoledirecte'] = array(
    'txt' => 'ENT ÉcoleDirecte',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Aplon/Aplim'
) + $tab_csv_format[''] + $tab_serveur_cas['ecoledirecte'];
$tab_connexion_info['cas']['|gestibase'] = array(
    'txt' => 'ENT Gestibase iENT',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Gestibase'
) + $tab_csv_format['gestibase'] + $tab_serveur_cas['gestibase'];
$tab_connexion_info['cas']['|itslearning_lasalle'] = array(
    'txt' => 'ENT des établissements du réseau Lasalle',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'ItsLearning'
) + $tab_csv_format['itslearning'] + $tab_serveur_cas['itslearning_lasalle'];
$tab_connexion_info['cas']['|itslearning_individuel'] = array(
    'txt' => 'ENT ItsLearning pour des établissements hors collectivité',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'ItsLearning'
) + $tab_csv_format['itslearning'] + $tab_serveur_cas['itslearning_individuel'];
$tab_connexion_info['cas']['|itop_oze_1d'] = array(
    'txt' => 'ENT OZE Premier degré hors collectivité',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'Itop'
) + $tab_csv_format['itop'] + $tab_serveur_cas['itop_oze_1d'];
$tab_connexion_info['cas']['|itop_oze_2d'] = array(
    'txt' => 'ENT OZE Second degré hors collectivité',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Itop'
) + $tab_csv_format['itop'] + $tab_serveur_cas['itop_oze_2d'];
$tab_connexion_info['cas']['01|kosmos_01'] = array(
    'txt' => 'ENT K-d’école (département de l’Ain)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_01'];
$tab_connexion_info['cas']['01|kosmos_auvergne-rhone-alpes'] = array(
    'txt' => 'ENT Ma classe en Auvergne-Rhône-Alpes',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_auvergne-rhone-alpes'];
$tab_connexion_info['cas']['02|scolastance_02'] = array(
    'txt' => 'ENT département de l’Aisne sur Scolastance',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'Infostance'
) + $tab_csv_format['scolastance'] + $tab_serveur_cas['scolastance_02'];
$tab_connexion_info['cas']['02|itslearning_02'] = array(
    'txt' => 'ENT département de l’Aisne sur ItsLearning',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'ItsLearning'
) + $tab_csv_format['itslearning'] + $tab_serveur_cas['itslearning_02'];
$tab_connexion_info['cas']['02|entlibre_picardie'] = array(
    'txt' => 'ENT Libre LÉO des lycées de Picardie',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'OPEN ENT NG'
) + $tab_csv_format['webeducation'] + $tab_serveur_cas['entlibre_picardie'];
$tab_connexion_info['cas']['02|entlibre_hauts-de-france'] = array(
    'txt' => 'ENT Libre Hauts-de-France',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'OPEN ENT NG'
) + $tab_csv_format['webeducation'] + $tab_serveur_cas['entlibre_hauts-de-france'];
$tab_connexion_info['cas']['03|itop_auvergne'] = array(
    'txt' => 'ENT Auvergne (académie de Clermont-Ferrand) Itop',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Itop'
) + $tab_csv_format['itop'] + $tab_serveur_cas['itop_auvergne'];
$tab_connexion_info['cas']['03|scolastance_auvergne'] = array(
    'txt' => 'ENT Auvergne (académie de Clermont-Ferrand) Scolastance',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'Infostance'
) + $tab_csv_format['scolastance'] + $tab_serveur_cas['scolastance_auvergne'];
$tab_connexion_info['cas']['03|kosmos_auvergne-rhone-alpes'] = array(
    'txt' => 'ENT Ma classe en Auvergne-Rhône-Alpes',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_auvergne-rhone-alpes'];
$tab_connexion_info['cas']['04|scolastance_04'] = array(
    'txt' => 'ENT département des Alpes de Haute-Provence sur Scolastance',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'Infostance'
) + $tab_csv_format['scolastance'] + $tab_serveur_cas['scolastance_04'];
$tab_connexion_info['cas']['04|itslearning_04'] = array(
    'txt' => 'ENT département des Alpes de Haute-Provence sur ItsLearning',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'ItsLearning'
) + $tab_csv_format['itslearning'] + $tab_serveur_cas['itslearning_04'];
$tab_connexion_info['cas']['04|entlibre_ent04'] = array(
    'txt' => 'ENT Libre collèges des Alpes de Haute-Provence',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'OPEN ENT NG'
) + $tab_csv_format['webeducation'] + $tab_serveur_cas['entlibre_ent04'];
$tab_connexion_info['cas']['05|envole_provence'] = array(
    'txt' => 'ENT ProVENCE (académie d’Aix-Marseille)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'DSI Aix-Marseille'
) + $tab_csv_format['provence'] + $tab_serveur_cas['provence'];
$tab_connexion_info['cas']['06|itop_agora06'] = array(
    'txt' => 'ENT Agora 06 (collèges des Alpes-Maritimes, Itop)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Itop'
) + $tab_csv_format['itop'] + $tab_serveur_cas['itop_agora06'];
$tab_connexion_info['cas']['06|kosmos_agora06'] = array(
    'txt' => 'ENT Agora 06 (collèges des Alpes-Maritimes, Kosmos Skolengo)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_agora06'];
$tab_connexion_info['cas']['06|itop_nice'] = array(
    'txt' => 'ENT académie de Nice (hors Agora 06 et Olympe 83)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Itop'
) + $tab_csv_format['itop'] + $tab_serveur_cas['itop_enteduc'];
$tab_connexion_info['cas']['07|pentila_nero'] = array(
    'txt' => 'ENT Pentila Néro',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'Pentila'
) + $tab_csv_format['pentila'] + $tab_serveur_cas['pentila_nero'];
$tab_connexion_info['cas']['07|kosmos_auvergne-rhone-alpes'] = array(
    'txt' => 'ENT Ma classe en Auvergne-Rhône-Alpes',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_auvergne-rhone-alpes'];
$tab_connexion_info['cas']['08|kosmos_grand-est'] = array(
    'txt' => 'ENT Mon bureau numerique (région Grand Est)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_grand-est'];
$tab_connexion_info['cas']['08|envole_ardennes'] = array(
    'txt' => 'ENT Ard’ENT (département des Ardennes)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Envole-Scribe'
) + $tab_csv_format['perso'] + $tab_serveur_cas['envole_ardennes'];
$tab_connexion_info['cas']['08|itop_rca'] = array(
    'txt' => 'ENT lycées région Champagne-Ardenne',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'Itop'
) + $tab_csv_format['itop'] + $tab_serveur_cas['itop_rca'];
$tab_connexion_info['cas']['08|itop_oze_rca'] = array(
    'txt' => 'ENT OZE lycées région Champagne-Ardenne',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'Itop'
) + $tab_csv_format['itop'] + $tab_serveur_cas['itop_oze_rca'];
$tab_connexion_info['cas']['09|kosmos_entmip'] = array(
    'txt' => 'ENT Midi-Pyrénées (académie de Toulouse)',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_entmip'];
$tab_connexion_info['cas']['09|kosmos_occitanie'] = array(
    'txt' => 'ENT Occitanie (Kosmos, région Occitanie)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_occitanie'];
$tab_connexion_info['cas']['10|kosmos_grand-est'] = array(
    'txt' => 'ENT Mon bureau numerique (région Grand Est)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_grand-est'];
$tab_connexion_info['cas']['10|itop_rca'] = array(
    'txt' => 'ENT lycées région Champagne-Ardenne',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'Itop'
) + $tab_csv_format['itop'] + $tab_serveur_cas['itop_rca'];
$tab_connexion_info['cas']['10|itop_oze_rca'] = array(
    'txt' => 'ENT OZE lycées région Champagne-Ardenne',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'Itop'
) + $tab_csv_format['itop'] + $tab_serveur_cas['itop_oze_rca'];
$tab_connexion_info['cas']['11|esup_montpellier'] = array(
    'txt' => 'ENT Languedoc-Roussillon (académie de Montpellier)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'ESUP Portail'
) + $tab_csv_format['esup'] + $tab_serveur_cas['esup_montpellier'];
$tab_connexion_info['cas']['11|kosmos_occitanie'] = array(
    'txt' => 'ENT Occitanie (Kosmos, région Occitanie)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_occitanie'];
$tab_connexion_info['cas']['12|kosmos_entmip'] = array(
    'txt' => 'ENT Midi-Pyrénées (académie de Toulouse)',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_entmip'];
$tab_connexion_info['cas']['12|kosmos_occitanie'] = array(
    'txt' => 'ENT Occitanie (Kosmos, région Occitanie)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_occitanie'];
$tab_connexion_info['cas']['13|envole_provence'] = array(
    'txt' => 'ENT ProVENCE (académie d’Aix-Marseille)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'DSI Aix-Marseille'
) + $tab_csv_format['provence'] + $tab_serveur_cas['provence'];
$tab_connexion_info['cas']['14|entlibre_normandie'] = array(
    'txt' => 'ENT L’Educ de Normandie (région Normandie, NÉO)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'OPEN ENT NG'
) + $tab_csv_format['webeducation'] + $tab_serveur_cas['entlibre_normandie'];
$tab_connexion_info['cas']['14|itslearning_caen'] = array(
    'txt' => 'ENT L’Educ de Normandie (académie de Caen, ItsLearning)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'ItsLearning'
) + $tab_csv_format['itslearning'] + $tab_serveur_cas['itslearning_caen'];
$tab_connexion_info['cas']['14|itslearning_calvados_catho'] = array(
    'txt' => 'ENT de l’enseignement Catholique du Calvados',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'ItsLearning'
) + $tab_csv_format['itslearning'] + $tab_serveur_cas['itslearning_calvados_catho'];
$tab_connexion_info['cas']['15|itop_auvergne'] = array(
    'txt' => 'ENT Auvergne (académie de Clermont-Ferrand) Itop',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Itop'
) + $tab_csv_format['itop'] + $tab_serveur_cas['itop_auvergne'];
$tab_connexion_info['cas']['15|scolastance_auvergne'] = array(
    'txt' => 'ENT Auvergne (académie de Clermont-Ferrand) Scolastance',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'Infostance'
) + $tab_csv_format['scolastance'] + $tab_serveur_cas['scolastance_auvergne'];
$tab_connexion_info['cas']['15|kosmos_auvergne-rhone-alpes'] = array(
    'txt' => 'ENT Ma classe en Auvergne-Rhône-Alpes',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_auvergne-rhone-alpes'];
$tab_connexion_info['cas']['16|icart_poitiers'] = array(
    'txt' => 'ENT i-Cart! (académie de Poitiers)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Envole-Scribe'
) + $tab_csv_format['perso'] + $tab_serveur_cas['icart_poitiers'];
$tab_connexion_info['cas']['16|entlibre_lol'] = array(
    'txt' => 'ENT Libre LOL (académie de Poitiers)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'OPEN ENT NG'
) + $tab_csv_format['webeducation'] + $tab_serveur_cas['entlibre_poitiers'];
$tab_connexion_info['cas']['16|entlibre_nouvelle-aquitaine'] = array(
    'txt' => 'ENT Lycée connecté (région Nouvelle-Aquitaine hors Bordeaux)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'OPEN ENT NG'
) + $tab_csv_format['webeducation'] + $tab_serveur_cas['entlibre_nouvelle-aquitaine'];
$tab_connexion_info['cas']['17|icart_poitiers'] = array(
    'txt' => 'ENT i-Cart! (académie de Poitiers)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Envole-Scribe'
) + $tab_csv_format['perso'] + $tab_serveur_cas['icart_poitiers'];
$tab_connexion_info['cas']['17|entlibre_lol'] = array(
    'txt' => 'ENT Libre LOL (académie de Poitiers)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'OPEN ENT NG'
) + $tab_csv_format['webeducation'] + $tab_serveur_cas['entlibre_poitiers'];
$tab_connexion_info['cas']['17|entlibre_nouvelle-aquitaine'] = array(
    'txt' => 'ENT Lycée connecté (région Nouvelle-Aquitaine hors Bordeaux)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'OPEN ENT NG'
) + $tab_csv_format['webeducation'] + $tab_serveur_cas['entlibre_nouvelle-aquitaine'];
$tab_connexion_info['cas']['18|netocentre_orleanstours'] = array(
    'txt' => 'ENT NetO’Centre (lycées de l’académie d’Orléans-Tours)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'GIP'
) + $tab_csv_format['netocentre'] + $tab_serveur_cas['netocentre_orleanstours'];
$tab_connexion_info['cas']['18|envole_orleans-tours_18'] = array(
    'txt' => 'ENT Envole (département du Cher)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Envole-Scribe'
) + $tab_csv_format['envole_orleans-tours'] + $tab_serveur_cas['envole_orleans-tours_18'];
$tab_connexion_info['cas']['18|netocentre_chercan'] = array(
    'txt' => 'ENT CHER CArtable Numérique (collèges du Cher)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'GIP'
) + $tab_csv_format['netocentre'] + $tab_serveur_cas['netocentre_chercan'];
$tab_connexion_info['cas']['19|entlibre_nouvelle-aquitaine'] = array(
    'txt' => 'ENT Lycée connecté (région Nouvelle-Aquitaine hors Bordeaux)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'OPEN ENT NG'
) + $tab_csv_format['webeducation'] + $tab_serveur_cas['entlibre_nouvelle-aquitaine'];
$tab_connexion_info['cas']['2A|itslearning_corse'] = array(
    'txt' => 'ENT LEIA (académie de Corse)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'ItsLearning'
) + $tab_csv_format['itslearning'] + $tab_serveur_cas['itslearning_corse'];
$tab_connexion_info['cas']['2B|itslearning_corse'] = array(
    'txt' => 'ENT LEIA (académie de Corse)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'ItsLearning'
) + $tab_csv_format['itslearning'] + $tab_serveur_cas['itslearning_corse'];
$tab_connexion_info['cas']['21|liberscol_dijon'] = array(
    'txt' => 'ENT Liberscol (académie de Dijon)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Tetra Informatique'
) + $tab_csv_format['liberscol'] + $tab_serveur_cas['liberscol'];
$tab_connexion_info['cas']['21|cloe_dijon'] = array(
    'txt' => 'ENT CLOE (académie de Dijon)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Envole-Scribe'
) + $tab_csv_format['perso'] + $tab_serveur_cas['cloe_dijon'];
$tab_connexion_info['cas']['21|kosmos_eclat-bfc'] = array(
    'txt' => 'ENT ECLAT-BFC (Kosmos, région Bourgogne-Franche-Comté)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_eclat-bfc'];
$tab_connexion_info['cas']['22|toutatice'] = array(
    'txt' => 'ENT Toutatice (académie de Rennes)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'SERIA Rennes'
) + $tab_csv_format['toutatice'] + $tab_serveur_cas['toutatice'];
$tab_connexion_info['cas']['23|kosmos_creuse'] = array(
    'txt' => 'ENT Creuse - K-d’école',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_creuse'];
$tab_connexion_info['cas']['23|logica_elie'] = array(
    'txt' => 'ENT Elie (collèges de la Creuse)',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'OPEN ENT NG'
) + $tab_csv_format['logica'] + $tab_serveur_cas['logica_elie'];
$tab_connexion_info['cas']['23|entlibre_nouvelle-aquitaine'] = array(
    'txt' => 'ENT Lycée connecté (région Nouvelle-Aquitaine hors Bordeaux)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'OPEN ENT NG'
) + $tab_csv_format['webeducation'] + $tab_serveur_cas['entlibre_nouvelle-aquitaine'];
$tab_connexion_info['cas']['25|enoe_besancon'] = array(
    'txt' => 'ENT ENOE (académie de Besançon)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Envole-Scribe'
) + $tab_csv_format['perso'] + $tab_serveur_cas['enoe_besancon'];
$tab_connexion_info['cas']['25|kosmos_eclat-bfc'] = array(
    'txt' => 'ENT ECLAT-BFC (Kosmos, région Bourgogne-Franche-Comté)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_eclat-bfc'];
$tab_connexion_info['cas']['26|pentila_nero'] = array(
    'txt' => 'ENT Pentila Néro',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'Pentila'
) + $tab_csv_format['pentila'] + $tab_serveur_cas['pentila_nero'];
$tab_connexion_info['cas']['26|kosmos_auvergne-rhone-alpes'] = array(
    'txt' => 'ENT Ma classe en Auvergne-Rhône-Alpes',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_auvergne-rhone-alpes'];
$tab_connexion_info['cas']['27|itop_eure'] = array(
    'txt' => 'ENT département de l’Eure - Itop',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Itop'
) + $tab_csv_format['itop'] + $tab_serveur_cas['itop_enteduc'];
$tab_connexion_info['cas']['27|kosmos_eure'] = array(
    'txt' => 'ENT département de l’Eure - Kosmos',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_eure'];
$tab_connexion_info['cas']['27|entlibre_normandie'] = array(
    'txt' => 'ENT L’Educ de Normandie (région Normandie, NÉO)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'OPEN ENT NG'
) + $tab_csv_format['webeducation'] + $tab_serveur_cas['entlibre_normandie'];
$tab_connexion_info['cas']['27|lea_hautenormandie'] = array(
    'txt' => 'ENT Lycées Échanger Apprendre (Haute-Normandie)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Pentila'
) + $tab_csv_format['pentila'] + $tab_serveur_cas['lea_hautenormandie'];
$tab_connexion_info['cas']['28|netocentre_orleanstours'] = array(
    'txt' => 'ENT NetO’Centre (lycées de l’académie d’Orléans-Tours)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'GIP'
) + $tab_csv_format['netocentre'] + $tab_serveur_cas['netocentre_orleanstours'];
$tab_connexion_info['cas']['28|netocentre_eureliens_28'] = array(
    'txt' => 'ENT Collèges Euréliens (Net O’Centre, d’Eure-et-Loir)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'GIP'
) + $tab_csv_format['netocentre'] + $tab_serveur_cas['netocentre_eureliens_28'];
$tab_connexion_info['cas']['28|envole_orleans-tours_28'] = array(
    'txt' => 'ENT Envole (département d’Eure-et-Loir)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Envole-Scribe'
) + $tab_csv_format['envole_orleans-tours'] + $tab_serveur_cas['envole_orleans-tours_28'];
$tab_connexion_info['cas']['29|toutatice'] = array(
    'txt' => 'ENT Toutatice (académie de Rennes)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'SERIA Rennes'
) + $tab_csv_format['toutatice'] + $tab_serveur_cas['toutatice'];
$tab_connexion_info['cas']['30|esup_montpellier'] = array(
    'txt' => 'ENT Languedoc-Roussillon (académie de Montpellier)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'ESUP Portail'
) + $tab_csv_format['esup'] + $tab_serveur_cas['esup_montpellier'];
$tab_connexion_info['cas']['30|kosmos_occitanie'] = array(
    'txt' => 'ENT Occitanie (Kosmos, région Occitanie)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_occitanie'];
$tab_connexion_info['cas']['31|kosmos_entmip'] = array(
    'txt' => 'ENT Midi-Pyrénées (académie de Toulouse)',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_entmip'];
$tab_connexion_info['cas']['31|kosmos_ecollege31'] = array(
    'txt' => 'ENT eCollège 31 (département de Haute-Garonne)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_ecollege31'];
$tab_connexion_info['cas']['31|kosmos_occitanie'] = array(
    'txt' => 'ENT Occitanie (Kosmos, région Occitanie)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_occitanie'];
$tab_connexion_info['cas']['32|kosmos_entmip'] = array(
    'txt' => 'ENT Midi-Pyrénées (académie de Toulouse)',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_entmip'];
$tab_connexion_info['cas']['32|kosmos_occitanie'] = array(
    'txt' => 'ENT Occitanie (Kosmos, région Occitanie)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_occitanie'];
$tab_connexion_info['cas']['34|esup_montpellier'] = array(
    'txt' => 'ENT Languedoc-Roussillon (académie de Montpellier)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'ESUP Portail'
) + $tab_csv_format['esup'] + $tab_serveur_cas['esup_montpellier'];
$tab_connexion_info['cas']['34|kosmos_occitanie'] = array(
    'txt' => 'ENT Occitanie (Kosmos, région Occitanie)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_occitanie'];
$tab_connexion_info['cas']['35|toutatice'] = array(
    'txt' => 'ENT Toutatice (académie de Rennes)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'SERIA Rennes'
) + $tab_csv_format['toutatice'] + $tab_serveur_cas['toutatice'];
$tab_connexion_info['cas']['36|netocentre_orleanstours'] = array(
    'txt' => 'ENT NetO’Centre (lycées de l’académie d’Orléans-Tours)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'GIP'
) + $tab_csv_format['netocentre'] + $tab_serveur_cas['netocentre_orleanstours'];
$tab_connexion_info['cas']['36|netocentre_mon-e-college_36'] = array(
    'txt' => 'ENT Mon e-collège (Net O’Centre, collèges de l’Indre)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'GIP'
) + $tab_csv_format['netocentre'] + $tab_serveur_cas['netocentre_mon-e-college_36'];
$tab_connexion_info['cas']['36|envole_orleans-tours_36'] = array(
    'txt' => 'ENT Envole (département de l’Indre)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Envole-Scribe'
) + $tab_csv_format['envole_orleans-tours'] + $tab_serveur_cas['envole_orleans-tours_36'];
$tab_connexion_info['cas']['37|netocentre_orleanstours'] = array(
    'txt' => 'ENT NetO’Centre (lycées de l’académie d’Orléans-Tours)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'GIP'
) + $tab_csv_format['netocentre'] + $tab_serveur_cas['netocentre_orleanstours'];
$tab_connexion_info['cas']['37|netocentre_touraine-eschool'] = array(
    'txt' => 'ENT Touraine e-school (collèges d’Indre-et-Loire)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'GIP'
) + $tab_csv_format['netocentre'] + $tab_serveur_cas['netocentre_touraine-eschool'];
$tab_connexion_info['cas']['38|itop_isere'] = array(
    'txt' => 'ENT département de l’Isère',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Itop'
) + $tab_csv_format['itop'] + $tab_serveur_cas['itop_isere'];
$tab_connexion_info['cas']['38|itop_oze_isere'] = array(
    'txt' => 'ENT OZE département de l’Isère',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Itop'
) + $tab_csv_format['itop'] + $tab_serveur_cas['itop_oze_isere'];
$tab_connexion_info['cas']['38|kosmos_auvergne-rhone-alpes'] = array(
    'txt' => 'ENT Ma classe en Auvergne-Rhône-Alpes',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_auvergne-rhone-alpes'];
$tab_connexion_info['cas']['39|enoe_besancon'] = array(
    'txt' => 'ENT ENOE (académie de Besançon)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Envole-Scribe'
) + $tab_csv_format['perso'] + $tab_serveur_cas['enoe_besancon'];
$tab_connexion_info['cas']['39|kosmos_eclat-bfc'] = array(
    'txt' => 'ENT ECLAT-BFC (Kosmos, région Bourgogne-Franche-Comté)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_eclat-bfc'];
$tab_connexion_info['cas']['41|netocentre_orleanstours'] = array(
    'txt' => 'ENT NetO’Centre (lycées de l’académie d’Orléans-Tours)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'GIP'
) + $tab_csv_format['netocentre'] + $tab_serveur_cas['netocentre_orleanstours'];
$tab_connexion_info['cas']['41|netocentre_colleges41'] = array(
    'txt' => 'ENT Collèges41 (collèges du Loir-et-Cher)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'GIP'
) + $tab_csv_format['netocentre'] + $tab_serveur_cas['netocentre_colleges41'];
$tab_connexion_info['cas']['41|envole_orleans-tours_41'] = array(
    'txt' => 'ENT Envole (département du Loir-et-Cher)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Envole-Scribe'
) + $tab_csv_format['envole_orleans-tours'] + $tab_serveur_cas['envole_orleans-tours_41'];
$tab_connexion_info['cas']['42|kosmos_cybercolleges42'] = array(
    'txt' => 'ENT Cybercollèges 42 (département de la Loire)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_cybercolleges42'];
$tab_connexion_info['cas']['42|kosmos_auvergne-rhone-alpes'] = array(
    'txt' => 'ENT Ma classe en Auvergne-Rhône-Alpes',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_auvergne-rhone-alpes'];
$tab_connexion_info['cas']['43|itop_auvergne'] = array(
    'txt' => 'ENT Auvergne (académie de Clermont-Ferrand) Itop',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Itop'
) + $tab_csv_format['itop'] + $tab_serveur_cas['itop_auvergne'];
$tab_connexion_info['cas']['43|scolastance_auvergne'] = array(
    'txt' => 'ENT Auvergne (académie de Clermont-Ferrand) Scolastance',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'Infostance'
) + $tab_csv_format['scolastance'] + $tab_serveur_cas['scolastance_auvergne'];
$tab_connexion_info['cas']['43|kosmos_auvergne-rhone-alpes'] = array(
    'txt' => 'ENT Ma classe en Auvergne-Rhône-Alpes',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_auvergne-rhone-alpes'];
$tab_connexion_info['cas']['44|kosmos_elyco'] = array(
    'txt' => 'ENT e-lyco (académie de Nantes) Kosmos',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_elyco'];
$tab_connexion_info['cas']['44|itslearning_elyco'] = array(
    'txt' => 'ENT e-lyco (académie de Nantes) ItsLearning',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'ItsLearning'
) + $tab_csv_format['itslearning'] + $tab_serveur_cas['itslearning_elyco'];
$tab_connexion_info['cas']['44|itslearning_nantes_primaire'] = array(
    'txt' => 'ENT e-primo (écoles primaires de l’académie de Nantes)',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'ItsLearning'
) + $tab_csv_format['itslearning'] + $tab_serveur_cas['itslearning_nantes_primaire'];
$tab_connexion_info['cas']['45|netocentre_orleanstours'] = array(
    'txt' => 'ENT NetO’Centre (lycées de l’académie d’Orléans-Tours)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'GIP'
) + $tab_csv_format['netocentre'] + $tab_serveur_cas['netocentre_orleanstours'];
$tab_connexion_info['cas']['45|netocentre_mon-e-college_45'] = array(
    'txt' => 'ENT Mon e-collège loirétain (Net O’Centre, collèges du Loiret)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'GIP'
) + $tab_csv_format['netocentre'] + $tab_serveur_cas['netocentre_mon-e-college_45'];
$tab_connexion_info['cas']['45|envole_orleans-tours_45'] = array(
    'txt' => 'ENT Envole (département du Loiret)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Envole-Scribe'
) + $tab_csv_format['envole_orleans-tours'] + $tab_serveur_cas['envole_orleans-tours_45'];
$tab_connexion_info['cas']['46|kosmos_entmip'] = array(
    'txt' => 'ENT Midi-Pyrénées (académie de Toulouse)',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_entmip'];
$tab_connexion_info['cas']['46|kosmos_occitanie'] = array(
    'txt' => 'ENT Occitanie (Kosmos, région Occitanie)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_occitanie'];
$tab_connexion_info['cas']['48|esup_montpellier'] = array(
    'txt' => 'ENT Languedoc-Roussillon (académie de Montpellier)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'ESUP Portail'
) + $tab_csv_format['esup'] + $tab_serveur_cas['esup_montpellier'];
$tab_connexion_info['cas']['48|kosmos_occitanie'] = array(
    'txt' => 'ENT Occitanie (Kosmos, région Occitanie)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_occitanie'];
$tab_connexion_info['cas']['49|kosmos_elyco'] = array(
    'txt' => 'ENT e-lyco (académie de Nantes) Kosmos',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_elyco'];
$tab_connexion_info['cas']['49|itslearning_elyco'] = array(
    'txt' => 'ENT e-lyco (académie de Nantes) ItsLearning',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'ItsLearning'
) + $tab_csv_format['itslearning'] + $tab_serveur_cas['itslearning_elyco'];
$tab_connexion_info['cas']['49|itslearning_nantes_primaire'] = array(
    'txt' => 'ENT e-primo (écoles primaires de l’académie de Nantes)',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'ItsLearning'
) + $tab_csv_format['itslearning'] + $tab_serveur_cas['itslearning_nantes_primaire'];
$tab_connexion_info['cas']['50|entlibre_normandie'] = array(
    'txt' => 'ENT L’Educ de Normandie (région Normandie, NÉO)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'OPEN ENT NG'
) + $tab_csv_format['webeducation'] + $tab_serveur_cas['entlibre_normandie'];
$tab_connexion_info['cas']['50|itslearning_caen'] = array(
    'txt' => 'ENT L’Educ de Normandie (académie de Caen, ItsLearning)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'ItsLearning'
) + $tab_csv_format['itslearning'] + $tab_serveur_cas['itslearning_caen'];
$tab_connexion_info['cas']['51|kosmos_grand-est'] = array(
    'txt' => 'ENT Mon bureau numerique (région Grand Est)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_grand-est'];
$tab_connexion_info['cas']['51|itop_marne'] = array(
    'txt' => 'ENT département de la Marne',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Itop'
) + $tab_csv_format['itop'] + $tab_serveur_cas['itop_marne'];
$tab_connexion_info['cas']['51|itop_oze_marne'] = array(
    'txt' => 'ENT OZE département de la Marne',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Itop'
) + $tab_csv_format['itop'] + $tab_serveur_cas['itop_oze_marne'];
$tab_connexion_info['cas']['51|itop_rca'] = array(
    'txt' => 'ENT lycées région Champagne-Ardenne',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'Itop'
) + $tab_csv_format['itop'] + $tab_serveur_cas['itop_rca'];
$tab_connexion_info['cas']['51|itop_oze_rca'] = array(
    'txt' => 'ENT OZE lycées région Champagne-Ardenne',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'Itop'
) + $tab_csv_format['itop'] + $tab_serveur_cas['itop_oze_rca'];
$tab_connexion_info['cas']['52|kosmos_grand-est'] = array(
    'txt' => 'ENT Mon bureau numerique (région Grand Est)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_grand-est'];
$tab_connexion_info['cas']['52|itslearning_52'] = array(
    'txt' => 'ENT département de Haute-Marne sur ItsLearning',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'ItsLearning'
) + $tab_csv_format['itslearning'] + $tab_serveur_cas['itslearning_52'];
$tab_connexion_info['cas']['52|itop_rca'] = array(
    'txt' => 'ENT lycées région Champagne-Ardenne',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'Itop'
) + $tab_csv_format['itop'] + $tab_serveur_cas['itop_rca'];
$tab_connexion_info['cas']['52|itop_oze_rca'] = array(
    'txt' => 'ENT OZE lycées région Champagne-Ardenne',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'Itop'
) + $tab_csv_format['itop'] + $tab_serveur_cas['itop_oze_rca'];
$tab_connexion_info['cas']['53|kosmos_elyco'] = array(
    'txt' => 'ENT e-lyco (académie de Nantes) Kosmos',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_elyco'];
$tab_connexion_info['cas']['53|itslearning_elyco'] = array(
    'txt' => 'ENT e-lyco (académie de Nantes) ItsLearning',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'ItsLearning'
) + $tab_csv_format['itslearning'] + $tab_serveur_cas['itslearning_elyco'];
$tab_connexion_info['cas']['53|itslearning_nantes_primaire'] = array(
    'txt' => 'ENT e-primo (écoles primaires de l’académie de Nantes)',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'ItsLearning'
) + $tab_csv_format['itslearning'] + $tab_serveur_cas['itslearning_nantes_primaire'];
$tab_connexion_info['cas']['54|kosmos_grand-est'] = array(
    'txt' => 'ENT Mon bureau numerique (région Grand Est)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_grand-est'];
$tab_connexion_info['cas']['54|itop_place'] = array(
    'txt' => 'ENT Place (académie de Nancy-Metz)',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'Itop'
) + $tab_csv_format['itop'] + $tab_serveur_cas['itop_place'];
$tab_connexion_info['cas']['54|itop_oze_place'] = array(
    'txt' => 'ENT OZE Place (académie de Nancy-Metz sauf Moselle)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Itop'
) + $tab_csv_format['itop'] + $tab_serveur_cas['itop_oze_place'];
$tab_connexion_info['cas']['55|kosmos_grand-est'] = array(
    'txt' => 'ENT Mon bureau numerique (région Grand Est)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_grand-est'];
$tab_connexion_info['cas']['55|itop_place'] = array(
    'txt' => 'ENT Place (académie de Nancy-Metz)',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'Itop'
) + $tab_csv_format['itop'] + $tab_serveur_cas['itop_place'];
$tab_connexion_info['cas']['55|itop_oze_place'] = array(
    'txt' => 'ENT OZE Place (académie de Nancy-Metz sauf Moselle)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Itop'
) + $tab_csv_format['itop'] + $tab_serveur_cas['itop_oze_place'];
$tab_connexion_info['cas']['56|toutatice'] = array(
    'txt' => 'ENT Toutatice (académie de Rennes)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'SERIA Rennes'
) + $tab_csv_format['toutatice'] + $tab_serveur_cas['toutatice'];
$tab_connexion_info['cas']['57|kosmos_grand-est'] = array(
    'txt' => 'ENT Mon bureau numerique (région Grand Est)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_grand-est'];
$tab_connexion_info['cas']['57|itop_place'] = array(
    'txt' => 'ENT Place (académie de Nancy-Metz)',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'Itop'
) + $tab_csv_format['itop'] + $tab_serveur_cas['itop_place'];
$tab_connexion_info['cas']['58|liberscol_dijon'] = array(
    'txt' => 'ENT Liberscol (académie de Dijon)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Tetra Informatique'
) + $tab_csv_format['liberscol'] + $tab_serveur_cas['liberscol'];
$tab_connexion_info['cas']['58|cloe_dijon'] = array(
    'txt' => 'ENT CLOE (académie de Dijon)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Envole-Scribe'
) + $tab_csv_format['perso'] + $tab_serveur_cas['cloe_dijon'];
$tab_connexion_info['cas']['58|kosmos_eclat-bfc'] = array(
    'txt' => 'ENT ECLAT-BFC (Kosmos, région Bourgogne-Franche-Comté)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_eclat-bfc'];
$tab_connexion_info['cas']['59|kosmos_savoirsnumeriques5962'] = array(
    'txt' => 'ENT Savoirs Numériques 5962 (académie de Lille)',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_savoirsnumeriques5962'];
$tab_connexion_info['cas']['59|entlibre_hauts-de-france'] = array(
    'txt' => 'ENT Libre Hauts-de-France',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'OPEN ENT NG'
) + $tab_csv_format['webeducation'] + $tab_serveur_cas['entlibre_hauts-de-france'];
$tab_connexion_info['cas']['60|itop_oise'] = array(
    'txt' => 'ENT département de l’Oise',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'Itop'
) + $tab_csv_format['itop'] + $tab_serveur_cas['itop_oise'];
$tab_connexion_info['cas']['60|itop_oze_oise'] = array(
    'txt' => 'ENT OZE département de l’Oise',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'Itop'
) + $tab_csv_format['itop'] + $tab_serveur_cas['itop_oze_2d'];
$tab_connexion_info['cas']['60|entlibre_picardie'] = array(
    'txt' => 'ENT Libre LÉO des lycées de Picardie',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'OPEN ENT NG'
) + $tab_csv_format['webeducation'] + $tab_serveur_cas['entlibre_picardie'];
$tab_connexion_info['cas']['60|entlibre_hauts-de-france'] = array(
    'txt' => 'ENT Libre Hauts-de-France',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'OPEN ENT NG'
) + $tab_csv_format['webeducation'] + $tab_serveur_cas['entlibre_hauts-de-france'];
$tab_connexion_info['cas']['61|entlibre_normandie'] = array(
    'txt' => 'ENT L’Educ de Normandie (région Normandie, NÉO)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'OPEN ENT NG'
) + $tab_csv_format['webeducation'] + $tab_serveur_cas['entlibre_normandie'];
$tab_connexion_info['cas']['61|itslearning_caen'] = array(
    'txt' => 'ENT L’Educ de Normandie (académie de Caen, ItsLearning)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'ItsLearning'
) + $tab_csv_format['itslearning'] + $tab_serveur_cas['itslearning_caen'];
$tab_connexion_info['cas']['62|kosmos_savoirsnumeriques5962'] = array(
    'txt' => 'ENT Savoirs Numériques 5962 (académie de Lille)',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_savoirsnumeriques5962'];
$tab_connexion_info['cas']['62|kosmos_savoirsnumeriques62'] = array(
    'txt' => 'ENT Savoirs Numériques 62 (collèges du Pas-de-Calais)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_savoirsnumeriques62'];
$tab_connexion_info['cas']['62|entlibre_hauts-de-france'] = array(
    'txt' => 'ENT Libre Hauts-de-France',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'OPEN ENT NG'
) + $tab_csv_format['webeducation'] + $tab_serveur_cas['entlibre_hauts-de-france'];
$tab_connexion_info['cas']['63|itop_auvergne'] = array(
    'txt' => 'ENT Auvergne (académie de Clermont-Ferrand) Itop',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Itop'
) + $tab_csv_format['itop'] + $tab_serveur_cas['itop_auvergne'];
$tab_connexion_info['cas']['63|scolastance_auvergne'] = array(
    'txt' => 'ENT Auvergne (académie de Clermont-Ferrand) Scolastance',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'Infostance'
) + $tab_csv_format['scolastance'] + $tab_serveur_cas['scolastance_auvergne'];
$tab_connexion_info['cas']['63|kosmos_auvergne-rhone-alpes'] = array(
    'txt' => 'ENT Ma classe en Auvergne-Rhône-Alpes',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_auvergne-rhone-alpes'];
$tab_connexion_info['cas']['65|kosmos_entmip'] = array(
    'txt' => 'ENT Midi-Pyrénées (académie de Toulouse)',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_entmip'];
$tab_connexion_info['cas']['65|kosmos_occitanie'] = array(
    'txt' => 'ENT Occitanie (Kosmos, région Occitanie)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_occitanie'];
$tab_connexion_info['cas']['66|esup_montpellier'] = array(
    'txt' => 'ENT Languedoc-Roussillon (académie de Montpellier)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'ESUP Portail'
) + $tab_csv_format['esup'] + $tab_serveur_cas['esup_montpellier'];
$tab_connexion_info['cas']['66|kosmos_occitanie'] = array(
    'txt' => 'ENT Occitanie (Kosmos, région Occitanie)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_occitanie'];
$tab_connexion_info['cas']['67|kosmos_grand-est'] = array(
    'txt' => 'ENT Mon bureau numerique (région Grand Est)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_grand-est'];
$tab_connexion_info['cas']['67|itop_alsace'] = array(
    'txt' => 'ENT Alsace (académie de Strasbourg) Itop',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'Itop'
) + $tab_csv_format['itop'] + $tab_serveur_cas['itop_alsace'];
$tab_connexion_info['cas']['67|itop_oze_alsace'] = array(
    'txt' => 'ENT OZE Alsace (académie de Strasbourg)',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'Itop'
) + $tab_csv_format['itop'] + $tab_serveur_cas['itop_oze_alsace'];
$tab_connexion_info['cas']['67|scolastance_alsace'] = array(
    'txt' => 'ENT Alsace (académie de Strasbourg) Scolastance',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'Infostance'
) + $tab_csv_format['scolastance'] + $tab_serveur_cas['scolastance_alsace'];
$tab_connexion_info['cas']['68|kosmos_grand-est'] = array(
    'txt' => 'ENT Mon bureau numerique (région Grand Est)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_grand-est'];
$tab_connexion_info['cas']['68|itop_alsace'] = array(
    'txt' => 'ENT Alsace (académie de Strasbourg) Itop',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'Itop'
) + $tab_csv_format['itop'] + $tab_serveur_cas['itop_alsace'];
$tab_connexion_info['cas']['68|itop_oze_alsace'] = array(
    'txt' => 'ENT OZE Alsace (académie de Strasbourg)',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'Itop'
) + $tab_csv_format['itop'] + $tab_serveur_cas['itop_oze_alsace'];
$tab_connexion_info['cas']['68|scolastance_alsace'] = array(
    'txt' => 'ENT Alsace (académie de Strasbourg) Scolastance',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'Infostance'
) + $tab_csv_format['scolastance'] + $tab_serveur_cas['scolastance_alsace'];
$tab_connexion_info['cas']['69|laclasse'] = array(
    'txt' => 'ENT laclasse.com (métropole de Lyon)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Erasme'
) + $tab_csv_format['laclasse'] + $tab_serveur_cas['laclasse'];
$tab_connexion_info['cas']['69|kosmos_auvergne-rhone-alpes'] = array(
    'txt' => 'ENT Ma classe en Auvergne-Rhône-Alpes',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_auvergne-rhone-alpes'];
$tab_connexion_info['cas']['70|enoe_besancon'] = array(
    'txt' => 'ENT ENOE (académie de Besançon)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Envole-Scribe'
) + $tab_csv_format['perso'] + $tab_serveur_cas['enoe_besancon'];
$tab_connexion_info['cas']['70|kosmos_eclat-bfc'] = array(
    'txt' => 'ENT ECLAT-BFC (Kosmos, région Bourgogne-Franche-Comté)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_eclat-bfc'];
$tab_connexion_info['cas']['71|liberscol_dijon'] = array(
    'txt' => 'ENT Liberscol (académie de Dijon)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Tetra Informatique'
) + $tab_csv_format['liberscol'] + $tab_serveur_cas['liberscol'];
$tab_connexion_info['cas']['71|cloe_dijon'] = array(
    'txt' => 'ENT CLOE (académie de Dijon)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Envole-Scribe'
) + $tab_csv_format['perso'] + $tab_serveur_cas['cloe_dijon'];
$tab_connexion_info['cas']['71|kosmos_eclat-bfc'] = array(
    'txt' => 'ENT ECLAT-BFC (Kosmos, région Bourgogne-Franche-Comté)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_eclat-bfc'];
$tab_connexion_info['cas']['72|kosmos_elyco'] = array(
    'txt' => 'ENT e-lyco (académie de Nantes) Kosmos',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_elyco'];
$tab_connexion_info['cas']['72|itslearning_elyco'] = array(
    'txt' => 'ENT e-lyco (académie de Nantes) ItsLearning',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'ItsLearning'
) + $tab_csv_format['itslearning'] + $tab_serveur_cas['itslearning_elyco'];
$tab_connexion_info['cas']['72|itslearning_nantes_primaire'] = array(
    'txt' => 'ENT e-primo (écoles primaires de l’académie de Nantes)',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'ItsLearning'
) + $tab_csv_format['itslearning'] + $tab_serveur_cas['itslearning_nantes_primaire'];
$tab_connexion_info['cas']['73|cartabledesavoie'] = array(
    'txt' => 'ENT Cartable de Savoie',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'Pentila'
) + $tab_csv_format['pentila'] + $tab_serveur_cas['pentila_cartabledesavoie'];
$tab_connexion_info['cas']['73|kosmos_auvergne-rhone-alpes'] = array(
    'txt' => 'ENT Ma classe en Auvergne-Rhône-Alpes',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_auvergne-rhone-alpes'];
$tab_connexion_info['cas']['74|pentila_nero'] = array(
    'txt' => 'ENT Pentila Néro',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'Pentila'
) + $tab_csv_format['pentila'] + $tab_serveur_cas['pentila_nero'];
$tab_connexion_info['cas']['74|kosmos_auvergne-rhone-alpes'] = array(
    'txt' => 'ENT Ma classe en Auvergne-Rhône-Alpes',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_auvergne-rhone-alpes'];
$tab_connexion_info['cas']['75|entlibre_pcn'] = array(
    'txt' => 'ENT Paris Classe Numérique',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'OPEN ENT NG'
) + $tab_csv_format['webeducation'] + $tab_serveur_cas['entlibre_pcn'];
$tab_connexion_info['cas']['75|entlibre_monlycee'] = array(
    'txt' => 'ENT MonLycée.net (lycées d’Ile de France)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'OPEN ENT NG'
) + $tab_csv_format['webeducation'] + $tab_serveur_cas['entlibre_monlycee'];
$tab_connexion_info['cas']['76|entlibre_normandie'] = array(
    'txt' => 'ENT L’Educ de Normandie (région Normandie, NÉO)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'OPEN ENT NG'
) + $tab_csv_format['webeducation'] + $tab_serveur_cas['entlibre_normandie'];
$tab_connexion_info['cas']['76|kosmos_arsene76'] = array(
    'txt' => 'ENT Arsène 76 (département de Seine-Maritime)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_arsene76'];
$tab_connexion_info['cas']['76|lea_hautenormandie'] = array(
    'txt' => 'ENT Lycées Échanger Apprendre (Haute-Normandie)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Pentila'
) + $tab_csv_format['pentila'] + $tab_serveur_cas['lea_hautenormandie'];
$tab_connexion_info['cas']['77|entlibre_ent77'] = array(
    'txt' => 'ENT département de Seine et Marne',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'OPEN ENT NG'
) + $tab_csv_format['webeducation'] + $tab_serveur_cas['entlibre_ent77'];
$tab_connexion_info['cas']['77|entlibre_monlycee'] = array(
    'txt' => 'ENT MonLycée.net (lycées d’Ile de France)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'OPEN ENT NG'
) + $tab_csv_format['webeducation'] + $tab_serveur_cas['entlibre_monlycee'];
$tab_connexion_info['cas']['77|cel_creteil'] = array(
    'txt' => 'ENT Cartable en ligne (académie de Créteil)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Envole-Scribe'
) + $tab_csv_format['perso'] + $tab_serveur_cas['cel_creteil'];
$tab_connexion_info['cas']['78|itop_yvelines'] = array(
    'txt' => 'ENT e-Collèges des Yvelines',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Itop'
) + $tab_csv_format['itop'] + $tab_serveur_cas['itop_yvelines'];
$tab_connexion_info['cas']['78|itop_oze_yvelines'] = array(
    'txt' => 'ENT OZE des collèges des Yvelines',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Itop'
) + $tab_csv_format['itop'] + $tab_serveur_cas['itop_oze_yvelines'];
$tab_connexion_info['cas']['78|entlibre_monlycee'] = array(
    'txt' => 'ENT MonLycée.net (lycées d’Ile de France)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'OPEN ENT NG'
) + $tab_csv_format['webeducation'] + $tab_serveur_cas['entlibre_monlycee'];
$tab_connexion_info['cas']['79|icart_poitiers'] = array(
    'txt' => 'ENT i-Cart! (académie de Poitiers)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Envole-Scribe'
) + $tab_csv_format['perso'] + $tab_serveur_cas['icart_poitiers'];
$tab_connexion_info['cas']['79|entlibre_lol'] = array(
    'txt' => 'ENT Libre LOL (académie de Poitiers)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'OPEN ENT NG'
) + $tab_csv_format['webeducation'] + $tab_serveur_cas['entlibre_poitiers'];
$tab_connexion_info['cas']['79|entlibre_nouvelle-aquitaine'] = array(
    'txt' => 'ENT Lycée connecté (région Nouvelle-Aquitaine hors Bordeaux)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'OPEN ENT NG'
) + $tab_csv_format['webeducation'] + $tab_serveur_cas['entlibre_nouvelle-aquitaine'];
$tab_connexion_info['cas']['80|itop_somme'] = array(
    'txt' => 'ENT département de la Somme sur Itop',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Itop'
) + $tab_csv_format['itop'] + $tab_serveur_cas['itop_enteduc'];
$tab_connexion_info['cas']['80|entlibre_somme'] = array(
    'txt' => 'ENT Libre NÉO des collèges de la Somme',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'OPEN ENT NG'
) + $tab_csv_format['webeducation'] + $tab_serveur_cas['entlibre_somme'];
$tab_connexion_info['cas']['80|entlibre_somme_ecole'] = array(
    'txt' => 'ENT Libre ONE des écoles de la Somme',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'OPEN ENT NG'
) + $tab_csv_format['webeducation'] + $tab_serveur_cas['entlibre_somme_ecole'];
$tab_connexion_info['cas']['80|entlibre_picardie'] = array(
    'txt' => 'ENT Libre LÉO des lycées de Picardie',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'OPEN ENT NG'
) + $tab_csv_format['webeducation'] + $tab_serveur_cas['entlibre_picardie'];
$tab_connexion_info['cas']['80|entlibre_hauts-de-france'] = array(
    'txt' => 'ENT Libre Hauts-de-France',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'OPEN ENT NG'
) + $tab_csv_format['webeducation'] + $tab_serveur_cas['entlibre_hauts-de-france'];
$tab_connexion_info['cas']['81|kosmos_entmip'] = array(
    'txt' => 'ENT Midi-Pyrénées (académie de Toulouse)',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_entmip'];
$tab_connexion_info['cas']['81|kosmos_occitanie'] = array(
    'txt' => 'ENT Occitanie (Kosmos, région Occitanie)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_occitanie'];
$tab_connexion_info['cas']['82|kosmos_entmip'] = array(
    'txt' => 'ENT Midi-Pyrénées (académie de Toulouse)',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_entmip'];
$tab_connexion_info['cas']['82|kosmos_occitanie'] = array(
    'txt' => 'ENT Occitanie (Kosmos, région Occitanie)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_occitanie'];
$tab_connexion_info['cas']['83|itop_olympe83'] = array(
    'txt' => 'ENT Olympe 83 (département du Var)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Itop'
) + $tab_csv_format['itop'] + $tab_serveur_cas['itop_enteduc'];
$tab_connexion_info['cas']['83|itop_nice'] = array(
    'txt' => 'ENT académie de Nice (hors Agora 06 et Olympe 83)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Itop'
) + $tab_csv_format['itop'] + $tab_serveur_cas['itop_enteduc'];
$tab_connexion_info['cas']['84|envole_provence'] = array(
    'txt' => 'ENT ProVENCE (académie d’Aix-Marseille)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'DSI Aix-Marseille'
) + $tab_csv_format['provence'] + $tab_serveur_cas['provence'];
$tab_connexion_info['cas']['84|envole_84'] = array(
    'txt' => 'ENT Portail de Services Établissements 84 (Vaucluse)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'DSI Aix-Marseille'
) + $tab_csv_format['provence'] + $tab_serveur_cas['envole_84'];
$tab_connexion_info['cas']['84|kosmos_aucollege84'] = array(
    'txt' => 'ENT @ucollège84 (collèges du Vaucluse, Kosmos)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_aucollege84'];
$tab_connexion_info['cas']['85|kosmos_elyco'] = array(
    'txt' => 'ENT e-lyco (académie de Nantes) Kosmos',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_elyco'];
$tab_connexion_info['cas']['85|itslearning_elyco'] = array(
    'txt' => 'ENT e-lyco (académie de Nantes) ItsLearning',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'ItsLearning'
) + $tab_csv_format['itslearning'] + $tab_serveur_cas['itslearning_elyco'];
$tab_connexion_info['cas']['85|itslearning_nantes_primaire'] = array(
    'txt' => 'ENT e-primo (écoles primaires de l’académie de Nantes)',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'ItsLearning'
) + $tab_csv_format['itslearning'] + $tab_serveur_cas['itslearning_nantes_primaire'];
$tab_connexion_info['cas']['86|icart_poitiers'] = array(
    'txt' => 'ENT i-Cart! (académie de Poitiers)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Envole-Scribe'
) + $tab_csv_format['perso'] + $tab_serveur_cas['icart_poitiers'];
$tab_connexion_info['cas']['86|entlibre_lol'] = array(
    'txt' => 'ENT Libre LOL (académie de Poitiers)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'OPEN ENT NG'
) + $tab_csv_format['webeducation'] + $tab_serveur_cas['entlibre_poitiers'];
$tab_connexion_info['cas']['86|entlibre_nouvelle-aquitaine'] = array(
    'txt' => 'ENT Lycée connecté (région Nouvelle-Aquitaine hors Bordeaux)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'OPEN ENT NG'
) + $tab_csv_format['webeducation'] + $tab_serveur_cas['entlibre_nouvelle-aquitaine'];
$tab_connexion_info['cas']['87|entlibre_nouvelle-aquitaine'] = array(
    'txt' => 'ENT Lycée connecté (région Nouvelle-Aquitaine hors Bordeaux)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'OPEN ENT NG'
) + $tab_csv_format['webeducation'] + $tab_serveur_cas['entlibre_nouvelle-aquitaine'];
$tab_connexion_info['cas']['88|kosmos_grand-est'] = array(
    'txt' => 'ENT Mon bureau numerique (région Grand Est)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_grand-est'];
$tab_connexion_info['cas']['88|itop_place'] = array(
    'txt' => 'ENT Place (académie de Nancy-Metz)',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'Itop'
) + $tab_csv_format['itop'] + $tab_serveur_cas['itop_place'];
$tab_connexion_info['cas']['88|itop_oze_place'] = array(
    'txt' => 'ENT OZE Place (académie de Nancy-Metz sauf Moselle)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Itop'
) + $tab_csv_format['itop'] + $tab_serveur_cas['itop_oze_place'];
$tab_connexion_info['cas']['89|liberscol_dijon'] = array(
    'txt' => 'ENT Liberscol (académie de Dijon)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Tetra Informatique'
) + $tab_csv_format['liberscol'] + $tab_serveur_cas['liberscol'];
$tab_connexion_info['cas']['89|cloe_dijon'] = array(
    'txt' => 'ENT CLOE (académie de Dijon)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Envole-Scribe'
) + $tab_csv_format['perso'] + $tab_serveur_cas['cloe_dijon'];
$tab_connexion_info['cas']['89|kosmos_eclat-bfc'] = array(
    'txt' => 'ENT ECLAT-BFC (Kosmos, région Bourgogne-Franche-Comté)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_eclat-bfc'];
$tab_connexion_info['cas']['90|itslearning_90'] = array(
    'txt' => 'ENT Territoire de Belfort sur ItsLearning',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'ItsLearning'
) + $tab_csv_format['itslearning'] + $tab_serveur_cas['itslearning_90'];
$tab_connexion_info['cas']['90|itop_90'] = array(
    'txt' => 'ENT Territoire de Belfort sur Itop',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Itop'
) + $tab_csv_format['itop'] + $tab_serveur_cas['itop_enteduc'];
$tab_connexion_info['cas']['90|itop_oze_90'] = array(
    'txt' => 'ENT OZE Territoire de Belfort',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Itop'
) + $tab_csv_format['itop'] + $tab_serveur_cas['itop_oze_ent90'];
$tab_connexion_info['cas']['90|enoe_besancon'] = array(
    'txt' => 'ENT ENOE (académie de Besançon)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Envole-Scribe'
) + $tab_csv_format['perso'] + $tab_serveur_cas['enoe_besancon'];
$tab_connexion_info['cas']['90|kosmos_eclat-bfc'] = array(
    'txt' => 'ENT ECLAT-BFC (Kosmos, région Bourgogne-Franche-Comté)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_eclat-bfc'];
$tab_connexion_info['cas']['91|entlibre_essonne'] = array(
    'txt' => 'ENT Libre des collèges de l’Essonne',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'OPEN ENT NG'
) + $tab_csv_format['webeducation'] + $tab_serveur_cas['entlibre_essonne'];
$tab_connexion_info['cas']['91|entlibre_monlycee'] = array(
    'txt' => 'ENT MonLycée.net (lycées d’Ile de France)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'OPEN ENT NG'
) + $tab_csv_format['webeducation'] + $tab_serveur_cas['entlibre_monlycee'];
$tab_connexion_info['cas']['92|itop_enc92'] = array(
    'txt' => 'ENT collèges des Hauts-de-Seine',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Itop'
) + $tab_csv_format['itop'] + $tab_serveur_cas['itop_enc92'];
$tab_connexion_info['cas']['92|itop_oze_enc92'] = array(
    'txt' => 'ENT OZE collèges des Hauts-de-Seine',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Itop'
) + $tab_csv_format['itop'] + $tab_serveur_cas['itop_oze_enc92'];
$tab_connexion_info['cas']['92|entlibre_monlycee'] = array(
    'txt' => 'ENT MonLycée.net (lycées d’Ile de France)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'OPEN ENT NG'
) + $tab_csv_format['webeducation'] + $tab_serveur_cas['entlibre_monlycee'];
$tab_connexion_info['cas']['93|itslearning_93'] = array(
    'txt' => 'ENT Webcollege ItsLearning (collèges de Seine-Saint-Denis)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'ItsLearning'
) + $tab_csv_format['itslearning'] + $tab_serveur_cas['itslearning_93'];
$tab_connexion_info['cas']['93|kosmos_monecollege93'] = array(
    'txt' => 'ENT Mon e-collège (collèges de Seine-Saint-Denis)',
    'integration_ent' => 1,
    'obsolete' => 1,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_monecollege93'];
$tab_connexion_info['cas']['93|kosmos_webcollege93'] = array(
    'txt' => 'ENT Webcollège (collèges de Seine-Saint-Denis, Kosmos Skolengo)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_webcollege93'];
$tab_connexion_info['cas']['93|entlibre_monlycee'] = array(
    'txt' => 'ENT MonLycée.net (lycées d’Ile de France)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'OPEN ENT NG'
) + $tab_csv_format['webeducation'] + $tab_serveur_cas['entlibre_monlycee'];
$tab_connexion_info['cas']['93|cel_creteil'] = array(
    'txt' => 'ENT Cartable en ligne (académie de Créteil)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Envole-Scribe'
) + $tab_csv_format['perso'] + $tab_serveur_cas['cel_creteil'];
$tab_connexion_info['cas']['94|cel_creteil'] = array(
    'txt' => 'ENT Cartable en ligne (académie de Créteil)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Envole-Scribe'
) + $tab_csv_format['perso'] + $tab_serveur_cas['cel_creteil'];
$tab_connexion_info['cas']['94|entlibre_monlycee'] = array(
    'txt' => 'ENT MonLycée.net (lycées d’Ile de France)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'OPEN ENT NG'
) + $tab_csv_format['webeducation'] + $tab_serveur_cas['entlibre_monlycee'];
$tab_connexion_info['cas']['95|itop_valdoise'] = array(
    'txt' => 'ENT Anper95 (département du Val d’Oise)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Itop'
) + $tab_csv_format['itop'] + $tab_serveur_cas['itop_valdoise'];
$tab_connexion_info['cas']['95|itop_oze_valdoise'] = array(
    'txt' => 'ENT OZE Anper95 (département du Val d’Oise)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Itop'
) + $tab_csv_format['itop'] + $tab_serveur_cas['itop_oze_valdoise'];
$tab_connexion_info['cas']['95|kosmos_moncollege95'] = array(
    'txt' => 'ENT Mon collège (collèges du département du Val d’Oise)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Kosmos'
) + $tab_csv_format['kosmos'] + $tab_serveur_cas['kosmos_moncollege95'];
$tab_connexion_info['cas']['95|entlibre_monlycee'] = array(
    'txt' => 'ENT MonLycée.net (lycées d’Ile de France)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'OPEN ENT NG'
) + $tab_csv_format['webeducation'] + $tab_serveur_cas['entlibre_monlycee'];
$tab_connexion_info['cas']['138|itop_monaco'] = array(
    'txt' => 'ENT Principauté de Monaco',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'Itop'
) + $tab_csv_format['itop'] + $tab_serveur_cas['itop_enteduc'];
$tab_connexion_info['cas']['972|entlibre_colibri'] = array(
    'txt' => 'ENT Colibri (académie de la Martinique)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'OPEN ENT NG'
) + $tab_csv_format['webeducation'] + $tab_serveur_cas['entlibre_martinique'];
$tab_connexion_info['cas']['974|ent_reunion'] = array(
    'txt' => 'ENT Métice (académie de La Réunion)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'DSI La Réunion'
) + $tab_csv_format['reunion'] + $tab_serveur_cas['lareunion'];
$tab_connexion_info['shibboleth']['24|argos'] = array(
    'txt' => 'ENT Argos / OSÉ (académie de Bordeaux)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'DSI Bordeaux'
) + $tab_csv_format[''];
$tab_connexion_info['shibboleth']['24|lyceeconnecte_bordeaux'] = array(
    'txt' => 'ENT Lycée connecté (académie de Bordeaux)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'DSI Bordeaux'
) + $tab_csv_format[''];
$tab_connexion_info['shibboleth']['33|argos'] = array(
    'txt' => 'ENT Argos / OSÉ (académie de Bordeaux)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'DSI Bordeaux'
) + $tab_csv_format[''];
$tab_connexion_info['shibboleth']['33|lyceeconnecte_bordeaux'] = array(
    'txt' => 'ENT Lycée connecté (académie de Bordeaux)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'DSI Bordeaux'
) + $tab_csv_format[''];
$tab_connexion_info['shibboleth']['40|argos'] = array(
    'txt' => 'ENT Argos / OSÉ (académie de Bordeaux)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'DSI Bordeaux'
) + $tab_csv_format[''];
$tab_connexion_info['shibboleth']['40|lyceeconnecte_bordeaux'] = array(
    'txt' => 'ENT Lycée connecté (académie de Bordeaux)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'DSI Bordeaux'
) + $tab_csv_format[''];
$tab_connexion_info['shibboleth']['47|argos'] = array(
    'txt' => 'ENT Argos / OSÉ (académie de Bordeaux)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'DSI Bordeaux'
) + $tab_csv_format[''];
$tab_connexion_info['shibboleth']['47|lyceeconnecte_bordeaux'] = array(
    'txt' => 'ENT Lycée connecté (académie de Bordeaux)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'DSI Bordeaux'
) + $tab_csv_format[''];
$tab_connexion_info['shibboleth']['64|argos64'] = array(
    'txt' => 'ENT Argos64 / OSÉ (département des Pyrénées-Atlantiques)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'DSI Bordeaux'
) + $tab_csv_format[''];
$tab_connexion_info['shibboleth']['64|lyceeconnecte_bordeaux'] = array(
    'txt' => 'ENT Lycée connecté (académie de Bordeaux)',
    'integration_ent' => 1,
    'obsolete' => 0,
    'societe' => 'DSI Bordeaux'
) + $tab_csv_format[''];

/**
 * Certains sous-tableaux ne sont plus utiles.
 * Cependant $tab_serveur_cas peut ensuite être utilisé par l’application.
 */
unset($tab_csv_format, $tab_saml_param);

/**
 * Reliquats
 *
 * Orléans-Tours http://www.ac-orleans-tours.fr/vie_numerique/ent/ https://envole-loiret.ac-orleans-tours.fr/
 * http://www.ac-orleans-tours.fr/vie_numerique/ecole_numerique_2nd_degre/ent/envole/ https://envole-indre.ac-orleans-tours.fr/
 * https://envole-cher.ac-orleans-tours.fr/
 * https://envole-loir-et-cher.ac-orleans-tours.fr/
 * https://envole-eure-et-loir.ac-orleans-tours.fr/
 *
 * https://cas.scolastance.com/cas-ent74 http://ent74.scolastance.com/etablissements.aspx
 * https://cas.scolastance.com/cas-client http://client.scolastance.com/etablissements.aspx
 * https://cas.scolastance.com/cas-demo http://demo.scolastance.com/etablissements.aspx
 */

?>
