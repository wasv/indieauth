<?php

require __DIR__ . '/vendor/autoload.php';

session_start([
        'use_only_cookies' => true,
        'cookie_secure' => true,
        'cookie_lifetime' => 14 * 24  *3600,
        'gc_maxlifetime' => 14 * 24 * 3600,
]);

// Change to fit webserver configuration.
IndieAuth\Client::$clientID = 'https://example.org/login/';
IndieAuth\Client::$redirectURL = 'https://example.org/login/';

if(!isset($_SESSION['indieauth_state'])) {
    if( isset($_POST['me'])):
            list($authorizationURL, $error) = IndieAuth\Client::begin($_POST['me']);
            $_SESSION['redirect_to'] = $_POST['url'];
            if($error) {
                    echo "<p>Error: ".$error['error']."</p>";
                    echo "<p>".$error['error_description']."</p>";
            } else {
                    // Redirect the user to their authorization endpoint
                    header('Location: '.$authorizationURL);
            }
    else:
    ?>
    <html><head></head><body>
            <form method="POST" action="/login/">
                    <input name="me" type="url" />
                    <input name="url" type="hidden" value="<?php echo $_GET['url']; ?>" />
                    <input type="submit" value="Sign-In" />
            </form>
    <body></html>
    <?php
    endif;
} else {
    list($user, $error) = IndieAuth\Client::complete($_GET);
    if($error) {
            echo "<p>Error: ".$error['error']."</p>";
            echo "<p>".$error['error_description']."</p>";
    } else {
            // Login succeeded!
            // put the me URL into the session for later checks!
            $_SESSION['me'] = $user['me'];
            $_SESSION['CREATED'] = time();
            // redirect them to where they were going
            if( isset($_SESSION['redirect_to']) ) {
                    header('Location: '.$_SESSION['redirect_to']);
            } else {
                echo "Authenticated.";
            }
    }
}
