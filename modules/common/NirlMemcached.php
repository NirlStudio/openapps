<?php
/**
 * NirlMemcached.php
 * 
 * 2013, Nirl Studio. All Rights Reserved.
 */
 
include_once dirname(__FILE__).'/common.php' ;

/**
 * NirlMemcached implements a simple memcached service pool.
 */
class NirlMemcached {
	
    private static $server_groups ;
    
	private static function config() {
		
        if ( isset(self::$server_groups) )
			return ;
		 
        global $NirlMemcached_server_groups ;
       	
        if ( empty($NirlMemcached_server_groups) ) {
        	
		 	// by default, use the local memcached service as the default pool.
            self::$server_groups = array(
            
				// '*' indicates the default pool.
                '*' => array(
                    array('127.0.0.1', 11211)
            		// could have more than one server in a pool.
				) 
			) ;
        } else {
        
            self::$server_groups = $NirlMemcached_server_groups;	
        }
	}
	
    public static function get($name) {
    	
		// try to load configuration.
    	self::config();
        
		if ( empty($name)							// no name 
		     || !isset(self::$server_groups[$name]) // invalid name. 
		     || empty(self::$server_groups[$name]) )// no data for the name.
            $name = '*' ; // use the default pool.
		
		// get server entries
        $servers = isset(self::$server_groups[$name]) ? self::$server_groups[$name] : NULL ;
        if ( empty($servers) )
			return FALSE ;
		
		// create instance
        $mc = new Memcached($name) ;
        $mc->addServers($servers) ;
        return $mc ;
    }
}

?>