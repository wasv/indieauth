<?php

declare(strict_types=1);

$configfile= __DIR__ . '/token-config.php';
if (file_exists($configfile)) {
    include_once $configfile;
} else {
    http_response_code(500);
    header('Content-Type: text/plain;charset=UTF-8');
    exit('server_error');
}


define('MINTOKEN_CURL_TIMEOUT', 4);
define('MINTOKEN_REVOKE_AFTER', '7 DAY');

function connectToDatabase(): PDO
{
    static $pdo;
    if (!isset($pdo)) {
        $pdo = new PDO(DB_URL, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

function initCurl(string $url)/* : resource */
{
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($curl, CURLOPT_MAXREDIRS, 8);
    curl_setopt($curl, CURLOPT_TIMEOUT_MS, round(MINTOKEN_CURL_TIMEOUT * 1000));
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT_MS, 2000);
    curl_setopt($curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2);
    return $curl;
}

function storeToken(string $me, string $client_id, string $scope): string
{
    $pdo = connectToDatabase();
    do {
        $hashable = substr(str_replace(chr(0), '', random_bytes(100)), 0, 72);
        $hash = password_hash($hashable, PASSWORD_BCRYPT);
    } while (strlen($hashable) !== 72 || $hash === false);
    for ($i = 0; $i < 10; $i++) {
        $lastException = null;
        $id = bin2hex(random_bytes(32));
        $revokeColumn = '';
        $revokeValue = '';
        $revoking = [];
        if (is_string(MINTOKEN_REVOKE_AFTER) && strlen(MINTOKEN_REVOKE_AFTER) > 0) {
            $revokeColumn = ', revoked';
            $revokeValue = ', NOW() + INTERVAL 7 DAY';
        }
        // We have to prepare inside the loop, https://github.com/teamtnt/tntsearch/pull/126
        $statement = $pdo->prepare('INSERT INTO tokens (token_id, token_hash, auth_me, auth_client_id, auth_scope' . $revokeColumn . ') VALUES (?, ?, ?, ?, ?' . $revokeValue . ')');
        try {
            $statement->execute(array_merge([$id, $hash, $me, $client_id, $scope], $revoking));
        } catch (PDOException $e) {
            $lastException = $e;
            if ($statement->errorInfo()[1] !== 19) {
                throw $e;
            }
            continue;
        }
        break;
    }
    if ($lastException !== null) {
        throw $e;
    }
    return $id . '_' . bin2hex($hashable);
}

function retrieveToken(string $token): ?array
{
    list($id, $hashable) = explode('_', $token);
    $pdo = connectToDatabase();
    $statement = $pdo->prepare('SELECT *, revoked > CURRENT_TIMESTAMP AS active FROM tokens WHERE token_id = ?');
    $statement->execute([$id]);
    $token = $statement->fetch(PDO::FETCH_ASSOC);
    if ($token !== false && password_verify(hex2bin($hashable), $token['token_hash'])) {
        return $token;
    }
    return null;
}

function markTokenUsed(string $tokenId): void
{
    $pdo = connectToDatabase();
    $statement = $pdo->prepare('UPDATE tokens SET last_use = CURRENT_TIMESTAMP WHERE token_id = ? AND (last_use IS NULL OR last_use < CURRENT_TIMESTAMP)');
    $statement->execute([$tokenId]);
}

function revokeToken(string $token): void
{
    $token = retrieveToken($token);
    if ($token !== null) {
        $pdo = connectToDatabase();
        $statement = $pdo->prepare('UPDATE tokens SET revoked = CURRENT_TIMESTAMP WHERE token_id = ? AND (revoked IS NULL OR revoked > CURRENT_TIMESTAMP)');
        $statement->execute([$token['token_id']]);
    }
}

function isTrustedEndpoint(string $endpoint): bool
{
    $pdo = connectToDatabase();
    $statement = $pdo->prepare('SELECT COUNT(*) FROM settings WHERE setting_name = ? AND setting_value = ?');
    $statement->execute(['endpoint', $endpoint]);
    return $statement->fetchColumn() > 0;
}

function discoverAuthorizationEndpoint(string $url): ?string
{
    $curl = initCurl($url);
    $headers = [];
    $last = '';
    curl_setopt($curl, CURLOPT_HEADERFUNCTION, function ($curl, $header) use (&$headers, &$last): int {
        $url = curl_getinfo($curl, CURLINFO_EFFECTIVE_URL);
        if ($url !== $last) {
            $headers = [];
        }
        $len = strlen($header);
        $header = explode(':', $header, 2);
        if (count($header) === 2) {
            $name = strtolower(trim($header[0]));
            if (!array_key_exists($name, $headers)) {
                $headers[$name] = [trim($header[1])];
            } else {
                $headers[$name][] = trim($header[1]);
            }
        }
        $last = $url;
        return $len;
    });
    $body = curl_exec($curl);
    if (curl_getinfo($curl, CURLINFO_HTTP_CODE) !== 200 || curl_errno($curl) !== 0) {
        return null;
    }
    curl_close($curl);
    $endpoint = null;
    if (array_key_exists('link', $headers)) {
        foreach ($headers['link'] as $link) {
            $found = preg_match('@^\s*<([^>]*)>\s*;(.*?;)?\srel="([^"]*?\s+)?authorization_endpoint(\s+[^"]*?)?"@', $link, $match);
            if ($found === 1) {
                $endpoint = $match[1];
                break;
            }
        }
    }
    if ($endpoint === null) {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML(mb_convert_encoding($body, 'HTML-ENTITIES', 'UTF-8'));
        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query('//*[contains(concat(" ", normalize-space(@rel), " "), " authorization_endpoint ") and @href][1]/@href');
        if ($nodes->length === 0) {
            return null;
        }
        $endpoint = $nodes->item(0)->value;
        $bases = $xpath->query('//base[@href][1]/@href');
        if ($bases->length !== 0) {
            $last = resolveUrl($last, $bases->item(0)->value);
        }
    }
    return resolveUrl($last, $endpoint);
}

function verifyCode(string $code, string $client_id, string $redirect_uri, string $endpoint): ?array
{
    $curl = initCurl($endpoint);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query([
        'code' => $code,
        'client_id' => $client_id,
        'redirect_uri' => $redirect_uri,
    ]));
    curl_setopt($curl, CURLOPT_HTTPHEADER, ['Accept: application/json']);
    $body = curl_exec($curl);
    curl_close($curl);
    $info = json_decode($body, true, 2);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return null;
    }
    $info = filter_var_array($info, [
        'me' => FILTER_VALIDATE_URL,
        'scope' => [
            'filter' => FILTER_VALIDATE_REGEXP,
            'options' => ['regexp' => '@^[\x21\x23-\x5B\x5D-\x7E]+( [\x21\x23-\x5B\x5D-\x7E]+)*$@'],
        ],
    ]);
    if (in_array(null, $info, true) || in_array(false, $info, true)) {
        return null;
    }
    return $info;
}

