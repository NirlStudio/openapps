<?php
/**
 * minifx.php
 * 
 * 2013, Nirl Studio. No Rights Reserved.
 */
include_once dirname(__FILE__).'/../common/NirlService.php';

/**
 * check a standard NirlService result, to determine whether to query for new data. 
 */
function minifx_have_update($result) {
    
    if ( is_array($result) && 
         isset($result['status']) && isset($result['updated']) ) 
        return $result['status'] == 200 && $result['updated'];
        
    return FALSE;
}

/**
 * This class should be extended by a server-side modal for a web view, which 
 * does not require user authentication.
 */
class MiniPublicModel extends NirlPublicService {
    
    protected static $methods = array(
        '_u' => 'update',  
        '_c' => 'check',
        '_q' => 'query',
        '_m' => 'more'
    );
    
    /**
     * application code need not to override this method.
     */
    public function update($data) {
        
        if ( empty($data) ) // without data stamp.
            return $this->query($data);
        
        $result = $this->check($data);  // check for update
        if ( minifx_have_update($result) )
            return $this->query($data); // model has been updated.
        else
            return $result; // no update
    }
    
    /**
     * application code could choose not to override this method, 
     * if it does not support incremental update of view model.
     */
    public function check($data) {
        
        // To be overridden.
        return array(
            'status'  => 200, // succeeded.
            'updated' => TRUE // have update.
        );
    }
    
    /**
     * application code must override this method to provide data for its view.
     */
    public function query($data) {
        
        // To be overridden.
        return array(
            'status' => 200,   // succeeded. 
            'stamp'  => array( // some data indicating the data version.
                'latest' => 0  // or jsut use the latest item id. 
            ),
            'mode'   => 'full',// 'full' or 'inc'
            'more'   => TRUE,  // more data to be fetched.
            'list'   => array()// list of data items.
        );
    }
    
    /**
     * application code must override this method to provide data for its view.
     */
    public function more($data) {
        
        // To be overridden.
        return array(
            'status' => 200,   // succeeded.
            'more'   => TRUE,  // more data to be fetched.
            'list'   => array()// list of data items.
        ); 
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
        '_q' => 'query',
        '_m' => 'more'
    );
    
    /**
     * application code need not to override this method.
     */
    public function update($userid, $data) {
        
        if ( empty($data) ) // without data stamp.
            return $this->query($userid, $data);
        
        $result = $this->check($userid, $data); // check for update
        if ( minifx_have_update($result) )
            return $this->query($userid, $data); // model has been updated.
        else
            return $result; // no update
    }
    
    /**
     * application code could choose not to override this method, 
     * if it does not support incremental update of view model.
     */
    public function check($userid, $data) {
        
        // To be overridden.
        return array(
            'status'  => 200, // succeeded.
            'updated' => TRUE // have update.
        );
    }
    
    /**
     * application code must override this method to provide data for its view.
     */
    public function query($userid, $data) {
        
        // To be overridden.
        return array(
            'status' => 200,   // succeeded. 
            'stamp'  => array( // some data indicating the data version.
                'latest' => 0  // or jsut use the latest item id. 
            ),
            'mode'   => 'full',// 'full' or 'inc'
            'more'   => TRUE,  // more data to be fetched.
            'list' => array()  // list of data items.
        );
    }
    
    /**
     * application code must override this method to provide data for its view.
     */
    public function more($userid, $data) {
        
        // To be overridden.
        return array(
            'status' => 200,   // succeeded.
            'more'   => TRUE,  // more data to be fetched.
            'list'   => array()// list of data items.
        );
    }
}

?>
