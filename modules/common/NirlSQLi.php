<?php
/**
 * NirlSQLi.php
 * 
 * 2013, Nirl Studio. No Rights Reserved.
 */
 
include_once dirname(__FILE__).'/common.php';

/**
 * NirlSQLi provides a standard pattern to access SQLi/MariaDB.
 */
class NirlSQLi {
    
    private static $connections;
    
    private $dbname = NULL;
    private $db     = NULL;
    
    private $sql  = NULL;
    private $stmt = NULL;
    
    private $col_count = 0;
    private $col_names = NULL;
    
    private static function config() {

        if ( isset(self::$connections) ) 
            return;
        
        global $NirlSQLi_connections;
        if ( isset($NirlSQLi_connections) )
            self::$connections = $NirlSQLi_connections;
        
        if ( empty(self::$connections) ) 
            self::$connections = array();
    }
    
    private static function error($name, $db, $msg) {
        
        error_log("NirlSQLi::$msg\n [$name : $db->errno] $db->error");
    }
    
    private static function getConnection($dbname='*') {
        
        self::config();
        if ( empty($dbname) || // no explicit name. 
             !isset(self::$connections[$dbname]) ||
             empty(self::$connections[$dbname]) ) { 
            
            if ( $dbname !== '*' ) {
                $dbname = '*'; // use default db when a name is not defined.
            } else {
                error_log('NirlSQLi::getConnection::No available database.');
                return FALSE;
            }
        }
        
        if ( !isset(self::$connections[$dbname]) 
             || empty(self::$connections[$dbname]) ) {
            
            error_log("NirlSQLi::getConnection::Unknown database [$dbname].");
            return FALSE;
        }
        
        $params = self::$connections[$dbname] ;
        $db = new mysqli($params[0], $params[1], $params[2], $params[3]) ;
        if ( $db->connect_errno ) {
            
            error_log("NirlSQLi::getConnection::Failed to connect.\n [$dbname : $db->connect_errno] $db->connect_error.");
            return FALSE;
        }
            
        if ( !$db->query('SET time_zone=\'UTC\';') ) {
                
            self::error($dbname, $db, 'Failed to set time_zone to UTC.');
            $db->close();
            return FALSE;
        }
        
        return $db;
    }
    
    private function elog($msg, $types=NULL, $params=NULL) {
        
        if ( empty($this->db) )
            return;
        
        if ( !empty($this->sql) )
            $msg = "$msg\n - $this->sql";

        if ( !empty($types) || !empty($params) )
            $msg = "$msg\n - $types - " . var_export($params, TRUE);

        self::error($this->dbname, $this->db, $msg);
        $this->free();
    }
    
    private function innerExec($opr, $types, $params, $longdata=NULL, $try_insert=FALSE) {
        
        if ( empty($this->db) || empty($this->stmt) ) 
            return FALSE;
        
        $stmt = $this->stmt;
        if ( $types !== NULL || $params !== NULL || $longdata != NULL ) {
            
            if ( $this->bindParams($types, $params, $longdata!=NULL) === FALSE ) {
                
                $this->elog("$opr::bindParams failed.", $types, $params);
                return FALSE;
            }
            
            if ( $longdata != NULL 
                 && $stmt->send_long_data(count($params), $longdata) === FALSE ) {
                 
                $this->elog("$opr::send_long_data failed.", $types, $params);
                return FALSE;
            }
        }
        
        if ( $stmt->execute() === FALSE ) {
            
            if ( $try_insert && $this->db->errno == 1062 ) // duplicate key. 
                $this->free();
            else
                $this->elog("$opr::execute failed.", $types, $params);
            return FALSE;
        }
        
        if ( $stmt->store_result() === FALSE ) {
            
            $this->elog("$opr::store_result failed.", $types, $params);
            return FALSE;
        }
        
        return TRUE;
    }
    
    function __construct($dbname='*') {
        
        if ( empty($dbname) )
            $dbname = '*';
        
        $this->dbname = $dbname;
        $this->db = self::getConnection($dbname);
    }
    
    function __destruct() {
        
        $this->free();
    }
    