function invalidRequest(): void
{
    // This is probably wrong, but RFC 6750 is a little unclear.
    // Maybe this should be handled per RFC 6749, putting the error code in the redirect?
    http_response_code(400);
    header('Content-Type: text/plain;charset=UTF-8');
    exit('invalid_request');
}

$method = filter_input(INPUT_SERVER, 'REQUEST_METHOD', FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => '@^[!#$%&\'*+.^_`|~0-9a-z-]+$@i']]);
if ($method === 'GET') {
    $bearer_regexp = '@^Bearer [0-9a-f]+_[0-9a-f]+$@';
    $authorization = filter_input(INPUT_SERVER, 'HTTP_AUTHORIZATION', FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => $bearer_regexp]])
        ?? filter_input(INPUT_SERVER, 'REDIRECT_HTTP_AUTHORIZATION', FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => $bearer_regexp]]);
    if ($authorization === null && function_exists('apache_request_headers')) {
        $headers = array_change_key_case(apache_request_headers(), CASE_LOWER);
        if (isset($headers['authorization'])) {
            $authorization = filter_var($headers['authorization'], FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => $bearer_regexp]]);
        }
    }
    if ($authorization === null) {
        http_response_code(401);
        header('WWW-Authenticate: Bearer');
        exit();
    } elseif ($authorization === false) {
        http_response_code(401);
        header('WWW-Authenticate: Bearer, error="invalid_token", error_description="The access token is malformed"');
        exit();
    } else {
        $token = retrieveToken(substr($authorization, 7));
        if ($token === null) {
            http_response_code(401);
            header('WWW-Authenticate: Bearer, error="invalid_token", error_description="The access token is unknown"');
            exit();
        } elseif ($token['active'] === '0') {
            http_response_code(401);
            header('WWW-Authenticate: Bearer, error="invalid_token", error_description="The access token is revoked"');
            exit();
        } else {
            http_response_code(200);
            header('Content-Type: application/json;charset=UTF-8');
            markTokenUsed($token['token_id']);
            exit(json_encode([
                'me' => $token['auth_me'],
                'client_id' => $token['auth_client_id'],
                'scope' => $token['auth_scope'],
            ]));
        }
    }
} elseif ($method === 'POST') {
    $type = filter_input(INPUT_SERVER, 'CONTENT_TYPE', FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => '@^application/x-www-form-urlencoded(;.*)?$@']]);
    if (!is_string($type)) {
        http_response_code(415);
        exit();
    }
    $revoke = filter_input(INPUT_POST, 'action', FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => '@^revoke$@']]);
    if (is_string($revoke)) {
        $token = filter_input(INPUT_POST, 'token', FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => '@^[0-9a-f]+_[0-9a-f]+$@']]);
        if (is_string($token)) {
            revokeToken($token);
        }
        http_response_code(200);
        exit();
    }
    $request =
        (array)filter_input_array(INPUT_POST, [
            'grant_type' => [
                'filter' => FILTER_VALIDATE_REGEXP,
                'options' => ['regexp' => '@^authorization_code$@'],
            ],
            'code' => [
                'filter' => FILTER_VALIDATE_REGEXP,
                'options' => ['regexp' => '@^[\x20-\x7E]+$@'],
            ],
            'client_id' => FILTER_VALIDATE_URL,
            'redirect_uri' => FILTER_VALIDATE_URL,
            'me' => FILTER_VALIDATE_URL,
        ]);
    if ($request === null || in_array(null, $request, true) || in_array(false, $request, true)) {
        invalidRequest();
    }
    $endpoint = discoverAuthorizationEndpoint($request['me']);
    if ($endpoint === null || !isTrustedEndpoint($endpoint)) {
        invalidRequest();
    }
    $info = verifyCode($request['code'], $request['client_id'], $request['redirect_uri'], $endpoint);
    if ($info === null) {
        invalidRequest();
    }
    $token = storeToken($info['me'], $request['client_id'], $info['scope']);
    http_response_code(200);
    header('Content-Type: application/json;charset=UTF-8');
    exit(json_encode([
        'access_token' => $token,
        'token_type' => 'Bearer',
        'scope' => $info['scope'],
        'me' => $info['me'],
    ]));
} else {
    http_response_code(405);
    header('Allow: GET, POST');
    exit();
}

