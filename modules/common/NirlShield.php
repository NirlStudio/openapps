<?php
/**
 * NirlShield.php
 * 
 * 2013, Nirl Studio. All Rights Reserved.
 */

include_once dirname(__FILE__).'/common.php' ;
include_once dirname(__FILE__).'/NirlLog.php' ;

/**
 * NirlShield provides a VERY SIMPLE protection to server.
 */
class NirlShield {
    
	private static $enable ;    // TRUE or FALSE
	private static $policies ;  // array('event_name' => array('seconds'=>600, 'limit'=>600), ...)
	
	private static function config() {
		
		if ( isset(self::$enable) ) // has been configured.
			return self::$enable ;
		
		global $NirlShield_enable ;
		global $NirlShield_policies ;
		
		if ( empty($NirlShield_enable) ){
			  
        	// without explicit configuration, turn it off.
            self::$enable   = FALSE ;
            self::$policies = NULL ;
        } else if ( empty($NirlShield_policies) ) {
        	
        	// without explicit policies, turn it off.
            self::$enable   = FALSE ; 
            self::$policies = NULL ;
			error_log('NirlShield::config::can\'t be enabled without policies.') ;
        } else {
        	
        	// turn it on.
            self::$enable   = TRUE ;
            self::$policies = $NirlShield_policies ;
        }
		
		// to tell the functional methods.
		return self::$enable ;
	}
	
	/**
	 * It should be called immediately when an event occurs.
	 * event_name: the type of this event.
	 * entity_id : the identity of current security entity.
	 */
	public static function trying($event_name, $entity_id) {
		
		$key = "NSH:$event_name:$entity_id" ; // generate counter entry key.
        if ( executed_once($key)  // has been detected for the same request. 
             || !self::config() ) // or disabled, or no valid configuration.
            return ;
		
		$mc = NirlMemcached::get('NirlShield') ;
		if ( empty($mc) ) 
			return ; // without an available memcached instance, let it go.
		
		if ( !empty($mc->get("NSH:B:$entity_id")) ) {
			
			// if the entity has been blocked, terminate current request immediately.
        	header('HTTP/1.1 403 Forbidden') ;
        	die() ;
		}
	
		$config = map_to(self::$policies, $event_name) ;
		if ( empty($config) ) 
			return ; // do nothing for an event without a valid policy.

		// get the value of time period for countering.
		$seconds = map_ne($config, 'seconds', 600) ; // by default, 600 calls in 600 seconds.
		
		// try to increment counter value.
		$counter = $mc->increment($key, 1) ;
		if ( $counter === FALSE ) {
			 
			// the counter entry was not existing.
			$mc->add($key, 0, $seconds) ; 		 // try to add an empty entry.
			$counter = $mc->increment($key, 1) ; // try to increment it again.
			
			if ( $counter === FALSE ) // there must be some unknown issues.
				return ;
		}
	
		$limit = map_ne($config, 'limit', 600) ;
		if ( $counter > $limit ) // to block it for 6 times of countering period.
			self::block($entity_id, $seconds * 6) ; 
	}
	
	/**
	 * To block a suspicious entity for a period of time.
	 * entity_id: the identity of target security entity.
	 * seconds  : the time period to block. 
	 */
	public static function block($entity_id, $seconds, $reason='') {
		
		$result = 'should be' ;
		if ( self::config() ) {
        
			$key = "NSH:B:$entity_id" ; // generate the key of block entry.
        	$mc = NirlMemcached::get('NirlShield') ;
			if ( !empty($mc) ) { // if there is a memcached instance,
			 
				if ( $mc->set($key, 1, $seconds) ) // add a new one or replace an existing one.
					$result = 'has been' ;         // if did it successfully.
			}
        }
 
 		// to log a warning message.
 		NirlLog::warn("$entity_id $result blocked in next $seconds seconds.", 
 		              'NirlShield:block', 90000, $reason);
 		
        // even being disabled, it will terminate current request immediately too.
        header('HTTP/1.1 403 Forbidden') ;
        die() ;
	}
    
	/**
	 * To detect operation frequency for the same remote IP address.
	 */
	public static function guardIP() {
		
		$addr = $_SERVER['REMOTE_ADDR'] ; // use the IP address detected by web server.
		if ( !empty($addr) )
			self::trying('guardIP', $addr) ;
	}
    
	/**
	 * To detect operation frequency for the same authenticated user.
	 */
	public static function guardUser($userid) {
		
		if ( !empty($userid) )
			self::trying('guardUser', $userid) ;
	}
    
	/**
	 * To detect for authentication attempts.
	 */
	public static function authAttempt($username) {
		
		if ( !empty($username) )
			self::trying('authAttempt', $username) ;
	}
    
	/**
	 * To detect for authentication failure.
	 */
	public static function authFailure($username) {
		
		if ( !empty($username) )
			self::trying('authFailure', $username) ;
	}
	
	/**
	 * To detect for invalid calls from a user and a remote address.
	 */
	public static function invalidCall($userid) {
		
		// detect by user id.
		if ( !empty($userid) )
			self::trying('invalidCallUser', $addr) ;
		
		// detect by client IP address.
		$addr = $_SERVER['REMOTE_ADDR'] ;
		if ( !empty($addr) )
			self::trying('invalidCallIP', $addr) ;
	}
	
	/**
	 * To detect for internal errors related to a user and a remote address.
	 */
	public static function internalError($userid) {
		
		// detect by user id.
		if ( !empty($userid) )
			self::trying('internalErrorUser', $addr) ;
		
		// detect by client IP address.
		$addr = $_SERVER['REMOTE_ADDR'] ;
		if ( !empty($addr) )
			self::trying('internalErrorIP', $addr) ;
	}
}

?>