    /**
     * To prepare a sql statement before execute it.
     */
    public function prepare($sql) {
        
        if ( empty($this->db) ) 
            return FALSE;

        if ( !empty($this->stmt) ) {
            
            $this->stmt->free_result();
            $this->stmt->close();
        }   
            
        $this->sql = $sql;
        $this->stmt = $this->db->prepare($sql);
        if( $this->stmt )
            return TRUE;
        
        $this->elog("prepare failed.");
        return FALSE;
    }
    
    /**
     * To execute an INSERT statement with some parameters.
     */
    public function insert($types=NULL, $params=NULL, $longdata=NULL, $try=FALSE) {
        
        if ( $this->innerExec('insert', $types, $params, $longdata, $try) === FALSE )
            return FALSE;

        return isset($this->stmt->insert_id) ? $this->stmt->insert_id : TRUE;
    }
    
    /**
     * To execute an INSERT statement which might contain a duplicate key.
     */
    public function tryInsert($types=NULL, $params=NULL, $longdata=NULL) {
        
        return $this->insert($types, $params, $longdata, TRUE);
    }
    
    /**
     * To execute a DELETE statement with some parameters.
     */
    public function delete($types=NULL, $params=NULL) {
        
        if ( $this->innerExec('delete', $types, $params) === FALSE )
            return FALSE;

        return $this->stmt->affected_rows;
    }
    
    /**
     * To execute an UPDATE statement with some parameters.
     */
    public function update($types=NULL, $params=NULL, $longdata=NULL) {
        
        if ( $this->innerExec('update', $types, $params, $longdata) === FALSE )
            return FALSE;
         
        return $this->stmt->affected_rows;
    }
    
    /**
     * To execute a SELECT statement with some parameters.
     * The db connection will still be open after this call.
     */
    public function select($types=NULL, $params=NULL, $longdata=NULL) {
        
        if ( $this->innerExec('select', $types, $params, $longdata) === FALSE )
            return FALSE;

        if ( !$this->getColumns($types, $params) ) 
            return FALSE;

        if ( !$this->bindColumns() ) {
        
            $this->elog('select::bindColumns failed.', $types, $params);
            return FALSE;    
        }

        return TRUE;
    }
    
    /**
     * To directly execute an SELECT statement with some parameters.
     * The db connection will still be open after this call.
     */
    public function query($sql, $types=NULL, $params=NULL, $longdata=NULL) {
        
        if ( $this->prepare($sql) === FALSE )
            return FALSE;
        else
            return $this->select($types, $params, $longdata);
    }
    
    /**
     * To directly execute a SQL statement and return the number of affected rows.
     */
    public function exec($sql, $types=NULL, $params=NULL, $longdata=NULL) {
        
        if ( $this->prepare($sql) === FALSE )
            return FALSE;

        if ( $this->innerExec('exec', $types, $params, $longdata) === FALSE )
            return FALSE;

        $result = $this->db->affected_rows;
        $this->free();
        return $result;
    }
    
    /**
     * To directly execute a SQL statement in a database.
     */
    public static function execute($dbname, $sql, $types=NULL, $params=NULL, $longdata=NULL) {
        
        $db = new NirlSQLi($dbname);
        return $db->exec($sql, $types, $params, $longdata);
    }
    
    /**
     * To query the number of affected rows in previous operation.
     * For SELECT statements it works like rowsCount.
     */
    public function affectedRows() {
        
        if ( !empty($this->db) )
            return $this->db->affected_rows;
        else
            return 0;
    }
    
    /**
     * To query the row count after having executed a SELECT statement.
     */
    public function rowsCount() {
        
        if ( !empty($this->stmt) )
            return $this->stmt->num_rows;
        else
            return 0;
    }
    
    /**
     * To query the column count after having executed a SELECT statement.
     */
    public function fieldsCount() {
        
        return $this->col_count;
    }
    
    /**
     * To return the values of current row as an array. 
     * The db connection will still be open after this call.
     */
    public function fetchArray() {

        if ( !$this->stmt->fetch() )
            return FALSE;
            
        return $this->getValues();
    }
    
