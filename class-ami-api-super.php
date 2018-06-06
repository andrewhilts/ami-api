<?php
class Ami_API_Super extends WP_JSON_Posts {
	
	public function get_posts( $filter = array(), $context = 'view', $type = 'post', $page = 1, $args = array()) {
		$query = $args;

		// Validate post types and permissions
		$query['post_type'] = array();

		foreach ( (array) $type as $type_name ) {
			$post_type = get_post_type_object( $type_name );

			if ( ! ( (bool) $post_type ) || ! $post_type->show_in_json ) {
				return new WP_Error( 'json_invalid_post_type', sprintf( __( 'The post type "%s" is not valid' ), $type_name ), array( 'status' => 403 ) );
			}

			$query['post_type'][] = $post_type->name;
		}

		global $wp;

		// Allow the same as normal WP
		$valid_vars = apply_filters('query_vars', $wp->public_query_vars);

		// If the user has the correct permissions, also allow use of internal
		// query parameters, which are only undesirable on the frontend
		//
		// To disable anyway, use `add_filter('json_private_query_vars', '__return_empty_array');`

		if ( current_user_can( $post_type->cap->edit_posts ) ) {
			$private = apply_filters( 'json_private_query_vars', $wp->private_query_vars );
			$valid_vars = array_merge( $valid_vars, $private );
		}

		// Define our own in addition to WP's normal vars
		$json_valid = array( 'posts_per_page' );
		$valid_vars = array_merge( $valid_vars, $json_valid );

		// Filter and flip for querying
		$valid_vars = apply_filters( 'json_query_vars', $valid_vars );
		$valid_vars = array_flip( $valid_vars );

		// Exclude the post_type query var to avoid dodging the permission
		// check above
		unset( $valid_vars['post_type'] );

		foreach ( $valid_vars as $var => $index ) {
			if ( isset( $filter[ $var ] ) ) {
				$query[ $var ] = apply_filters( 'json_query_var-' . $var, $filter[ $var ] );
			}
		}

		// Special parameter handling
		$query['paged'] = absint( $page );

		$post_query = new WP_Query();
		$posts_list = $post_query->query( $query );
		$response   = new WP_JSON_Response();
		$response->query_navigation_headers( $post_query );

		if ( ! $posts_list ) {
			$response->set_data( array() );
			return $response;
		}

		// holds all the posts data
		$struct = array();

		$response->header( 'Last-Modified', mysql2date( 'D, d M Y H:i:s', get_lastpostmodified( 'GMT' ), 0 ).' GMT' );

		foreach ( $posts_list as $post ) {
			$post = get_object_vars( $post );

			// Do we have permission to read this post?
			if ( ! json_check_post_permission( $post, 'read' ) ) {
				continue;
			}

			$response->link_header( 'item', json_url( '/posts/' . $post['ID'] ), array( 'title' => $post['post_title'] ) );
			$post_data = $this->prepare_post( $post, $context );
			if ( is_wp_error( $post_data ) ) {
				continue;
			}

			$struct[] = $post_data;
		}
		$response->set_data( $struct );

		return $response;
	}
	protected function prepare_post( $post, $context = 'view' ) {
		// Holds the data for this post.
		$_post = array( 'ID' => (int) $post['ID'] );

		$post_type = get_post_type_object( $post['post_type'] );

		if ( ! json_check_post_permission( $post, 'read' ) ) {
			return new WP_Error( 'json_user_cannot_read', __( 'Sorry, you cannot read this post.' ), array( 'status' => 401 ) );
		}

		$previous_post = null;
		if ( ! empty( $GLOBALS['post'] ) ) {
			$previous_post = $GLOBALS['post'];
		}
		$post_obj = get_post( $post['ID'] );

		// Don't allow unauthenticated users to read password-protected posts
		if ( ! empty( $post['post_password'] ) ) {
			if ( ! json_check_post_permission( $post, 'edit' ) ) {
				return new WP_Error( 'json_user_cannot_read', __( 'Sorry, you cannot read this post.' ), array( 'status' => 403 ) );
			}

			// Fake the correct cookie to fool post_password_required().
			// Without this, get_the_content() will give a password form.
			require_once ABSPATH . 'wp-includes/class-phpass.php';
			$hasher = new PasswordHash( 8, true );
			$value = $hasher->HashPassword( $post['post_password'] );
			$_COOKIE[ 'wp-postpass_' . COOKIEHASH ] = wp_slash( $value );
		}

		$GLOBALS['post'] = $post_obj;
		setup_postdata( $post_obj );

		// prepare common post fields
		$post_fields = array(
			'title'           => get_the_title( $post['ID'] ), // $post['post_title'],
			'status'          => $post['post_status'],
			'type'            => $post['post_type'],
			'author'          => (int) $post['post_author'],
			'content'         => apply_filters( 'the_content', $post['post_content'] ),
			'parent'          => (int) $post['post_parent'],
			#'post_mime_type' => $post['post_mime_type'],
			'link'            => get_permalink( $post['ID'] ),
		);

		$post_fields_extended = array(
			'slug'           => $post['post_name'],
			'guid'           => apply_filters( 'get_the_guid', $post['guid'] ),
			'excerpt'        => $this->prepare_excerpt( $post['post_excerpt'] ),
			'menu_order'     => (int) $post['menu_order'],
			'comment_status' => $post['comment_status'],
			'ping_status'    => $post['ping_status'],
			'sticky'         => ( $post['post_type'] === 'post' && is_sticky( $post['ID'] ) ),
		);

		$post_fields_raw = array(
			'title_raw'   => $post['post_title'],
			'content_raw' => $post['post_content'],
			'excerpt_raw' => $post['post_excerpt'],
			'guid_raw'    => $post['guid'],
			'post_meta'   => $this->handle_get_post_meta( $post['ID'] ),
		);

		// Dates
		$timezone = json_get_timezone();

		if ( $post['post_date_gmt'] === '0000-00-00 00:00:00' ) {
			$post_fields['date']              = null;
			$post_fields_extended['date_tz']  = null;
			$post_fields_extended['date_gmt'] = null;
		}
		else {
			$post_date                        = WP_JSON_DateTime::createFromFormat( 'Y-m-d H:i:s', $post['post_date'], $timezone );
			$post_fields['date']              = json_mysql_to_rfc3339( $post['post_date'] );
			$post_fields_extended['date_tz']  = $post_date->format( 'e' );
			$post_fields_extended['date_gmt'] = json_mysql_to_rfc3339( $post['post_date_gmt'] );
		}

		if ( $post['post_modified_gmt'] === '0000-00-00 00:00:00' ) {
			$post_fields['modified']              = null;
			$post_fields_extended['modified_tz']  = null;
			$post_fields_extended['modified_gmt'] = null;
		}
		else {
			$modified_date                        = WP_JSON_DateTime::createFromFormat( 'Y-m-d H:i:s', $post['post_modified'], $timezone );
			$post_fields['modified']              = json_mysql_to_rfc3339( $post['post_modified'] );
			$post_fields_extended['modified_tz']  = $modified_date->format( 'e' );
			$post_fields_extended['modified_gmt'] = json_mysql_to_rfc3339( $post['post_modified_gmt'] );
		}

		// Authorized fields
		// TODO: Send `Vary: Authorization` to clarify that the data can be
		// changed by the user's auth status
		if ( json_check_post_permission( $post, 'edit' ) ) {
			$post_fields_extended['password'] = $post['post_password'];
		}

		// Consider future posts as published
		if ( $post_fields['status'] === 'future' ) {
			$post_fields['status'] = 'publish';
		}

		// Fill in blank post format
		$post_fields['format'] = get_post_format( $post['ID'] );

		if ( empty( $post_fields['format'] ) ) {
			$post_fields['format'] = 'standard';
		}

		if ( 0 === $post['post_parent'] ) {
			$post_fields['parent'] = null;
		}

		if ( ( 'view' === $context || 'view-revision' == $context ) && 0 !== $post['post_parent'] ) {
			// Avoid nesting too deeply
			// This gives post + post-extended + meta for the main post,
			// post + meta for the parent and just meta for the grandparent
			$parent = get_post( $post['post_parent'], ARRAY_A );
			$post_fields['parent'] = $this->prepare_post( $parent, 'embed' );
		}

		// Merge requested $post_fields fields into $_post
		$_post = array_merge( $_post, $post_fields );

		// Include extended fields. We might come back to this.
		$_post = array_merge( $_post, $post_fields_extended );

		if ( 'edit' === $context ) {
			if ( json_check_post_permission( $post, 'edit' ) ) {
				$_post = array_merge( $_post, $post_fields_raw );
			} else {
				$GLOBALS['post'] = $previous_post;
				if ( $previous_post ) {
					setup_postdata( $previous_post );
				}
				return new WP_Error( 'json_cannot_edit', __( 'Sorry, you cannot edit this post' ), array( 'status' => 403 ) );
			}
		} elseif ( 'view-revision' == $context ) {
			if ( json_check_post_permission( $post, 'edit' ) ) {
				$_post = array_merge( $_post, $post_fields_raw );
			} else {
				$GLOBALS['post'] = $previous_post;
				if ( $previous_post ) {
					setup_postdata( $previous_post );
				}
				return new WP_Error( 'json_cannot_view', __( 'Sorry, you cannot view this revision' ), array( 'status' => 403 ) );
			}
		}

		// Entity meta
		$links = array(
			'self'       => json_url( '/posts/' . $post['ID'] ),
			'author'     => json_url( '/users/' . $post['post_author'] ),
			'collection' => json_url( '/posts' ),
		);

		if ( 'view-revision' != $context ) {
			$links['replies'] = json_url( '/posts/' . $post['ID'] . '/comments' );
			$links['version-history'] = json_url( '/posts/' . $post['ID'] . '/revisions' );
		}

		$_post['meta'] = array( 'links' => $links );

		if ( ! empty( $post['post_parent'] ) ) {
			$_post['meta']['links']['up'] = json_url( '/posts/' . (int) $post['post_parent'] );
		}

		$GLOBALS['post'] = $previous_post;
		if ( $previous_post ) {
			setup_postdata( $previous_post );
		}
		
		$res = apply_filters( 'json_prepare_post', $_post, $post, $context );
		unset($res['author']);
		unset($res['meta']['links']);
		unset($res['status']);
		unset($res['parent']);
		unset($res['link']);
		unset($res['format']);
		unset($res['guid']);
		unset($res['excerpt']);
		unset($res['menu_order']);
		unset($res['comment_status']);
		unset($res['ping_status']);
		unset($res['sticky']);
		unset($res['date_tz']);
		unset($res['date_gmt']);
		unset($res['modified_tz']);
		unset($res['modified_gmt']);
		unset($res['terms']);
		$res['id'] = $res['ID'];
		unset($res['ID']);
		return $res;
	}
}
?>
