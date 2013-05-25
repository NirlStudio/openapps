<?php
/**
 * NirlAuth.php
 *
 * Nirl Studio. All Rights Reserved.
 */

include_once dirname(__FILE__) . '/NirlLog.php';
include_once dirname(__FILE__) . '/NirlSQLi.php';
include_once dirname(__FILE__) . '/NirlShield.php';
include_once dirname(__FILE__) . '/NirlSession.php';

class NirlAuthDigest {

    private static $configured;

    private static $realm = 'auth.nirls.net'; // security domain.
    private static $web_login = '';           // the login page.
    
    private static $nonce_key = ':/B:c_!FH$%A7~99^#8'; // to generate nonce.
    private static $nonce_lifetime = 600; // the valid period of a nonce.
    
    // seconds - 0 to disable credential cookie.
    private static $cred_cookie = 0;      // the expires time of credential cookie.
    // a blowfish encryption key.
    private static $cred_cookie_key = ''; // to encrypt/decrypt cookie.

    // the database name of user authentication data. 
    private static $sql_db_name = 'user'; // by default, use the db named as 'user'.
    // this program supposes that the result row contains a 'user_id' and a 'hash' value.
    // by default, the value of 'hash' should be the A1 value defined by Digest Authentication spec.
    private static $sql_query = 'SELECT user_id AS `user_id`, passhash AS `hash`
                                 FROM t_user WHERE user_name=?';

    private static function config() {

        if ( isset(self::$configured) )
            return;
        
        // try to access global config values.
        global $NirlAuthDigest_realm;
        global $NirlAuthDigest_web_login;

        global $NirlAuthDigest_nonce_key;
        global $NirlAuthDigest_nonce_lifetime;
        
        global $NirlAuthDigest_cred_cookie;
        global $NirlAuthDigest_cred_cookie_key;

        global $NirlAuthDigest_sql_db_name;
        global $NirlAuthDigest_sql_query;

        // try to save config values.
        if ( !empty($NirlAuthDigest_realm) )
            self::$realm = $NirlAuthDigest_realm;
        if ( !empty($NirlAuthDigest_web_login) )
            self::$web_login = $NirlAuthDigest_web_login;

        if ( !empty($NirlAuthDigest_nonce_key) )
            self::$nonce_key = $NirlAuthDigest_nonce_key;
        if ( !empty($NirlAuthDigest_nonce_lifetime) )
            self::$nonce_lifetime = $NirlAuthDigest_nonce_lifetime;

        if ( !empty($NirlAuthDigest_cred_cookie) )
            self::$cred_cookie = $NirlAuthDigest_cred_cookie;
        if ( !empty($NirlAuthDigest_cred_cookie_key) )
            self::$cred_cookie_key = $NirlAuthDigest_cred_cookie_key;

        if ( !empty($NirlAuthDigest_sql_db_name) )
            self::$sql_db_name = $NirlAuthDigest_sql_db_name;
        if ( !empty($NirlAuthDigest_sql_query) )
            self::$sql_query = $NirlAuthDigest_sql_query;

        // update the flag.
        self::$configured = TRUE;
    }

    /**
     * To tell this class to use the login page to challenge user for this session.
     */
    public static function useLoginPage() {

        NirlShield::guardIP();
        NirlSession::manage();
        
        $_SESSION['NAD:WEB'] = TRUE;
        setcookie('_NADWL', '1', time()+3600*24*30);
    }

    /**
     * Try to get the user ID of current session, return 0 without it.
     */
    public static function getUserID() {

        NirlShield::guardIP();
        NirlSession::manage();
        self::config();
        
        // try to recover user from credential cookie.
        if ( !isset($_SESSION['NAD:UID']) )
            self::recoverCredCookie();

        // no authenticated user found.
        if ( !isset($_SESSION['NAD:UID']) )
            return 0;

        $userid = $_SESSION['NAD:UID']; 
        NirlShield::guardUser($userid);
        return $userid;
    }

