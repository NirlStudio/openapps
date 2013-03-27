<?php
/** 
 * NirlService.php
 * 
 * 2013, Nirl Studio. All Rights Reserved.
 */

include_once dirname(__FILE__).'/NirlLog.php' ;
include_once dirname(__FILE__).'/NirlAuth.php' ;
include_once dirname(__FILE__).'/NirlShield.php' ;
include_once dirname(__FILE__).'/NirlSession.php' ;

/**
 * This abstract class automatically map operations to its member functions.
 */
class NirlAutoMapService {
    
    /**
	 * derived classes must override this to map all exposed method names
	 * to its member functions' name.
	 */
    protected static $methods = array() ; // array('op' ==> 'func_name', ...) 
    
    /**
	 * the method which really execute an action.
	 * if the $action is null, the ::process method should try to parse action by itself.
	 */
    public static function exec($action){
        
        $clsname = get_called_class() ;
        $obj = new $clsname() ;
        return $obj->process($action) ;
    }
    
    /**
	 * a service could be invoked directly by calling XXXService:run() in an app entry file.
	 */ 
    public static function run() {
        
        static::exec(NULL) ;
    }
    
    /**
	 * To read public names of exposed methods.
	 */ 
    public static function getMeta() {
        
        return array_keys(static::$methods) ;
    }
    
    /**
	 * To get the data part for an action.
	 */ 
    public function getData($action, $usebody) {
        
        if ( isset($action['_d'])  ) {
        	
        	// preferentially use the '_d' entry.
            return $action['_d'] ;	
        } else if ( !$usebody || $_SERVER['REQUEST_METHOD'] != 'POST') {
        	
        	// return an empty string if no action data.
        	return '' ;
		}
        
		// this class is not designed to process a mass of data in a single action.
		if ( intval($_SERVER['CONTENT_LENGTH']) > 0x10000 ) {
			
			// by default, maximum length is 64K.
			self::invalidCall(NirlAuthDigest::getUserID(),
							   'NirlAutoMapService::getData', 
							   $action, 
							   'data is too long.') ;
		}
		
		// use the request body for a POST request.		
        return file_get_contents('php://input') ;	
    }
    
    /**
	 * To prepare the result to be delivered to client.
	 */ 
    public function formatResult($result) {
        
		// keep it if it's null.
        if ( $result == NULL )
            return $result ;
        
		if ( is_array($result)) {// if it's an array already, 
        	
            // insert a 200-OK status code if without one.
            if ( !isset($result['status']) )
                $result['status'] = 200 ;
        } else { // if it's not an array, 
        
            // wrap it into an array and insert a 200-OK status code.
            $result = array('status'=>200, 'result'=>$result) ;
        }
		
        return $result ;
    }
    
    /**
	 * To invoke the implementation function for a method and its data.
	 */ 
    public function invoke($method, $data) {
        
        if ( !isset(static::$methods[$method]) )
            self::unknownMethod($method, $data) ;
        
        $func = static::$methods[$method] ;
		if ( empty($func) )
            self::unknownMethod($method, $data) ;
		
        return $this->{$func}($data) ;
    }
    
    /**
	 * To invoke the implementation for a method with an userid and its data.
	 */ 
    public function invokeWithUser($userid, $method, $data) { 
        
        if ( empty(static::$methods[$method]) )
            self::unknownMethod($method, $data, $userid) ;
        
        $func = static::$methods[$method] ;
		if ( empty($func) )
            self::unknownMethod($method, $data, $userid) ;
        
        return $this->{$func}($userid, $data) ;
    }
    
    protected static function invalidCall($userid, $method, $data, $reason='', $type=90400){
        
        NirlShield::invalidCall($userid) ;
        if ( empty($userid))
            NirlLog::warn("$method:: $reason", get_called_class(), $type, $data) ;
        else
            NirlLog::warn("$method($userid):: $reason", get_called_class(), $type, $data) ;
        
        header('HTTP/1.1 400 Bad Request') ;
        die() ;
    }
    
