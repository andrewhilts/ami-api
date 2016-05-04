<?php
/*
Plugin Name: AMI API
*/
function ami_api_init( $server ) {
	global $ami_api_mytype;

	require_once dirname( __FILE__ ) . '/class-ami-api-mytype.php';
	$ami_api = new Ami_Api( $server );
	add_filter( 'json_endpoints', array( $ami_api, 'register_routes' ) );
	// $myplugin->register_filters();
}
function add_query_vars_filter( $vars ){
  $vars[] = "services";
  $vars[] = "banks";
  return $vars;
}

// Remove JSON API CORS HEADERS; manage in-server
// remove_filter( 'json_serve_request', 'json_send_cors_headers'             );

add_filter( 'query_vars', 'add_query_vars_filter' );
add_action( 'wp_json_server_before_serve', 'ami_api_init' , 100);
?>