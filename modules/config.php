<?php
/**
 * config.php
 * 
 * the template file.
 *  
 */
 
/**
 * reset some ini configuration.
 */  
 ini_set('error_log', '/var/log/php/app.log');
 ini_set('display_errors', 'on'); // in development environment

/**
 * Configuration for NirlLog
 */  
 $NirlLog_level           = 3 ;    // information level - all
 $NirlLog_save_data       = TRUE ; // save addtional data 
 $NirlLog_max_data_length = 4096 ; // maximum data length.
 $NirlLog_use_error_log   = TRUE ; // write to the error_log at the same time.
 $NirlLog_db = array('db-server', 'db-user', 'db-pass', 'db-name') ;
 // $NirlLog_sql = 'INSERT INTO t_log(log_time,log_level,log_type,log_mod,log_desc,log_data) 
 //                 VALUES(now(), ?, ?, ?, ?, ?)' ;

/**
 * Configuration for NirlSession
 */
 // $NirlSession_max_life_time = 90000 ;
 // $NirlSession_max_idle_time = 1800 ;
 // $NirlSession_id_life_time  = 600 ;
 
/**
 * Configuration for NirlMemcached
 */
  /* 
  $NirlMemcached_server_groups = array(
      '*' => array(
          array('127.0.0.1', 11211)
      )
  ) ;
  */
  
/**
 * configuration for NirlSQLi
 */
 $NirlSQLi_connections = array(
     'name' => array('db-server', 'db-user', 'db-pass', 'db-name') // , ...
 ) ;
 
/**
 * Configuration for NirlShield
 */
 $NirlShield_enable = TRUE ;
 $NirlShield_policies = array(
     'guardIP'   => array('seconds'=>600, 'limit'=>600) ,
     'guardUser' => array('seconds'=>600, 'limit'=>600) ,
    
     'authAttempt' => array('seconds'=>600, 'limit'=>60) ,
     'authFailure' => array('seconds'=>600, 'limit'=>60) ,
    
     'invalidCallUser' => array('seconds'=>600, 'limit'=>120) ,
     'invalidCallIP'   => array('seconds'=>600, 'limit'=>120) ,
	
     'internalErrorUser' => array('seconds'=>600, 'limit'=>120) ,
     'internalErrorIP'   => array('seconds'=>600, 'limit'=>120)
 ) ;
 
/**
 * Configuration for NirlAuthDigest
 */
 $NirlAuthDigest_realm     = 'auth.nirls.net' ;
 $NirlAuthDigest_nonce_key = 'a9ae3^&*2l3dlkg@#pwe190j!@#@' ;
 // $NirlAuthDigest_nonce_lifetime = 600 ;
 // $NirlAuthDigest_cred_cookie    = 1800 ;
 // $NirlAuthDigest_sql_db_name = 'user' ;
 // $NirlAuthDigest_sql_query   = 'SELECT user_id AS `user_id`, passhash AS `hash`
 //                                FROM t_inst_auth WHERE inst_id=?' ;
 
/**
 * Configuration for NirlFileService
 */
 // $NirlFileService_upload_root_path = '/var/www/upload' ;
 
?>