    /**
     * Clear the existing user security context (logout).
     */
    public static function clearUserID() {

        // detect suspicious action.
        NirlShield::guardIP();
        
        if ( has_session() && isset($_SESSION['NAD:UID']) )
            unset($_SESSION['NAD:UID']); // clear session.
        self::refreshCredCookie(0);  // clear cookie.
    }

    /**
     * Try to get current user ID, and initiate a challenge respone without it.
     */
    public static function requireUserID() {

        NirlShield::guardIP();
        NirlSession::manage();
        self::config();

        // try to recover credential cookie.
        if ( !isset($_SESSION['NAD:UID']) )
            self::recoverCredCookie();

        // test for existing user context.
        if ( isset($_SESSION['NAD:UID']) ) {

            $userid = $_SESSION['NAD:UID'];
            NirlShield::guardUser($userid);
            return $userid;
        }

        // authenticate or challenge client.
        if ( isset($_SERVER['PHP_AUTH_DIGEST']) ) // it's an authorization request.
            return self::authenticate($_SERVER['PHP_AUTH_DIGEST']);
        else
            self::challenge(); // to challenge the client.
    }

    /**
     * To authenticate a user by a name & passcode pair.
     */
    public static function authenticateByCred($username, $passcode) {

        NirlShield::guardIP();
        NirlSession::manage();
        self::config();

        // check request
        if ( empty($username) || empty($passcode) || 
            strlen($username) < 4 || strlen($username) > 128 || 
            strlen($passcode) < 4 || strlen($passcode) > 128) {

            $ipaddr = $_SERVER['REMOTE_ADDR'];
            self::failed('name and/or passcode are invalid.', 
                         array($username, $passcode, $ipaddr), FALSE);
            NirlShield::block($ipaddr, 3600, 'invalid auth request.(web-form)');
        }

        // query user's authentication info.
        $user = self::queryUserFromSQL($username);
        if ( $user === FALSE )
            return 0;
        
        // the field 'hash' is required.
        if ( isset($user['hash']) && !empty($user['hash']) )
            $hash = $user['hash'];
        else
            return 0;

        // the field 'user_id' is required.
        if ( !isset($user['user_id']) || empty($user['user_id']) )
            return 0;

        // compute the hash value of credential.
        $A1 = self::generatePassHash($username, $passcode);
        if ( $A1 === $hash )// succeeded.
            return self::authenticatedAsUser($user['user_id']);
        else // failed.
            return 0;
    }

    /**
     * To save the authenticated user id into session.
     */
    public static function authenticatedAsUser($userid) {

        // this method can be directly called by appliction code.
        NirlShield::guardIP();
        NirlSession::manage();
        self::config();

        // save user id into session.
        $_SESSION['NAD:UID'] = $userid;
        // try to generate credential cookie.
        self::refreshCredCookie($userid);
        
        return $userid;
    }

    /**
     * Generate a random passcode basing on a seed value.
     */
    public static function generateRandPass($seed) {
        
        NirlShield::guardIP();
        self::config();

        if ( empty($seed) )
            $seed = time();
        
        $rand = openssl_random_pseudo_bytes(32);
        return md5($rand.$seed);
    }

    /**
     * Compute the hash-value (A1) basing on user name and passcode.
     */
    public static function generatePassHash($username, $passcode) {
        
        NirlShield::guardIP();
        self::config();
        
        // according to Digest Authentication's spec.
        return md5($username . ':' . self::$realm . ':' . $passcode);
    }

    private static function generateNonce($opaque, $ipaddr = NULL) {

        if (empty($ipaddr))
            $ipaddr = $_SERVER['REMOTE_ADDR'];

        // to validate of the nonce in authorization later.
        return md5($opaque . $ipaddr . self::$nonce_key);
    }