    /**
     * To return the field names and values of current row as a map.
     * The db connection will still be open after this call. 
     */
    public function fetchAssoc() {

        if ( !$this->stmt->fetch() )
            return FALSE;
            
        $values = $this->getValues();
        if ( $values === FALSE )
            return FALSE;
        
        return array_combine($this->col_names, $values);
    }
    
    /**
     * To return the values of current row as an array. 
     */
    public function getArray() {

        $result = $this->fetchArray();
        $this->free() ;
        return $result ;
    }
    
    /**
     * To return the field names and values of current row. 
     */
    public function getAssoc() {

        $result = $this->fetchAssoc();
        $this->free();
        return $result;
    }
    
    /**
     * To return the result set as an array of array. 
     */
    public function result($count = 0, $auto_free = TRUE) {
        
        $rows = array();
        if ( $count < 1 ) {
         
            while ($row = $this->fetchArray())
                array_push($rows, $row);   
        } else {
            
            while ($row = $this->fetchArray()) {
                if ( $count < 1 )
                    break;
                $count --;
                array_push($rows, $row);
            }
        }

        if ( $auto_free )
            $this->free();
        return $rows;
    }
    
    /**
     * To return the result set as an array of map. 
     */
    public function table($count = 0, $auto_free = TRUE) {
        
        $rows = array();
        if ( $count < 1 ) {
         
            while ($row = $this->fetchAssoc())
                array_push($rows, $row);   
        } else {
            
            while ($row = $this->fetchAssoc()) {
                if ( $count < 1 )
                    break;
                $count --;
                array_push($rows, $row);
            }
        }

        if ( $auto_free )
            $this->free();
        return $rows;
    }
    
    /**
     * To return values for a specific column index. 
     */
    public function valuesByColIndex($col) {

        $values = array();
        if ( $col < 0 || $col >= $this->col_count )
            return $values;
                
        while ($row = $this->fetchArray())
            array_push($values, $row[$col]);

        $this->free();
        return $values;
    }
    
    /**
     * To return values for a named column . 
     */
    public function valuesByColName($col) {
        
        $col = array_search($this->col_names, $col);
        if ( $col === FALSE )
            return array();
        else
            return $this->valuesByColIndex($col);
    }
    
    /**
     * To close database connection and release resource. 
     */
    public function free() {

        if ( !empty($this->stmt) ) {
            
            $this->stmt->free_result();
            $this->stmt->close();
            
            $this->stmt = NULL;
            $this->sql = NULL;
            $this->col_count = 0;
            $this->col_names = NULL;
            
            $this->clearValues();
        }

        if ( !empty($this->db) ) {

            $this->db->close();
            $this->db = NULL;
            $this->dbname = NULL;
        }
    }
    
    
    const MAX_PARAM_COUNT = 16;
    