    protected static function internalError($userid, $method, $data, $reason='', $type=90500){
        
        NirlShield::internalError($userid) ;
        if ( empty($userid))
            NirlLog::error("$method:: $reason", get_called_class(), $type, $data) ;
        else
            NirlLog::error("$method($userid):: $reason", get_called_class(), $type, $data) ;
        
        header('HTTP/1.1 500 Internal Server Error') ;
        die() ;
    }

    protected static function noMethod($userid=FALSE) {
        
        static::invalidCall($userid, '<????>', NULL, 'no method', 90404) ;
    }

    protected static function unknownMethod($method, $data, $userid=FALSE) {
        
        static::invalidCall($userid, $method, $data, 'unknown method', 90404) ;
    }
}

/**
 * The abstract class for all services which does not require an authenticated user.
 */
class NirlPublicService extends NirlAutoMapService{
	
	/**
	 * To execute an action in request handler mode or method-call mode.
	 */ 
    public function process($action=NULL, $useSession=FALSE) {
		
        if ( $useSession )
            NirlSession::manage() ;
        
        if ( empty($action) ) { // request handler mode

            $echoResult = TRUE ;  // use request body as arguments, and generate HTTP response.
            $action = $_REQUEST ; // the method name should be placed here.
        } else { // method-call mode
 
            $echoResult = FALSE ; // both method and data are in action, and not to generate HTTP reponse.
        }
        
        if ( !isset($action['_m']) )
            self::noMethod() ; // missing the field of method name.
            
		$method = $action['_m'] ;
		if ( empty($method) )
			self::noMethod() ; // no valid method name.
		
        $data = $this->getData($action, $echoResult) ;
        if ( !empty($data) && is_string($data) ) // if the data is a string,
            $args = json_decode($data, TRUE) ;   // the data must be a JSON string.
        else // if the data is already a strong-typed object.
            $args = $data ;// use it as arguments directly.
		
		// execute the method with arguments.
		$result = $this->invoke($method, $args) ;
		// try to standardize the result.
        $result = $this->formatResult($result) ;
        
		// for method-call mode or no non-empty result.
        if ( !$echoResult || $result == NULL )
            return $result ;
        
		// generate the response message.
   		header('Content-Type: application/json') ;
		echo json_encode($result) ; // the result also is returned as a JSON string.
        die() ;
	}
}

/**
 * The abstract class for all services which requires an authenticated user.
 */
class NirlProtectedService extends NirlAutoMapService {
	
	/**
	 * To execute an action in request handler mode or method-call mode.
	 */ 
    public function process($action=NULL) {
	
		// try to get a valid user id.
		$userid = NirlAuthDigest::requireUserID() ;
		if ( empty($userid) ) // if no valid user id, 
			die() ; // terminate current process immediately
        
        if ( empty($action) ) {  // request handler mode.
            
            $echoResult = TRUE ; // request body as argumnets, generate HTTP response.
            $action = $_REQUEST ;// to find method name in $_REQUEST. 
        } else { // method call mode. 
            
            $echoResult = FALSE ; // will not generate HTTP response.
        }
		
        if ( !isset($action['_m']) )
            self::noMethod($userid) ; // missing the field of method name.
		
        $method = $action['_m'] ;
		if ( empty($method) )
            self::noMethod($userid) ; // no valid method name.
        
        if ( $method == '_so') { // a default log off operation.
        
			NirlAuthDigest::clearUserID() ; // clear security context.
			die('Signed Off.') ; // by default, do nothing after signed off.
		}
		
        $data = $this->getData($action, $echoResult) ;
        if ( !empty($data) && is_string($data) ) // if the data is a string,
            $args = json_decode($data, TRUE) ;   // the data must be a JSON string.
        else // for any other type of data, 
            $args = $data ; // use the original data as arguments.
        
        // invoke the method with user id and its arguments.
        $result = $this->invokeWithUser($userid, $method, $args) ;
		// to standardize the result.
        $result = $this->formatResult($result) ;
        
		// for method-call mode or no non-empty result.
        if ( !$echoResult || $result == NULL )
            return $data ; // 
        
        // for request handler mode, 
        header('Content-Type: application/json') ;
        echo json_encode($result) ; // the result will be returned as a JSON string.
        die() ;
	}
}

