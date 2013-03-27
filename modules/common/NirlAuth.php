<?php
/** 
 * NirlAuth.php
 * 
 * Nirl Studio. All Rights Reserved.
 */

include_once dirname(__FILE__).'/NirlLog.php' ;
include_once dirname(__FILE__).'/NirlSQLi.php' ;
include_once dirname(__FILE__).'/NirlShield.php' ;
include_once dirname(__FILE__).'/NirlSession.php' ;


class NirlAuthDigest {
	
    private static $configured ;
    
	private static $realm          = 'auth.nirls.net' ;
	private static $nonce_key      = ':/B:c_!FH$%A7~99^#8' ;
	private static $nonce_lifetime = 600 ; // seconds
	private static $cred_cookie    = 0 ;   // disabled by default.
	
    private static $sql_db_name = 'user' ; // by default, use the db named as 'user'.
    // this program supposes that the result row contains user_id and hash value(A1).
	private static $sql_query   = 'SELECT user_id AS `user_id`, passhash AS `hash`
	                               FROM t_inst_auth WHERE inst_id=?' ;
	
    private static function config() {
        
        if ( isset(self::$configured) )
            return ;
        
        global $NirlAuthDigest_realm ;
        global $NirlAuthDigest_nonce_key ;
        global $NirlAuthDigest_nonce_lifetime ;
        global $NirlAuthDigest_cred_cookie ;
		
        global $NirlAuthDigest_sql_db_name ;
        global $NirlAuthDigest_sql_query ;
		
        if ( !empty($NirlAuthDigest_realm) )
            self::$realm = $NirlAuthDigest_realm ;
		
        if ( !empty($NirlAuthDigest_nonce_key) )
            self::$nonce_key = $NirlAuthDigest_nonce_key ;
        
        if ( !empty($NirlAuthDigest_nonce_lifetime) )
            self::$nonce_lifetime = $NirlAuthDigest_nonce_lifetime ;
        
        if ( !empty($NirlAuthDigest_cred_cookie) )
            self::$cred_cookie = $NirlAuthDigest_cred_cookie ;
		
        if ( !empty($NirlAuthDigest_sql_db_name) )
            self::$sql_db_name = $NirlAuthDigest_sql_db_name ;
        
        if ( !empty($NirlAuthDigest_sql_query) )
            self::$sql_query = $NirlAuthDigest_sql_query ;
        
        self::$configured = TRUE ; // update the flag.
    }
    
	/**
	 * Try to get the user ID of current session, return 0 without it.
	 */
	public static function getUserID() {
		
		// try to detect action frequency from the client.
        NirlShield::guardIP() ;
        if ( empty(session_id()) )
            return 0 ; // Zero must be defined as an invalid user ID in application.
            
		NirlSession::manage() ;
		// try to recover credential cookie.
        if ( !isset($_SESSION['NAD:UID']) )
			self::recoverCredCookie() ;

		// no authenticated user found.			
        if ( !isset($_SESSION['NAD:UID']) )
        	return 0 ;
		
        $userid = $_SESSION['NAD:UID'] ;
        // try to detect user's action frequency.
        NirlShield::guardUser($userid) ;
		
		self::refreshCredCookie(); 
        return $userid ;
	}
	
	/**
	 * Clear the existing user security context (logout).
	 */
	public static function clearUserID() {
		
        NirlShield::guardIP() ; // detect suspicious action.
        if ( !empty(session_id()) && isset($_SESSION['NAD:UID']) ) {
        	 
			unset($_SESSION['NAD:UID']) ;
			self::refreshCredCookie(TRUE); 
		}
	}
	
	/**
	 * Try to get current user ID, initiate a challenge 0 without it.
	 */
	public static function requireUserID() {
		
        NirlShield::guardIP() ; // guard by remote address.
        NirlSession::manage() ; // manage current session.
        self::config() ;        // try to load configuration.
        
		// try to recover credential cookie.
        if ( !isset($_SESSION['NAD:UID']) )
			self::recoverCredCookie() ;
		
		// test for existing user context.
		if ( isset($_SESSION['NAD:UID']) ) {
		 
            $userid = $_SESSION['NAD:UID'] ;
            NirlShield::guardUser($userid) ; // guard by user.
			self::refreshCredCookie(); 
            return $userid ;
		}
		
		// authenticate or challenge client.
		if ( isset($_SERVER['PHP_AUTH_DIGEST']) ) // it's authorization request.
            return self::authenticate($_SERVER['PHP_AUTH_DIGEST']) ;
        else 
			self::challenge() ; // to challenge current client.
	}
    
    /**
	 * Generate a random passcode basing on an appointed seed.
	 */
	public static function generateRandPass($seed) {

        self::config() ;
        $rand1 = rand(8899000, 18899000) ;
        $rand2 = rand(1899000, 28899000) ;
        $rand = "$rand1:$seed:$rand2" ;
        return md5(self::$realm . $rand) ;
    }
	
	/**
	 * Compute the hash-value (A1) basing on user name and passcode.
	 */
	public static function generatePassHash($username, $passcode) {

        self::config() ;
		// according to Digest Authentication's spec.
		return md5($username . ':' . self::$realm . ':' . $passcode) ;
	}
	
	
    private static function generateNonce($opaque, $ipaddr=NULL) {
    
        if ( empty($ipaddr) )
            $ipaddr = get_remote_addr() ;
		
		// for validation of the nonce in authentication process later.
        return md5($opaque . $ipaddr . self::$nonce_key) ;
    }
    