    private static function challenge() {

        // for a web application, developer can provide a customized login page.
        if ( isset($_SESSION['NAD:WEB']) || isset($_COOKIE['_NADWL']) ) {
            
            // to challenge client by a customized login page.
            $url = self::$web_login;
            if ( !empty($url) ) { // it's required.
                
                header("Location: $url");
                die();
            }
        }
        
        // to use the time stamp as the opaque value, to validate later.
        $opaque = base64_encode(strval(time()));
        $nonce = self::generateNonce($opaque);

        // compose response headers.
        header('HTTP/1.1 401 Unauthorized');
        header('WWW-Authenticate: Digest realm="' . self::$realm . '",qop="auth",nonce="' . $nonce . '",opaque="' . $opaque . '"');
        die();
    }

    private static function authenticate($auth) {

        // to parse Authorization header.
        $data = self::parseHeader($auth);
        if ($data === FALSE)
            self::failed("Invalid auth header", $auth);

        // to get values of required fields.
        $username = $data['username'];
        $nonce = $data['nonce'];
        $opaque = $data['opaque'];
        $ipaddr = $_SERVER['REMOTE_ADDR'];

        // to verify the request.
        self::verfifyAuthRequest($username, $nonce, $opaque, $ipaddr);

        // query user's authentication info.
        $user = self::queryUserFromSQL($username);
        if ($user === FALSE)
            self::failed("Not found user.", $username);

        // the field 'hash' is required.
        if ( isset($user['hash']) && !empty($user['hash']) )
            $A1 = $user['hash'];
        else
            self::failed("Not pass hash.", $username);

        // the field 'user_id' is required.
        if ( !isset($user['user_id']) || empty($user['user_id']) )
            self::failed("Not user ID.", $username);
        
        // according to Digest Authentication's spec.
        $A2 = md5($_SERVER['REQUEST_METHOD'] . ':' . $data['uri']);
        // compute the correct auth response.
        $response = md5($A1 . ':' . $nonce . ':' . $data['nc'] . ':' . $data['cnonce'] . ':' . $data['qop'] . ':' . $A2);

        // compare with the auth response from client side.
        if ($data['response'] === $response)
            return self::authenticatedAsUser($user['user_id']);

        // detect for too frequent failures of authentication.
        NirlShield::authFailure($username);

        // authentication failed.
        self::failed("Invalid auth response.", $auth);
    }

    private static function parseHeader($auth) {

        // required fields for Digest Authentication.
        $fields = array('nonce' => 1, 'nc' => 1, 'cnonce' => 1, 'qop' => 1, 'username' => 1, 'uri' => 1, 'response' => 1, 'opaque' => 1);

        // merge field names.
        $keys = implode('|', array_keys($fields));
        // parse the header.
        preg_match_all('@(' . $keys . ')=(?:([\'"])([^\2]+?)\2|([^\s,]+))@', $auth, $matches, PREG_SET_ORDER);

        $data = array();
        // the result set.
        foreach ($matches as $m) {

            $field = $m[1];
            $data[$field] = $m[3] ? $m[3] : $m[4];
            unset($fields[$field]); // remove found field.
        }

        // return FALSE when missed anyone of required fields.
        return $fields ? FALSE : $data;
    }

    private static function queryUserFromSQL($username) {

        if ( empty(self::$sql_db_name) || empty(self::$sql_query) )
            return FALSE;
        
        $db = new NirlSQLi(self::$sql_db_name);
        if ( $db->query(self::$sql_query, 's', array($username)) )
            return $db -> getAssoc(); // array('user_id' => 1000, 'pass' => '.....')
        else
            return FALSE;
    }