/**
 * This class can merge mutltiple services functions into one url. 
 * It also can execute multiple actions in one request. 
 */
class NirlServiceGroup {
    
    /**
	 * derived classes must override this to include all service classes.
	 */
    protected static $include_services = array() ; // array('XXXSvc', ...)
	/**
	 * this field will save the map of method name to its implementation.
	 * It must be overriden by any derived class.
	 */
    protected static $services ;
    
	
    protected static function prepareService() {
        
        if ( isset(static::$services) ) 
            return ; // do nothing if this service group has been initialized.
        
        $tmp = array(); // use this local variable to avoid threading issue.
        foreach (static::$include_services as $alias => $svcname) {
            
			// get all public method names exposed by this service.
            $methods = call_user_func("$svcname::getMeta") ;
			if ( empty($methods) )
				continue ; // no exposed method.
			
			// to generate a closure 
			$exec = function($action=NULL) use ($svcname) {
				
				$processor = "$svcname::exec" ;
            	return call_user_func_array($processor, array($action)) ;
			} ;
			
			// map all these methods to this service's processor function.
            $tmp = array_merge($tmp, array_fill_keys($methods, $exec)) ;
			
			// map global names of methods to its service processor.
			if ( is_string($alias) ) {
				
				foreach($methods as $method)
					$tmp["$alias:$method"] = $exec ;
			} else {
				
				foreach($methods as $method)
					$tmp["$svcname:$method"] = $exec ;
			}
        }
        
        static::$services = $tmp ; // save the final service map.
    }
    
    protected static function getProcessor($method) {
        
        $processor = map_to(static::$services, $method) ;
        if ( empty($processor) ) // if can't find a valid processor.
            self::unknownMethod($method) ;
		
        return $processor ;
    }
    
    /**
	 * To handle current request.
	 */ 
    public static function process(){
        
        static::prepareService() ;
        
        $method = isset($_REQUEST['_m'])? $_REQUEST['_m'] : NULL ;
        if ( empty($method) )
            self::invalidCall('<????>', 'no method', 90902) ;
        
        if ( $method == '_so') { // a default log off operation.
            
            NirlAuthDigest::clearUserID() ; // clear security context.
            die('Signed Off.') ; // do nothing after log off.
        }
        
        if ( $method !== '_exec' ) { // if not for batch-mode,
            
            $processor = self::getProcessor($method) ;
            $processor() ; // generally, the process will terminate here.
            return ;
        }
        
		// for batch-mode : to execute multiple actions in a request.
		if ( isset($action['_d']) )
            $data = $action['_d'] ; // preferentially use the '_d' entery. 
        else // or to use the request body.
            $data = file_get_contents('php://input') ;
		
		if ( !empty($data) && is_string($data) ) // if the data is a string,
            $actions = json_decode($data, TRUE) ;   // the data must be a JSON string.
        else // if the data is already a strong-typed object.
            $actions = $data ;// use it as arguments directly.
            
        if ( empty($actions) ) 
            self::invalidCall($method, 'no action', 90903, $data) ;
        
        $response = array() ;
        foreach ($actions as $action) {
            
            if ( !isset($action['_m']) ) // found an invalid action entry.
                self::invalidCall($method, 'no method', 90904, $action) ;

            $method = $action['_m'] ; // look up for method name.
            $processor = self::getProcessor($method) ; // look up for processor. 
            
            $result = $processor($action) ; // execute the action.
            if ( $result == NULL )
                $result = array() ; // replace NULL with an empty array.
            
            if ( isset($action['_c']) ) // the action has a context object.
                $response[] = array('_m'=>$method, '_c'=>$action['_c'], '_d'=>$result) ;
            else // without a context object.
                $response[] = array('_m'=>$method, '_d'=>$result) ;
        }
        
		// to generate response body as JSON string.
        header('Content-Type: application/json') ;
        echo json_encode($response) ;
		die() ;
    }