	private static function challenge() {
	   	
		// to use the time stamp as the opaque value, to validate later.
		$opaque = base64_encode(strval(time())) ;
		$nonce = self::generateNonce($opaque) ;
		
		// compose response headers.
		header('HTTP/1.1 401 Unauthorized') ;
		header('WWW-Authenticate: Digest realm="' . self::$realm .
		           '",qop="auth",nonce="' . $nonce . '",opaque="' . $opaque . '"') ;
		die() ;
	}
	
	private static function authenticate($auth) {
		
		// parse Authorization header.
		$data = self::parseHeader($auth) ;
		if ( $data === FALSE ) 
	       self::failed("Invalid auth header", $auth) ;
		
		// get seperate value of each field.
		$username = $data['username'] ;
		$nonce = $data['nonce'] ;
		$opaque = $data['opaque'] ;
		$ipaddr = get_remote_addr() ;
		
		// to verify the request.
        self::verfifyAuthRequest($username, $nonce, $opaque, $ipaddr) ;
		
		// query user's authentication info.
		$user = self::queryUserFromSQL($username) ;
		if ( $user === FALSE ) 
	       self::failed("Not found user", $username) ;
		
		if ( isset($user['hash']) && !empty($user['hash']) )
			$A1 = $user['hash'] ;
		else 
	       self::failed("Not pass hash", $username) ; 
        
		// according to Digest Authentication's spec.
		$A2 = md5($_SERVER['REQUEST_METHOD'] . ':' . $data['uri']) ;
		// compute the correct auth response.
		$response = md5($A1 . ':' . $nonce . ':' . $data['nc'] . ':' 
		                . $data['cnonce'] . ':' . $data['qop'] . ':' . $A2) ;
		
		// compare with the auth response from client side.
		if ( $data['response'] !== $response ) {
		
			self::failed("Invalid auth response", $auth, FALSE) ;	
			// detect for too frequent failed authentication from same client.
			NirlShield::authFailure($username) ;
		}
		
		// succeeded!
		$userid = $user['user_id'] ;
		$_SESSION['NAD:UID'] = $userid ;
		self::refreshCredCookie(); 
		return $userid ;
	}
	
	private static function parseHeader($auth) {
		
		// required fields for Digest Authentication.
		$fields = array('nonce'=>1, 'nc'=>1, 'cnonce'=>1, 'qop'=>1, 
		                'username'=>1, 'uri'=>1, 'response'=>1, 'opaque'=>1) ;
		
		// merge field names.
		$keys = implode('|', array_keys($fields)) ;
		// parse the header.
		preg_match_all('@(' . $keys . ')=(?:([\'"])([^\2]+?)\2|([^\s,]+))@', 
		               $auth, $matches, PREG_SET_ORDER) ;
					   
		$data = array(); // the result set.
		foreach ($matches as $m) {
			
			$field = $m[1] ;
			$data[$field] = $m[3] ? $m[3] : $m[4] ;
			unset($fields[$field]) ; // remove found field.
		}
		
		// return FALSE when missed anyone of required fields.
		return $fields ? FALSE : $data ;
	}
	
	private static function queryUserFromSQL($username) {
		
		$db = new NIrlSQLi(self::$sql_db_name) ;
		if ( $db->query(self::$sql_query, 's', array($username)) )
			return $db->getAssoc() ; // array('user_id' => 1000, 'pass' => '.....')
		else
			return FALSE ;
	}
    
	private static function verfifyAuthRequest($username, $nonce, $opaque, $ipaddr) {
		
        if ( empty($username) || empty($nonce) || empty($opaque) ){
            
            self::failed('missing required auth field.', 
                         array($username, $nonce, $opaque, $ipaddr), FALSE) ;
            NirlShield::block($ipaddr, 3600, 'invalid auth request.(0)') ;
        }
        
        if ( strlen($username) > 128 || strlen($nonce) > 128 || strlen($opaque) > 128 ){
            
            self::failed('auth field is too large.',
                         array($username, $nonce, $opaque, $ipaddr), FALSE) ;
            NirlShield::block($ipaddr, 3600, 'invalid auth request.(1)') ;
        }
        
        if ( strstr($username, '\'') ) { // SQL Injection attempt.
            
            self::failed('harmful user name - SQL Injection',
                         array($username, $nonce, $opaque, $ipaddr), FALSE) ;
            NirlShield::block($ipaddr, 3600 * 8, 'invalid auth request.(2)') ;
        }
        
        // verify the opaque, re-challenge client if the value is timeout.
        $timestamp = intval(base64_decode($opaque)) ;
        if ( (time() - $timestamp) > self::$nonce_lifetime )
            self::challenge() ;
		
		// verify the nonce, re-challenge client if the nonce is invalid.
        $vnonce = self::generateNonce($opaque, $ipaddr) ;
        if ( $vnonce !== $nonce )
            self::challenge() ;
        
		// detect for too frequent authentication.
		NirlShield::authAttempt($username);
	}
	
	private static function refreshCredCookie($clear=FALSE) {
		
		// TODO
	}
	
	private static function recoverCredCookie() {
		
		// TODO
		return FALSE ;
	}
	
	private static function generateCredCookie() {
		
		// TODO
		return '' ;
	}
	
	private static function parseCredCookie() {
		
		// TODO
		return 0 ;
	}
	
	private static function failed($reason, $data=NULL, $terminate=TRUE) {
		
        NirlLog::warn($reason, get_called_class(), 90802, $data);
        if ( $terminate ) {
		
			header('HTTP/1.1 401 Access Denied') ;
			die() ;	
		}
	}
}

?>