    private function bindParams($types, $params, $has_longdata) {
        
        if ( $types == NULL )
            $types = '';
        
        if ( $params == NULL )
            $params = array();
        
        $count = strlen($types);
        if ( $count > self::MAX_PARAM_COUNT ) {
            
            $this->elog("exec::bindParams::too many parameters - $count.", 
                        $types, $params);
            return FALSE;
        }
        
        if ( $count !== count($params) ) {
            
            $this->elog("exec::bindParams - $types does not match $params.", 
                        $types, $params);
            return FALSE;
        }
        
        // no parameter to be binded.
        if ( $count < 1 && !$has_longdata )
            return TRUE;
        
        // automatically append a BLOB type if it's necessary.
        if ( $has_longdata )
            $types .= 'b';
        
        $pos = 0;
        $p00 = $pos >= $count || !isset($params[$pos]) ? NULL : $params[$pos]; $pos ++;
        $p01 = $pos >= $count || !isset($params[$pos]) ? NULL : $params[$pos]; $pos ++;
        $p02 = $pos >= $count || !isset($params[$pos]) ? NULL : $params[$pos]; $pos ++;
        $p03 = $pos >= $count || !isset($params[$pos]) ? NULL : $params[$pos]; $pos ++;
        $p04 = $pos >= $count || !isset($params[$pos]) ? NULL : $params[$pos]; $pos ++;
        $p05 = $pos >= $count || !isset($params[$pos]) ? NULL : $params[$pos]; $pos ++;
        $p06 = $pos >= $count || !isset($params[$pos]) ? NULL : $params[$pos]; $pos ++;
        $p07 = $pos >= $count || !isset($params[$pos]) ? NULL : $params[$pos]; $pos ++;
        $p08 = $pos >= $count || !isset($params[$pos]) ? NULL : $params[$pos]; $pos ++;
        $p09 = $pos >= $count || !isset($params[$pos]) ? NULL : $params[$pos]; $pos ++;
        $p10 = $pos >= $count || !isset($params[$pos]) ? NULL : $params[$pos]; $pos ++;
        $p11 = $pos >= $count || !isset($params[$pos]) ? NULL : $params[$pos]; $pos ++;
        $p12 = $pos >= $count || !isset($params[$pos]) ? NULL : $params[$pos]; $pos ++;
        $p13 = $pos >= $count || !isset($params[$pos]) ? NULL : $params[$pos]; $pos ++;
        $p14 = $pos >= $count || !isset($params[$pos]) ? NULL : $params[$pos]; $pos ++;
        $p15 = $pos >= $count || !isset($params[$pos]) ? NULL : $params[$pos]; $pos ++;
        
        $stmt = $this->stmt;
        switch ($count) {
            case 1:
                return $stmt->bind_param($types, $p00);
            case 2:
                return $stmt->bind_param($types, $p00, $p01);
            case 3:
                return $stmt->bind_param($types, $p00, $p01, $p02);
            case 4:
                return $stmt->bind_param($types, $p00, $p01, $p02, $p03);
            case 5:
                return $stmt->bind_param($types, $p00, $p01, $p02, $p03, $p04);
            case 6:
                return $stmt->bind_param($types, $p00, $p01, $p02, $p03, $p04, $p05);
            case 7:
                return $stmt->bind_param($types, $p00, $p01, $p02, $p03, $p04, $p05, $p06);
            case 8:
                return $stmt->bind_param($types, $p00, $p01, $p02, $p03, $p04, $p05, $p06, $p07);
            case 9:
                return $stmt->bind_param($types, $p00, $p01, $p02, $p03, $p04, $p05, $p06, $p07, $p08);
            case 10:
                return $stmt->bind_param($types, $p00, $p01, $p02, $p03, $p04, $p05, $p06, $p07, $p08, $p09);
            case 11:
                return $stmt->bind_param($types, $p00, $p01, $p02, $p03, $p04, $p05, $p06, $p07, $p08, $p09, $p10);
            case 12:
                return $stmt->bind_param($types, $p00, $p01, $p02, $p03, $p04, $p05, $p06, $p07, $p08, $p09, $p10, $p11);
            case 13:
                return $stmt->bind_param($types, $p00, $p01, $p02, $p03, $p04, $p05, $p06, $p07, $p08, $p09, $p10, $p11, $p12);
            case 14:
                return $stmt->bind_param($types, $p00, $p01, $p02, $p03, $p04, $p05, $p06, $p07, $p08, $p09, $p10, $p11, $p12, $p13);
            case 15:
                return $stmt->bind_param($types, $p00, $p01, $p02, $p03, $p04, $p05, $p06, $p07, $p08, $p09, $p10, $p11, $p12, $p13, $p14);
            case 16:
                return $stmt->bind_param($types, $p00, $p01, $p02, $p03, $p04, $p05, $p06, $p07, $p08, $p09, $p10, $p11, $p12, $p13, $p14, $p15);
                
            default:
                return FALSE;
        }
    }
    
    
    const MAX_FIELD_COUNT = 16;
    // a series of fields to store column values.
    private $cv0  = NULL;
    private $cv1  = NULL;
    private $cv2  = NULL;
    private $cv3  = NULL;
    private $cv4  = NULL;
    private $cv5  = NULL;
    private $cv6  = NULL;
    private $cv7  = NULL;
    private $cv8  = NULL;
    private $cv9  = NULL;
    private $cv10 = NULL;
    private $cv11 = NULL;
    private $cv12 = NULL;
    private $cv13 = NULL;
    private $cv14 = NULL;
    private $cv15 = NULL;
    
