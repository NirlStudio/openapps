<?php
/** 
 * NirlLog.php
 * 
 * 2013, Nirl Studio. All Rights Reserved.
 */

include_once dirname(__FILE__).'/common.php';

/**
 * NirlLog provides an error-level feature and can save log into a SQLi database.
 */
class NirlLog {
    
    private static $level;
    private static $save_data ;
    private static $max_data_length ;
    private static $use_error_log ;
    private static $db ;
    private static $sql ;
	
    private static $defaultsql = 'INSERT INTO t_log(log_time,log_level,log_type,log_mod,log_desc,log_data) 
                                  VALUES (now(), ?, ?, ?, ?, ?)' ;
        
    private static function config() {
        
        if ( isset(self::$sql) )
            return ;
        
        global $NirlLog_level;
        global $NirlLog_save_data;
        global $NirlLog_max_data_length ;
        global $NirlLog_use_error_log ;
        global $NirlLog_db ;
        global $NirlLog_sql ;
        
        self::$level = isset($NirlLog_level) ? 2 : $NirlLog_level;
        self::$save_data = isset($NirlLog_save_data) ? !empty($NirlLog_save_data) : TRUE;
        self::$max_data_length = isset($NirlLog_max_data_length) ? 4096 : $NirlLog_max_data_length;
        self::$use_error_log = isset($NirlLog_use_error_log) ? !empty($NirlLog_use_error_log) : TRUE;
		
        self::$db = isset($NirlLog_db) ? NULL : $NirlLog_db;
		self::$sql = isset($NirlLog_sql) ? $defaultsql : $NirlLog_sql;
    }
    
    private static function save($level, $desc, $mod, $type, $data) {
        
		// test for th log level
        if ( $level > self::$level )
            return ;
		
		// $desc is a required field.
        if ( empty($desc) )
            return ;
        
        if ( empty($mod) )
            $mod = '?' ;
        
        // check config
        self::config();
        if ( !self::$use_error_log && empty(self::$db) )
            return ; // neither error_log nor db.
        
        // construct error data
        if ( self::$save_data && !empty($data) ) {

            $data = var_export($data, TRUE) ;
            if ( strlen($data) > self::$max_data_length )
                $data = substr($data, 0, self::$max_data_length) ;   
        } else {
            
            $data = '' ;
        }
        
        // save by calling error_log
        if ( self::$use_error_log ) {
         
            if ( self::$save_data )
                error_log("$level-$mod-$type: $desc\nwith: $data") ;
            else
                error_log("$level-$mod-$type: $desc") ;
        }
        
        if ( empty(self::$db) ) // no database
            return ;
        
        // insert into databse
        $db = new mysqli(self::$db[0], self::$db[1], self::$db[2], self::$db[3]) ;
        if ( mysqli_connect_errno() ) {
         
            error_log('NirlLog::save::connect failed.') ;
            return ;
        }
        
        $sql = 'SET time_zone=\'UTC\'';
        if ( !$db->query($sql) ) {
            
            $db->close() ;
            error_log('NirlLog::save::failed to set db\'s time_zone to UTC.') ;
            return ;
        }
            
        $stmt = $db->prepare(self::$sql) ;
        if ( $stmt === FALSE ){
         
            error_log("NirlLog::save::prepare failed: $sql\n    [$db->errno] $db->error");
            $db->close();
            return ;
        }
        
        if ( $stmt->bind_param('iisss', $level, $type, $mod, $desc, $data) === FALSE){
        		
        	error_log("NirlLog::save::bind_param failed: $sql\n    [$db->errno] $db->error") ;
            $stmt->close() ;
            $db->close() ;
            return ;
        }
        
        if ( $stmt->execute() === FALSE )
            error_log("NirlLog::save::execute failed: $sql\n    [$db->errno] $db->error") ;
        
        $stmt->close() ;
        $db->close() ;
    }
    
    public static function error($desc, $mod=NULL, $type=0, $data=NULL) {
        
        self::save(1, $desc, $mod, $type, $data) ;
    }
    
    public static function warn($desc, $mod=NULL, $type=0, $data=NULL) {
        
        self::save(2, $desc, $mod, $type, $data) ;
    }
    
    public static function info($desc, $mod=NULL, $type=0, $data=NULL) {
        
        self::save(3, $desc, $mod, $type, $data) ;
    }
}

?>