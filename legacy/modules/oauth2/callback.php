<?php
require_once "config.inc.php";
$config = get_config();
require_once "ldap.inc.php";
require_once 'functions.inc.php';

// debug_var();
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

// If we don't have an authorization code then get one

if (! isset($_GET['code'])) {
    try {
        // Fetch the authorization URL from the provider; this returns the
        // urlAuthorize option and generates and applies any necessary parameters
        // (e.g. state).
        $authorizationUrl = $provider->getAuthorizationUrl();

        // Get the state generated for you and store it to the session.
        $_SESSION['oauth2state'] = $provider->getState();

        // Redirect the user to the authorization URL.
        header('Location: ' . $authorizationUrl);
        exit();
    } catch (\League\OAuth2\Client\Provider\Exception\IdentityProviderException $e) {
        exit("erreur de connexion Oauth2 sur l'ent : " . $e->getMessage());
    }

    // Check given state against previously stored one to mitigate CSRF attack
} elseif (empty($_GET['state']) || (isset($_SESSION['oauth2state']) && $_GET['state'] !== $_SESSION['oauth2state'])) {

    echo "state : " . $_GET['state'] . "<br>";
    echo "<pre>" . print_r($_SESSION, true) . "</pre>";
    unset($_SESSION['oauth2state']);
    exit('Invalid state');
} else {

    try {
        $time = time();
        // $existingAccessToken = apcu_fetch($_GET['state']);

        // $existingAccessToken = $_SESSION['accesstoken'] ?? false;
        if (! empty($existingAccessToken) && $existingAccessToken->hasExpired()) {
            $accessToken = $provider->getAccessToken('refresh_token', [
                'refresh_token' => $existingAccessToken->getRefreshToken()
            ]);
        } else {
            // Try to get an access token using the authorization code grant.
            $accessToken = $provider->getAccessToken('authorization_code', [
                'code' => $_GET['code']
            ]);
        }
        apcu_store($_GET['state'], $accessToken);
        $time = time() - $time;
        $message = "getAccessToken: " . $time . "s\n";
        error_log($message, 3, "/tmp/profil.log");
        // We have an access token, which we may use in authenticated
        // requests against the service provider's API.
        // echo $accessToken->getToken() . "\n";
        // echo $accessToken->getRefreshToken() . "\n";
        // echo $accessToken->getExpires() . "\n";
        // echo ($accessToken->hasExpired() ? 'expired' : 'not expired') . "\n";

        // Using the access token, we may look up details about the
        // resource owner.
        $time = time();
        $resourceOwner = $provider->getResourceOwner($accessToken);
        $userinfo = $resourceOwner->toArray();
        $time = time() - $time;
        // $message = "<pre>" . print_r($userinfo, true) . "</pre>";
        // error_log($message, 3, "/tmp/profil.log");
        $time = time();
        // le login peut-il être différent de celui de l'ent ?
        // OUI ! incohérence entre userinfo, et directory, qui ne renvoient pas la même valeur. Du coup on est obligé d'aller chercgher dans l'ad
        $filter = "(&(objectclass=user)(|(title=" . $userinfo['externalId'] . ")(cn=" . $userinfo['login'] . ")(userprincipalname=" . $userinfo['login'] . "@" . $config['domain'] . ")))";
        $user = search_ad($config, $filter, "filter", $config['dn']['people']);
        if (! isset($user[0]['cn'])) {
            die("erreur de récupération de l'utilisateur" . print_r($userinfo));
        }
        $time = time() - $time;
        $message = "search_ad: " . $time . "s\n";
        error_log($message, 3, "/tmp/profil.log");

        $_SESSION['login_ent'] = $userinfo['login'];
        // $_SESSION['login'] = $userinfo['login'];
        $_SESSION['login'] = $user[0]['cn'];
        $_SESSION['auth'] = "ent";
        // }
        $_SESSION['accesstoken'] = $accessToken;
        $_SESSION['hasPw'] = $userinfo['hasPw'] ?? NULL;
        ;
        $_SESSION['forceChangePassword'] = $userinfo['forceChangePassword'] ?? NULL;
        // header("Location:" . $config['se4_url']);
        header("Location:../index.html");
        exit();

        // } catch (\League\OAuth2\Client\Provider\Exception\IdentityProviderException | Exception $e) {
    } catch (Exception $e) {
        // Failed to get the access token or user details.
        $time = time() - $time;
        $message = urldecode($provider->getAuthorizationUrl()) . " : " . $e->getMessage() . " " . $time . "s\n";
        error_log($message, 3, "/tmp/profil.log");

        if ($e->getMessage() == "quota_overflow" || $e->getMessage() == "Invalid response received from Authorization Server. Expected JSON.") {
            apcu_store('ent_status', false);
            header("Location:../index.html");
            exit();
        }

        exit("erreur de connexion Oauth2 sur l'ent url : " . urldecode($provider->getAuthorizationUrl()) . " :<br> " . $e->getMessage());
    }
}
exit();
?>