    private function getColumns($types, $params) {
        
        // try to query the meta data of result set.
        $meta = $this->stmt->result_metadata();
        if ( $meta === FALSE ) {
            
            $this->elog('select::getColumns::result_meta failed', $types, $params);
            return FALSE;
        }
        
        // try to query the information of fields.
        $fields = $meta->fetch_fields();
        if ( $fields === FALSE ) {
            
            $meta->free(); // it should be manually freed.
            $this->elog('select::getColumns::fetch_fields failed', $types, $params);
            return FALSE;
        }
        
        $this->col_count = count($fields); // count of fields.
        if ( $this->col_count < 1) {       // no data column in result set.
            
            $meta->free();
            $this->elog('select::getColumns::fetch_fields: count = 0', $types, $params);
            return FALSE;
        }
        
        if ( $this->col_count > self::MAX_FIELD_COUNT ) { // too many returned fields.
            
            $meta->free();
            $this->elog('select::getColumns::fetch_fields: count > 16', $types, $params);
            return FALSE;
        }
        
        $this->col_names = array();
        foreach ($fields as $field)
            array_push($this->col_names, $field->name);
        
        $meta->free();
        return TRUE;
    }
    
    private function bindColumns() {
        
        switch ($this->col_count) {
            
            case 1:
                return $this->stmt->bind_result($this->cv0);
            case 2:
                return $this->stmt->bind_result($this->cv0, $this->cv1);
            case 3:
                return $this->stmt->bind_result($this->cv0, $this->cv1, $this->cv2);
            case 4:
                return $this->stmt->bind_result($this->cv0, $this->cv1, $this->cv2, $this->cv3);
            case 5:
                return $this->stmt->bind_result($this->cv0, $this->cv1, $this->cv2, $this->cv3, $this->cv4);
            case 6:
                return $this->stmt->bind_result($this->cv0, $this->cv1, $this->cv2, $this->cv3, $this->cv4, $this->cv5);
            case 7:
                return $this->stmt->bind_result($this->cv0, $this->cv1, $this->cv2, $this->cv3, $this->cv4, $this->cv5, $this->cv6);
            case 8:
                return $this->stmt->bind_result($this->cv0, $this->cv1, $this->cv2, $this->cv3, $this->cv4, $this->cv5, $this->cv6, $this->cv7);
            case 9:
                return $this->stmt->bind_result($this->cv0, $this->cv1, $this->cv2, $this->cv3, $this->cv4, $this->cv5, $this->cv6, $this->cv7, $this->cv8);
            case 10:
                return $this->stmt->bind_result($this->cv0, $this->cv1, $this->cv2, $this->cv3, $this->cv4, $this->cv5, $this->cv6, $this->cv7, $this->cv8, $this->cv9);
            case 11:
                return $this->stmt->bind_result($this->cv0, $this->cv1, $this->cv2, $this->cv3, $this->cv4, $this->cv5, $this->cv6, $this->cv7, $this->cv8, $this->cv9, $this->cv10);
            case 12:
                return $this->stmt->bind_result($this->cv0, $this->cv1, $this->cv2, $this->cv3, $this->cv4, $this->cv5, $this->cv6, $this->cv7, $this->cv8, $this->cv9, $this->cv10, $this->cv11);
            case 13:
                return $this->stmt->bind_result($this->cv0, $this->cv1, $this->cv2, $this->cv3, $this->cv4, $this->cv5, $this->cv6, $this->cv7, $this->cv8, $this->cv9, $this->cv10, $this->cv11, $this->cv12);
            case 14:
                return $this->stmt->bind_result($this->cv0, $this->cv1, $this->cv2, $this->cv3, $this->cv4, $this->cv5, $this->cv6, $this->cv7, $this->cv8, $this->cv9, $this->cv10, $this->cv11, $this->cv12, $this->cv13);
            case 15:
                return $this->stmt->bind_result($this->cv0, $this->cv1, $this->cv2, $this->cv3, $this->cv4, $this->cv5, $this->cv6, $this->cv7, $this->cv8, $this->cv9, $this->cv10, $this->cv11, $this->cv12, $this->cv13, $this->cv14);
            case 16:
                return $this->stmt->bind_result($this->cv0, $this->cv1, $this->cv2, $this->cv3, $this->cv4, $this->cv5, $this->cv6, $this->cv7, $this->cv8, $this->cv9, $this->cv10, $this->cv11, $this->cv12, $this->cv13, $this->cv14, $this->cv15);

            default:
                return FALSE;
        }
    }
    