/**
 * The following wall of code is dangerous. There be dragons.
 * Taken from the mf2-php project, which is pledged to the public domain under CC0.
 */
function parseUriToComponents(string $uri): array
{
    $result = [
        'scheme' => null,
        'authority' => null,
        'path' => null,
        'query' => null,
        'fragment' => null,
    ];
    $u = @parse_url($uri);
    if (array_key_exists('scheme', $u)) {
        $result['scheme'] = $u['scheme'];
    }
    if (array_key_exists('host', $u)) {
        if (array_key_exists('user', $u)) {
            $result['authority'] = $u['user'];
        }
        if (array_key_exists('pass', $u)) {
            $result['authority'] .= ':' . $u['pass'];
        }
        if (array_key_exists('user', $u) || array_key_exists('pass', $u)) {
            $result['authority'] .= '@';
        }
        $result['authority'] .= $u['host'];
        if (array_key_exists('port', $u)) {
            $result['authority'] .= ':' . $u['port'];
        }
    }
    if (array_key_exists('path', $u)) {
        $result['path'] = $u['path'];
    }
    if (array_key_exists('query', $u)) {
        $result['query'] = $u['query'];
    }
    if (array_key_exists('fragment', $u)) {
        $result['fragment'] = $u['fragment'];
    }
    return $result;
}
function resolveUrl(string $baseURI, string $referenceURI): string
{
    $target = [
        'scheme' => null,
        'authority' => null,
        'path' => null,
        'query' => null,
        'fragment' => null,
    ];
    $base = parseUriToComponents($baseURI);
    if ($base['path'] == null) {
        $base['path'] = '/';
    }
    $reference = parseUriToComponents($referenceURI);
    if ($reference['scheme']) {
        $target['scheme'] = $reference['scheme'];
        $target['authority'] = $reference['authority'];
        $target['path'] = removeDotSegments($reference['path']);
        $target['query'] = $reference['query'];
    } else {
        if ($reference['authority']) {
            $target['authority'] = $reference['authority'];
            $target['path'] = removeDotSegments($reference['path']);
            $target['query'] = $reference['query'];
        } else {
            if ($reference['path'] == '') {
                $target['path'] = $base['path'];
                if ($reference['query']) {
                    $target['query'] = $reference['query'];
                } else {
                    $target['query'] = $base['query'];
                }
            } else {
                if (substr($reference['path'], 0, 1) == '/') {
                    $target['path'] = removeDotSegments($reference['path']);
                } else {
                    $target['path'] = mergePaths($base, $reference);
                    $target['path'] = removeDotSegments($target['path']);
                }
                $target['query'] = $reference['query'];
            }
            $target['authority'] = $base['authority'];
        }
        $target['scheme'] = $base['scheme'];
    }
    $target['fragment'] = $reference['fragment'];
    $result = '';
    if ($target['scheme']) {
        $result .= $target['scheme'] . ':';
    }
    if ($target['authority']) {
        $result .= '//' . $target['authority'];
    }
    $result .= $target['path'];
    if ($target['query']) {
        $result .= '?' . $target['query'];
    }
    if ($target['fragment']) {
        $result .= '#' . $target['fragment'];
    } elseif ($referenceURI == '#') {
        $result .= '#';
    }
    return $result;
}
function mergePaths(array $base, array $reference): string
{
    if ($base['authority'] && $base['path'] == null) {
        $merged = '/' . $reference['path'];
    } else {
        if (($pos=strrpos($base['path'], '/')) !== false) {
            $merged = substr($base['path'], 0, $pos + 1) . $reference['path'];
        } else {
            $merged = $base['path'];
        }
    }
    return $merged;
}
function removeLeadingDotSlash(string &$input): void
{
    if (substr($input, 0, 3) == '../') {
        $input = substr($input, 3);
    } elseif (substr($input, 0, 2) == './') {
        $input = substr($input, 2);
    }
}
function removeLeadingSlashDot(string &$input): void
{
    if (substr($input, 0, 3) == '/./') {
        $input = '/' . substr($input, 3);
    } else {
        $input = '/' . substr($input, 2);
    }
}
function removeOneDirLevel(string &$input, string &$output): void
{
    if (substr($input, 0, 4) == '/../') {
        $input = '/' . substr($input, 4);
    } else {
        $input = '/' . substr($input, 3);
    }
    $output = substr($output, 0, strrpos($output, '/'));
}
function removeLoneDotDot(string &$input): void
{
    if ($input == '.') {
        $input = substr($input, 1);
    } else {
        $input = substr($input, 2);
    }
}
function moveOneSegmentFromInput(string &$input, string &$output): void
{
    if (substr($input, 0, 1) != '/') {
        $pos = strpos($input, '/');
    } else {
        $pos = strpos($input, '/', 1);
    }
    if ($pos === false) {
        $output .= $input;
        $input = '';
    } else {
        $output .= substr($input, 0, $pos);
        $input = substr($input, $pos);
    }
}
function removeDotSegments(string $path): string
{
    $input = $path;
    $output = '';
    $step = 0;
    while ($input) {
        $step++;
        if (substr($input, 0, 3) == '../' || substr($input, 0, 2) == './') {
            removeLeadingDotSlash($input);
        } elseif (substr($input, 0, 3) == '/./' || $input == '/.') {
            removeLeadingSlashDot($input);
        } elseif (substr($input, 0, 4) == '/../' || $input == '/..') {
            removeOneDirLevel($input, $output);
        } elseif ($input == '.' || $input == '..') {
            removeLoneDotDot($input);
        } else {
            moveOneSegmentFromInput($input, $output);
        }
    }
    return $output;
}