    protected static function invalidCall($method, $reason, $type, $data=NULL) {
            
        NirlShield::invalidCall(NirlAuthDigest::getUserID()) ;
        NirlLog::warn("$method:: $reason", get_called_class(), $type, $data) ;
        
        header('HTTP/1.1 400 Bad Request') ;
        die() ;
    }

    protected static function unknownMethod($method, $data=NULL) {

        self::invalidCall($method, 'unknown method', 90905, $data) ;
    }
}

/**
 * This class provides basic functions to upload and download files.   
 */ 
class NirlFileService {

	public static function process($requireAuth=TRUE, $useSession=TRUE) {
	
        if ( $requireAuth ) {
        	
            // the user id is required.
            NirlSession::manage() ;
    		$userid = NirlAuthDigest::requireUserID() ;
	       	if ( empty($userid) ) die() ;
        } else if ( $useSession ) {
        	
			// try to get current user.
            NirlSession::manage() ;
            $userid = NirlAuthDigest::getUserID() ;
        } else {
            
			// use an invalid value as user id.
            $userid = 0 ;
        }
        
		$method = $_SERVER['REQUEST_METHOD'] ;
		switch ($method) {
			
			case 'GET' : // the GET request will always be taken as download operation.
				static::download($userid) ;
				break ;
			
			case 'POST' : // the POST request will always be taken as upload operation.
				static::upload($userid) ;
				break ;
				
			default : // other request methods are not supported.
				header('HTTP/1.1 501 Not implemented') ;
				die() ;
		}			
	}
	
	public static function download($userid) {
		
		// get file id from request.
		$fileid = $_REQUEST['f'] ;
		
		// try to load file content.
		$result = static::loadFile($userid, $fileid) ;
		if ( empty($result) )
			self::internalError($userid, $fileid, 'failed to load file.') ;
		
		// get file informations
		$name = $result['name'] ;
		$type = $result['type'] ;
		$size = $result['size'] ;
		$content = $result['content'] ;
		if ( $content === FALSE )
			self::internalError($userid, $fileid, 'failed to load file content.') ;
		
		// generate response header
		header("Content-length: $size") ;
		header("Content-type: $type") ;
		header("Content-Disposition: attachment; filename=$name") ;
		
		// write file content as response body.
		echo $content ;
		die() ;
	}

	public static function upload($userid) {
		
		// enumerate all files in request.
		foreach($_FILES as $file)
			static::saveFile($userid, $file) ;
	}
	
	protected static function saveFile($userid, $file) {
        
		// get file's properties
        $path = $file['tmp_name'] ;
		$name = $file['name'] ;
		$type = $file['type'] ;
		$size = $file['size'] ;	
		
		// try to find out file's real MIME type.
		if ( empty($type) )
			$type = file_mime_content_type($path) ;
		
		// validate file's properties.
		if ( !static::checkFile($userid, $path, $name, $type, $size) ) 
			self::invalidCall($userid, $name . '-' . $type . '-' . $size, 
			                  'failed to check file.') ;
		
		// convert file if it's necessary. for example: resize image for user's protrait.
		$path = static::convertFile($userid, $path, $name, $type, $size) ;
		if ( empty($path) ) 
			self::internalError($userid, $name . '-' . $type . '-' . $size, 
			                    'failed to convert file.') ;
		
		// get destination URI to save the uploaded file.
		$desturi = static::getDestUri($userid, $path, $name, $type, $size) ;
		if ( empty($desturi) ) 
			self::internalError($userid, $name . '-' . $type . '-' . $size, 
			                    'failed to get file\'s dest URI.') ;
	
		// try to save the file to destination URI
		$fileid = static::saveFile($userid, $path, $name, $type, $size, $desturi) ;
		if ( empty($fileid) ) 
			self::internalError($userid, $name . '-' . $type . '-' . $size, 
			                    'failed to save file.') ;
		
		// generate response and place the file id into the response body. 
		header('Content-Type: text/plain') ;
		if ( is_string($fileid) ) // if it's a string, 
			echo $fileid ;        // just return it
		else                       // if not a string
			echo strval($fileid) ; // convert it to a string.
		die();
	}
	
