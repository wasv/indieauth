<?php

// Change this to match your IndieAuth url.
static $ME = 'https://example.org/';

session_start([
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
if( isset($_SESSION['me']) && $_SESSION['me'] == $ME ) {
        ; // authorized! just return 200 aka do nothing
} else {
        http_response_code(401);
}