    private function getValues() {
        
        switch ($this->col_count) {
            
            case 1:
                return array($this->cv0);
            case 2:
                return array($this->cv0, $this->cv1);
            case 3:
                return array($this->cv0, $this->cv1, $this->cv2);
            case 4:
                return array($this->cv0, $this->cv1, $this->cv2, $this->cv3);
            case 5:
                return array($this->cv0, $this->cv1, $this->cv2, $this->cv3, $this->cv4);
            case 6:
                return array($this->cv0, $this->cv1, $this->cv2, $this->cv3, $this->cv4, $this->cv5);
            case 7:
                return array($this->cv0, $this->cv1, $this->cv2, $this->cv3, $this->cv4, $this->cv5, $this->cv6);
            case 8:
                return array($this->cv0, $this->cv1, $this->cv2, $this->cv3, $this->cv4, $this->cv5, $this->cv6, $this->cv7);
            case 9:
                return array($this->cv0, $this->cv1, $this->cv2, $this->cv3, $this->cv4, $this->cv5, $this->cv6, $this->cv7, $this->cv8);
            case 10:
                return array($this->cv0, $this->cv1, $this->cv2, $this->cv3, $this->cv4, $this->cv5, $this->cv6, $this->cv7, $this->cv8, $this->cv9);
            case 11:
                return array($this->cv0, $this->cv1, $this->cv2, $this->cv3, $this->cv4, $this->cv5, $this->cv6, $this->cv7, $this->cv8, $this->cv9, $this->cv10);
            case 12:
                return array($this->cv0, $this->cv1, $this->cv2, $this->cv3, $this->cv4, $this->cv5, $this->cv6, $this->cv7, $this->cv8, $this->cv9, $this->cv10, $this->cv11);
            case 13:
                return array($this->cv0, $this->cv1, $this->cv2, $this->cv3, $this->cv4, $this->cv5, $this->cv6, $this->cv7, $this->cv8, $this->cv9, $this->cv10, $this->cv11, $this->cv12);
            case 14:
                return array($this->cv0, $this->cv1, $this->cv2, $this->cv3, $this->cv4, $this->cv5, $this->cv6, $this->cv7, $this->cv8, $this->cv9, $this->cv10, $this->cv11, $this->cv12, $this->cv13);
            case 15:
                return array($this->cv0, $this->cv1, $this->cv2, $this->cv3, $this->cv4, $this->cv5, $this->cv6, $this->cv7, $this->cv8, $this->cv9, $this->cv10, $this->cv11, $this->cv12, $this->cv13, $this->cv14);
            case 16:
                return array($this->cv0, $this->cv1, $this->cv2, $this->cv3, $this->cv4, $this->cv5, $this->cv6, $this->cv7, $this->cv8, $this->cv9, $this->cv10, $this->cv11, $this->cv12, $this->cv13, $this->cv14, $this->cv15);
            
            default:
                return array();
        }
    }
    
    private function clearValues() {
        
        // clear all stored column values.
        $this->cv0  = NULL;
        $this->cv1  = NULL;
        $this->cv2  = NULL;
        $this->cv3  = NULL;
        $this->cv4  = NULL;
        $this->cv5  = NULL;
        $this->cv6  = NULL;
        $this->cv7  = NULL;
        $this->cv8  = NULL;
        $this->cv9  = NULL;
        $this->cv10 = NULL;
        $this->cv11 = NULL;
        $this->cv12 = NULL;
        $this->cv13 = NULL;
        $this->cv14 = NULL;
        $this->cv15 = NULL;
    }
}

?>