<?php

$configfile= __DIR__ . '/config.php';
if (file_exists($configfile)) {
    include_once $configfile;
} else {
    http_response_code(500);
}

session_start([
        'name' => 'iauth',
        'use_only_cookies' => true,
        'cookie_secure' => true,
        'cookie_lifetime' => 14 * 24  *3600,
        'gc_maxlifetime' => 14 * 24 * 3600,
]);

if (!isset($_SESSION['CREATED']) || (time() - $_SESSION['CREATED'] > 7 * 24 * 3600)) {
    // session created more than 1 hour ago. expire it.
    session_unset();     // unset $_SESSION variable for the run-time
    session_destroy();   // destroy session data in storage
}
// session start and check session for a 'me' url and whether
// that 'me' is good according to the auth request
if( isset($_SESSION['me']) ) {
    if( $_SESSION['me'] == MYSELF ) {
        ; // authorized! just return 200 aka do nothing
    } else {
        http_response_code(403);
    }
} else {
        http_response_code(401);
}
