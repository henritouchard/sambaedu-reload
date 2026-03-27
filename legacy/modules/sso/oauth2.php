<?php
require_once "config.inc.php";
$config = get_config();
require_once (dirname(__FILE__) . '/../vendor/autoload.php');
$opt = [
    'clientId' => $config['openent_oauth2_id'], // The client ID assigned to you by the provider
    'clientSecret' => $config['openent_oauth2_pass'], // The client password assigned to you by the provider
    'redirectUri' => $config['openent_oauth2_redirect_uri'],
    'urlAuthorize' => $config['openent_uri'] . $config['openent_oauth2_auth'],
    'urlAccessToken' => $config['openent_uri'] . $config['openent_oauth2_token'],
    'urlResourceOwnerDetails' => $config['openent_uri'] . $config['openent_oauth2_userinfo'],
    'scopes' => [
        'userinfo'
    ]
];
set_oauth_proxy($config, $opt);
$provider = new \League\OAuth2\Client\Provider\GenericProvider($opt);
// Fetch the authorization URL from the provider; this returns the
// urlAuthorize option and generates and applies any necessary parameters
// (e.g. state).
$authorizationUrl = $provider->getAuthorizationUrl();

// Get the state generated for you and store it to the session.
$_SESSION['oauth2state'] = $provider->getState();

// Redirect the user to the authorization URL.
header('Location: ' . $authorizationUrl);
exit();

?>