    private static function verfifyAuthRequest($username, $nonce, $opaque, $ipaddr) {

        // detect for too frequent authentication.
        NirlShield::authAttempt($username);

        if ( empty($username) || empty($nonce) || empty($opaque) ) {

            self::failed('missing required auth field.', 
                         array($username, $nonce, $opaque, $ipaddr), FALSE);
            NirlShield::block($ipaddr, 3600, 'invalid auth request.(0)');
        }

        if ( strlen($username) > 128 || strlen($nonce) > 128 || strlen($opaque) > 128 ) {

            self::failed('auth field is too large.', 
                          array($username, $nonce, $opaque, $ipaddr), FALSE);
            NirlShield::block($ipaddr, 3600, 'invalid auth request.(1)');
        }

        if ( strstr($username, '\'') ) {// SQL Injection attempt?

            self::failed('harmful user name - SQL Injection', 
                         array($username, $nonce, $opaque, $ipaddr), FALSE);
            NirlShield::block($ipaddr, 3600 * 8, 'invalid auth request.(2)');
        }

        // verify the opaque, re-challenge client if the value is timeout.
        $timestamp = intval(base64_decode($opaque));
        if ( (time() - $timestamp) > self::$nonce_lifetime )
            self::challenge();

        // verify the nonce, re-challenge client if the nonce is invalid.
        $vnonce = self::generateNonce($opaque, $ipaddr);
        if ( $vnonce !== $nonce )
            self::challenge();
    }

    private static function refreshCredCookie($userid = 0) {
        
        if ($userid == 0) {  // for logout operation.
        
            setcookie('_NADC', '', 1); // remove the cookie.
            return;
        }

        if ( empty(self::$cred_cookie) || empty(self::$cred_cookie_key) )
            return; // missing required configuration, disabled.

        $cred = self::generateCredCookie($userid);
        if ( !empty($cred) )
            setcookie('_NADC', $cred, time() + self::$cred_cookie);
    }

    private static function recoverCredCookie() {

        if ( !isset($_COOKIE['_NADC']) || empty($_COOKIE['_NADC']) )
            return FALSE;

        $cred = $_COOKIE['_NADC'];
        $userid = self::parseCredCookie($cred);
        if ($userid == 0) // no valid user ID.
            return FALSE;

        // update session.
        self::authenticatedAsUser($userid);
        return TRUE;
    }

    private static function generateCredCookie($userid) {

        $ver = 'D5';
        $rand = openssl_random_pseudo_bytes(8);
        $time = time() + self::$cred_cookie;

        $txt = "$ver:$time:$userid:" . self::$realm . ":$rand";
        $iv = mcrypt_create_iv(8, MCRYPT_RAND);
        $txt = mcrypt_encrypt(MCRYPT_BLOWFISH, self::$cred_cookie_key, $txt, MCRYPT_MODE_CBC, $iv);

        $iv = bytes_to_hex($iv);
        $txt = base64_encode($txt);
        return "$ver$iv$txt";
    }

    private static function parseCredCookie($cred) {
        
        $ver = substr($cred, 0, 2);
        if ($ver != 'D5')
            return 0;

        $iv = hex_to_bytes(substr($cred, 2, 16));
        $txt = base64_decode(substr($cred, 18));
        $txt = mcrypt_decrypt(MCRYPT_BLOWFISH, self::$cred_cookie_key, $txt, MCRYPT_MODE_CBC, $iv);
        $ver = substr($txt, 0, 2);
        if ($ver != 'D5')
            return 0;

        $pos = strpos($txt, ':', 3);
        if ($pos === FALSE || $pos <= 3)
            return 0;

        $time = intval(substr($txt, 3, $pos - 3));
        if ($time > time())
            return 0;

        $pos += 1;
        $next = strpos($txt, ':', $pos);
        if ($next === FALSE || $next <= $pos)
            return 0;

        $userid = substr($txt, $pos, $next - $pos);
        return intval($userid);
    }

    private static function failed($reason, $data = NULL, $terminate = TRUE) {

        NirlLog::warn($reason, get_called_class(), 90802, $data);
        if ( $terminate ) {

            header('HTTP/1.1 401 Access Denied');
            die();
        }
    }

}
?>