	protected static function loadFile($userid, $fileid) {
		
		// by default, use the safe relative file name as file id.
        if ( !is_safe_path_name($fileid) )
            self::invalidCall($userid, $fileid, 'invalid file id.') ;

		// get the path for this file.
        $root = static::getUploadRootPath($userid) ;
    	$path = $root . '/' . $fileid ;
		
		// get the file's name.
		$pinfo = pathinfo($path, PATHINFO_FILENAME) ;
		$name = $pinfo['filename'] ;
		
		// get file's MIME content type.
		$type = file_mime_content_type($path) ;
		
		// get file's size
		$size = filesize($path) ;
		if ( $size < 1 )
			self::internalError($userid, $fileid) ;
		
		// try to open file
		$fp = fopen($path, 'r') ;
        if ( $fp === FALSE ) // if failed to open the file. 
            return array('name'=>$name, 'type'=>$type, 'size'=>$size, 'content'=>FALSE) ;
        
		// read file's content.
		$content = fread($fp, $size) ;
		fclose($fp) ;
		
		// return the file's properties and its content.
		return array('name'=>$name, 'type'=>$type, 'size'=>$size, 'content'=>$content) ;
	}
	
	protected static function checkFile($userid, $path, $name, $type, $size) {
		
		// to be overridden - test whether the uploaded file is a valid one.
		return TRUE ; // always returns TRUE by default.
	}
	
	protected static function convertFile($userid, $path, $name, $type, $size) {
		
		// to be overridden - convert the file if necessary
		return $path ; // do nothing by default
	}
	
	protected static function getDestUri($userid, $path, $name, $type, $size){
		
		// could be overridden - generate destination URI.
		$name = safe_file_name($name) ;
		$now = time() ;
		return date('ymd', $now) . '/' . $userid . '/' . date('His', $now). '_' . $name ;
	}
	
	protected static function saveFile($userid, $path, $name, $type, $size, $desturi) {
		
		// could be overridden - save the file to storage and/or replace the $desturi
        $root = static::getUploadRootPath($userid) ;
        $destpath = $root . '/' . $desturi ;
		
		// by default, just move the uploaded file into destination folder.
		if ( !move_uploaded_file($path, $destpath)) 
			self::internalError($userid, $desturi . '-' . $type . '-' . $size, 
			                    'failed to move file to dest URI.') ;
								
		// use the desturi (relatie path) as file's URI.
		return $desturi ;
	}
    
    protected static function getUploadRootPath($userid) {
        
        // could be overridden - get the root path for saving uploaded files
        global $NirlFileService_upload_root_path ;
		
        if ( empty($NirlFileService_upload_root_path) )
            return '/var/www/upload' ; // Windows is not supported now.
        else 
            return $NirlFileService_upload_root_path ;
    }
	
	protected static function invalidCall($userid, $file, $reason=''){
		
        NirlShield::invalidCall($userid) ;
        if ( empty($userid) )
            NirlLog::warn($reason, get_called_class(), 91400, $file) ;
        else
            NirlLog::warn("($userid):: $reason", get_called_class(), 91400, $file) ;
        
		header('HTTP/1.1 400 Bad Request') ;
		die() ;
	}
	
	protected static function internalError($userid, $file, $reason=''){
		
        NirlShield::internalError($userid) ;
        if ( empty($userid) )
            NirlLog::error($reason, get_called_class(), 91500, $file) ;
        else
            NirlLog::error("($userid):: $reason", get_called_class(), 91500, $file) ;
        
		header('HTTP/1.1 500 Internal Server Error') ;
		die() ;
	}
}

?>