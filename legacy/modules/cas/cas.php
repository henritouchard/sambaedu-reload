<?php
/**
 *   Example for a simple cas 2.0 client
 *
 * PHP Version 7
 *
 * @file     example_simple.php
 * @category Authentication
 * @package  PhpCAS
 * @author   Joachim Fritschi <jfritschi@freenet.de>
 * @author   Adam Franco <afranco@middlebury.edu>
 * @license  http://www.apache.org/licenses/LICENSE-2.0  Apache License 2.0
 * @link     https://wiki.jasig.org/display/CASC/phpCAS
 */

// Load the settings from the central config file
// require_once 'config.php';
// Load the CAS lib
// require_once $phpcas_path . '/CAS.php';
require_once ("config.inc.php");
$config = get_config();
require_once ("ldap.inc.php");
require_once ("functions.inc.php");
require_once (dirname(__FILE__) . '/../vendor/autoload.php');
$out = "";
$phpcas = new phpCAS();
// Enable debugging
$phpcas->setLogger();
// Enable verbose error messages. Disable in production!
$phpcas->setVerbose(true);
// Initialize phpCAS
$config['cas_base'] = $config['cas_base'] ?? "";
$config['cas_port'] = (int) $config['cas_port'] ?? 443;
if (isset($config['etab_ou']) && $config['etab_ou'] != 0) {
    $phpcas->client(CAS_VERSION_2_0, $config['cas_url'], $config['cas_port'], $config['cas_base'], $config['se4_url'], true);
    $phpcas->allowProxyChain(new CAS_ProxyChain(array(
        '|^' . $config['se4_url'] . '/central/.*\.php$|',
        '|^' . $config['se4_url'] . '/[0-9]{7}[a-z]/.*\.php$|'
    )));
} else {
    $phpcas->client(CAS_VERSION_2_0, $config['cas_url'], $config['cas_port'], $config['cas_base'], $config['se4_url'], true);
    // $phpcas->proxy(CAS_VERSION_2_0, $config['cas_url'], $config['cas_port'], $config['cas_base'], $config['se4_url'], true);
}

// For production use set the CA certificate that is the issuer of the cert
// on the CAS server and uncomment the line below
// phpCAS::setCasServerCACert("/etc/sambaedu/idf.pem");

// For quick testing you can disable SSL validation of the CAS server.
// THIS SETTING IS NOT RECOMMENDED FOR PRODUCTION.
// VALIDATING THE CAS SERVER IS CRUCIAL TO THE SECURITY OF THE CAS PROTOCOL!
$phpcas->setNoCasServerValidation();

// force CAS authentication
$phpcas->forceAuthentication();

// at this step, the user has been authenticated by the CAS server
// and the user's login name can be read with phpCAS::getUser().

// logout if desired
if (isset($_REQUEST['logout'])) {
    $phpcas->logout();
}
// $out= $phpcas->getUser();
// for this test, simply print that the authentication was successfull

$user = search_user($config, $phpcas->getUser());
if ($user['etab'] != 0) {
    $p = "/" . $user['etab'];
} else {
    $p = "";
}
// $out .= "<pre>" . print_r($phpcas->getAttributes(), true) . "</pre>";
$_SESSION['login'] = $user['cn'];
$_SESSION['auth'] = "ent";
if (have_right($config, SE_ADMIN, $user['cn'])) {
    header("Location:" . $config['se4_url'] . $p . "/admin");
} else {
    header("Location:" . $config['se4_url'] . $p . "/user");
}

// header("Location: ../index.html");

echo $out;

?>
