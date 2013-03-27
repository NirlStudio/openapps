<?php
/**
 * common.php
 *
 * 2013, Nirl Studio. All Rights Reserved.
 */

/* All modules in nirls/openapps depend on the time value of UTC. */
date_default_timezone_set('UTC') ;

/* Try to load configurations once. */
include_once dirname(__FILE__) . '/../config.php' ;

/**
 * To convert a hex string to a byte array.
 */
function hex_to_bytes($hexstr) {

    return pack('H*', $hexstr) ;
}

/**
 * To convert a byte array to a hex string.
 */
function bytes_to_hex($bytes) {

    $m = unpack('H*', $bytes) ;
    return $m[1];
}

/**
 * To test for whether an action has been executed once in current request's context.
 */
function executed_once($action) {

    if ( empty($action) ) // an empty action name is not allowed,
        return TRUE ;     // it will be taken as executed.

    if ( isset($_SERVER[$action]) )
        return TRUE ; // the status is retained in $_SERVER.

    // first time to be executed for the action.
    $_SERVER[$action] = TRUE ;
    return FALSE ;
}

/**
 * To get the MIME content type of a file.
 */
function file_mime_content_type($filename) {

    $info = new finfo(FILEINFO_MIME) ;
    if (is_resource($info) === TRUE)
        return $info->file($filename, FILEINFO_MIME_TYPE) ;

    return FALSE ;
}

/**
 * To remove unsafe characters in a file name string.
 */
function safe_file_name($filename) {

    if (strlen($filename) > 64)
        $filename = substr($filename, -64) ; // reserve the last 64 characters.

    // replace all unsafe characters to '_'.
    return preg_replace('/([^([0-9]|[a-z]|[A-Z]|[._])]|[.]{2})/', '_', $filename) ;
}

/**
 * To test whether the file name is a safe one.
 */
function is_safe_file_name($filename) {

    if (strlen($filename) > 64)
        return FALSE;

    return preg_match('/([^([0-9]|[a-z]|[A-Z]|[._])]|[.]{2})', $filename) === FALSE ;
}

/**
 * To test whether a full path string is a safe one.
 */
function is_safe_path_name($filename) {

    if (strlen($filename) > 256)
        return FALSE ;

    return preg_match('/([^([0-9]|[a-z]|[A-Z]|[._/])]|[.]{2})', $filename) === FALSE ;
}

/**
 * To test whether a string starts with a specific value.
 */
function str_starts($str, $value) {
    
    $length = strlen($value) ;
    return $length <= strlen($str) && (substr($str, 0, $length) === $value) ;
}

/**
 * To test whether a string ends with a specific value.
 */
function str_ends($str, $value) {
    
    $length = strlen($value) ;
    return $length <= strlen($str) && (substr($str, -$length) === $value) ;
}

/**
 * To map a key to a value with a default one.
 */
function map_to($map, $key, $default=NULL) {
    
    if ( empty($map) || empty($key) )
        return $default ; // both $map and $key must not be empty.
    
    if ( isset($map[$key]) )
        return $map[$key] ;
    else
        return $default ;
}

/**
 * To map a key to a non-empty value with a default one.
 */
function map_ne($map, $key, $default) {
    
    if ( empty($map) || empty($key) )
        return $default ; // both $map and $key must not be empty.
    
    if ( !isset($map[$key]) )
        return $default ;
    
    $value = $map[$key] ;
    if ( empty($value) )
        return $default ;
    else 
        return $value ;
}

/**
 * To test whether an IP address is a private one.
 */
function is_private_addr($ipaddr) {

    $addr = inet_pton($ipaddr) ;
    if ( $addr === FALSE )
        return FALSE ; // invalid address format.
    
    $len = strlen($addr);
    if ( $len !== 4 && $len !== 16 )
        return FALSE ; // invalid result for some unknown reasons.
    
    $bytes = unpack('C*', $addr) ; // convert string to byte array.
    if ( $len === 4 ) { // IPv4
    
        $a1 = $bytes[1] ; // it's a map not an array, so it starts from 1.
        $a2 = $bytes[2] ;
        return ( $a1 === 10 ) ||
               ( $a1 === 172 && ($a2 > 15 && $a2 < 32) ) ||
               ( $a1 === 192 && $a2 === 168 ) ;
        
    } else { // IPv6
        
        return ( $bytes[1] === 0xFD ) ; // FC00::/7
    }
}

/**
 * To get the real IP address of client side.
 */
function get_remote_addr() {

    // test for cached result
    if ( isset($_SERVER['_NU_REMOTE_ADDR']) )
        return $_SERVER['_NU_REMOTE_ADDR'] ;

    // possible headers containing the client ip address.
    $keys = array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 
                  'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 
                  'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR') ;

    foreach ($keys as $key) {

        if ( !isset($_SERVER[$key]) )
            continue ;

        // it may have multiple values seperated by ','.
        foreach (explode(',', $_SERVER[$key]) as $ip) {

            if ( filter_var($ip, FILTER_VALIDATE_IP) // a valid IP address string,  
                 && !is_private_addr($ip) ) {        // and not a private address

                $_SERVER['_NU_REMOTE_ADDR'] = $ip ;
                return $ip ;
            }
        }
    }

    // by default, always use the value of REMOTE_ADDR.
    $ip = $_SERVER['REMOTE_ADDR'] ;
    $_SERVER['_NU_REMOTE_ADDR'] = $ip ;
    return $ip ;
}

/**
 * To get the country code, country name and city name based on the remote address.
 */
function get_geo_country() {

    $ipaddr = get_remote_addr() ; // try to get real IP address.
    if ( empty($ipaddr) )
        return array('code' => '', 'name' => '', 'city' => '') ;

    $geo = geoip_record_by_name($ipaddr) ; // query by GeoIP.
    if ( empty($geo) )
        return array('code' => '', 'name' => '', 'city' => '') ;

    $code = map_to($geo, 'country_code3') ;  // try to use country_code3 firstly.
    if ( empty($code) )
        $code = map_ne($geo, 'country_code', '') ; // try to user country_code.

    $name = map_ne($geo, 'country_name', '') ; // display name of country.
    $city = map_ne($geo, 'city', '') ;         // name of city.

    return array('code' => $code, 
                 'name' => $name, 
                 'city' => strtolower($city)) ; // to simplify the comparasion.
}

?>
