<?php
/**
 * NirlSession.php
 * 
 * 2013, Nirl Studio. All Rights Reserved.
 */

include_once dirname(__FILE__).'/common.php' ;

/**
 * NirlSession implements some basic features related to session's life time.
 */
class NirlSession {
    
    private static $max_life_time ;
    private static $max_idle_time ;
    private static $id_life_time  ;
    
    private static function config() {
        
        if ( isset(self::$id_life_time) )
            return;
        
        global $NirlSession_max_life_time ;
        global $NirlSession_max_idle_time ;
        global $NirlSession_id_life_time  ;
        
        self::$max_life_time = empty($NirlSession_max_life_time) ? 90000 : $NirlSession_max_life_time ;
        self::$max_idle_time = empty($NirlSession_max_idle_time) ? 1800  : $NirlSession_max_idle_time ;
        self::$id_life_time  = empty($NirlSession_id_life_time)  ? 600   : $NirlSession_id_life_time ;
    }
    
    /**
     * To implement some common session management features.
     */
    public static function manage() {
        
        // check session environment once for a request.
        if ( executed_once('NSESS:MGD') )
            return ;
        
        // prepare session
        self::config() ;    // load configuration.
        if ( empty(session_id()) ) // init session without an existing one.
            session_start() ; 
        
        $now = time(); // to be reused.
        if ( !isset($_SESSION['NSESS:MGD']) ) {
            
            // for an un-managed session, just initialize it.
            $_SESSION['NSESS:MGD'] = $now ;
            $_SESSION['NSESS:LA']  = $now ;
            $_SESSION['NSESS:IDC'] = $now ;
            return ;
        }
        
        // for a managed session, check it.
        if ( ($now - $_SESSION['NSESS:LA']) > self::$max_idle_time  // idled a too long time.
        || ($now - $_SESSION['NSESS:MGD']) > self::$max_life_time) {// survived a too long time.
            
            // forcely clear all session related data.
            session_unset() ;
            session_destroy() ;
            session_write_close() ;
            setcookie(session_name(), '', 0, '/') ;
            session_regenerate_id(TRUE) ;
            
            // re-initialize the session.
            $_SESSION['NSESS:MGD'] = $now ;
            $_SESSION['NSESS:LA']  = $now ;
            $_SESSION['NSESS:IDC'] = $now ;
            return ;
        }

        // update session's active time.
        $_SESSION['NSESS:LA'] = $now ;
        
        // test for the session id's life time.
        if ( ($now - $_SESSION['NSESS:IDC']) > self::$id_life_time ) {
            
            // if the same session id has been used a too long time, 
            session_regenerate_id(FALSE) ;
            $_SESSION['NSESS:IDC'] = $now ;
        }
    }
}

?>