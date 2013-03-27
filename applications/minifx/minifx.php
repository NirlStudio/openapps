<?php
/**
 * minifx.php
 * 
 * 2013, Nirl Studio. All Rights Reserved.
 */
include_once dirname(__FILE__).'../../modules/common/NirlService.php' ;

/**
 * check a standard NirlService result, to determine whether to query for new data. 
 */
function minifx_have_update($result) {
	
	if ( is_array($result) )
		return !empty($result['result']) ;
	else 
		return !empty($result) ;
}

/**
 * This class should be extended by a server-side modal for a web view, which 
 * does not require user authentication.
 */
class MiniPublicModel extends NirlPublicService {
	
	protected static $methods = array(
	    '_u' => 'update',  
	    '_c' => 'check',
		'_q' => 'query'
	) ;
	
	/**
	 * application code need not to override this method.
	 */
	public function update($args) {
		
		if ( empty($args) ) // without data stamp.
			return $this->query($args) ;
		
		$result = $this->check($args) ; // check for update
		if ( minifx_have_update($result) )
			return $this->query($args) ; // model has been updated.
		else
			return $result ; // no update
	}
	
	/**
	 * application code could choose not to override this method, 
	 * if it does not support versioning view model.
	 */
	public function check($args) {
		
		return TRUE ; // no update.
	}
	
	/**
	 * application code must override this method to provide data for its view.
	 */
	public function query($args) {
		
		return array() ; // no updated data.
	}
}

/**
 * This class should be extended by a server-side modal for a web view, which 
 * requires user authentication.
 */
class MiniProtectedModel extends NirlProtectedService {
	
	protected static $methods = array(
	    '_u' => 'update',
	    '_c' => 'check',
		'_q' => 'query'
	) ;
	
	/**
	 * application code need not to override this method.
	 */
	public function update($userid, $args) {
		
		if ( empty($args) ) // without data stamp.
			return $this->query($userid, $args) ;
		
		$result = $this->check($userid, $args) ; // check for update
		if ( minifx_have_update($result) )
			return $this->query($userid, $args) ; // model has been updated.
		else
			return $result ; // no update
	}
	
	/**
	 * application code could choose not to override this method, 
	 * if it does not support versioning view model.
	 */
	public function check($userid, $args) {
		
		return TRUE ; // no update.
	}
	
	/**
	 * application code must override this method to provide data for its view.
	 */
	public function query($userid, $args) {
		
		return array() ; // no updated data.
	}
}

?>
