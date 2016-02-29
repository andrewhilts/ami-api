<?php

require_once dirname( __FILE__ ) . '/class-ami-api-super.php';

class Ami_API extends Ami_API_Super {

	public function register_routes( $routes ) {
		$routes = parent::register_routes( $routes );
		$routes['/amicms/jurisdictions'] = array(
			array(array($this, 'get_jurisdictions'), WP_JSON_Server::READABLE),
		);
		$routes['/amicms/jurisdictions/(?P<id>\d+)'] = array(
			array(array($this, 'get_post'), WP_JSON_Server::READABLE),	
		);
		$routes['/amicms/jurisdictions/(?P<juris_id>\d+)/operators'] = array(
			array(array($this, 'get_jurisdiction_operators'), WP_JSON_Server::READABLE),	
		);
		$routes['/amicms/operators'] = array(
			array(array($this, 'get_operators'), WP_JSON_Server::READABLE),
		);
		$routes['/amicms/operators/(?P<id>\d+)'] = array(
			array(array($this, 'get_post'), WP_JSON_Server::READABLE),	
		);
		$routes['/amicms/operators/(?P<operator_id>\d+)/services'] = array(
			array(array($this, 'get_operator_services'), WP_JSON_Server::READABLE),
		);
		$routes['/amicms/operators/(?P<operator_id>\d+)/data_banks'] = array(
			array(array($this, 'get_operator_data_banks'), WP_JSON_Server::READABLE),
		);
		$routes['/amicms/components'] = array(
			array(array($this, 'get_request_components'), WP_JSON_Server::READABLE),
		);
		$routes['/amicms/data_banks/identifiers'] = array(
			array(array($this, 'get_data_bank_identifiers'), WP_JSON_Server::READABLE),
		);
		$routes['/amicms/services/identifiers'] = array(
			array(array($this, 'get_service_identifiers'), WP_JSON_Server::READABLE),
		);
		$routes['/amicms/services/(?P<service_id>\d+)/request_components'] = array(
			array(array($this, 'get_service_request_components'), WP_JSON_Server::READABLE),
		);
		$routes['/amicms/jurisdictions/(?P<jurisdiction_id>\d+)/industries'] = array(
			array(array($this, 'get_jurisdiction_industries'), WP_JSON_Server::READABLE),
		);
		$routes['/amicms/jurisdictions/(?P<jurisdiction_id>\d+)/industries/(?P<industry_id>\d+)/operators'] = array(
			array(array($this, 'get_jurisdiction_industry_operators'), WP_JSON_Server::READABLE),
		);
		$routes['/amicms/jurisdictions/(?P<jurisdiction_id>\d+)/industries/(?P<industry_id>\d+)/request_template'] = array(
			array(array($this, 'get_jurisdiction_industry_request_template'), WP_JSON_Server::READABLE),
		);
		return $routes;
	}
	public function get_request_components($filter = array(), $context = 'view', $page = 1 ){
		$service_ids = get_query_var('services', [] );
		if(is_array($service_ids) && empty($service_ids)){
			return [];
		}
		$args = array(
			'orderby' => 'title',
			'order'   => 'ASC',
			'post__in' => $service_ids
		);
		$services = $this->get_posts(array(), 'view', 'operator-service', 1, $args);

		$component_ids = array();
		foreach($services->data as $serviceKey => $service){
			if(isset($service['meta']['request_components'])){
				foreach($service['meta']['request_components'] as $componentKey => $component){
					$component_ids[] = $component;
				}
			}
		}
		$component_ids = array_unique($component_ids);

		$post_type = 'request-components';
		
		$args = array(
			'orderby' => 'title',
			'order'   => 'ASC',
			'post__in' => $component_ids
		);
		return $this->get_posts(array(), 'view', $post_type, 1, $args);
	}
	public function get_service_identifiers($filter = array(), $context = 'view', $page = 1 ){
		return $this->get_identifiers('services', $filter, $context, $page);
	}
	public function get_data_bank_identifiers($filter = array(), $context = 'view', $page = 1){
		return $this->get_identifiers('data-banks', $filter, $context, $page);
	}
	public function get_identifiers($type, $filter = array(), $context = 'view', $page = 1){
		switch($type){
			case "data-banks":
				$query_param = "banks";
				$post_type = 'data-bank';
			break;
			case "services":
				$query_param = "services";
				$post_type = 'operator-service';
			break;
		}
		$relation_ids = get_query_var($query_param, [] );
		if(is_array($relation_ids) && empty($relation_ids)){
			return [];
		}
		$args = array(
			'orderby' => 'title',
			'order'   => 'ASC',
			'post__in' => $relation_ids
		);
		$relations = $this->get_posts(array(), 'view', $post_type, 1, $args);
		// return $relations;
		$identifier_ids = array();
		foreach($relations->data as $relationKey => $relation){
			if(isset($relation['meta']['identifiers'])){
				if(is_array($relation['meta']['identifiers'])){
					foreach($relation['meta']['identifiers'] as $identifierKey => $identifier){
						$identifier_ids[] = $identifier;
					}
				}
				else{
					$identifier_ids[] = $relation['meta']['identifiers'];
				}
			}
		}
		$identifier_ids = array_unique($identifier_ids);

		$post_type = 'identifier';
		
		$args = array(
			'orderby' => 'title',
			'order'   => 'ASC',
			'post__in' => $identifier_ids
		);
		$identifier_posts = $this->get_posts(array(), 'view', $post_type, 1, $args);
		foreach ($identifier_posts->data as $key => $identifier_post) {
			$new_options = array();
			if(isset($identifier_post['meta']['field_options'])){
				$options = explode("\r\n", $identifier_post['meta']['field_options']);
				foreach ($options as $subkey => $option) {
					$arr = explode(' : ', $option);
					$new_options[] = array("value" => $arr[0], "title" => $arr[1]);
				}
				$identifier_posts->data[$key]['meta']['field_options'] = $new_options;
			}
		}
		$identifiers = array();
		$identifiers['basic_personal_info'] = array();

		
		foreach($relations->data as $relationKey => $relation){
			$identifier_weight = 0;
			$identifiers[$relation['id']] = array();
			if(isset($relation['meta']['identifiers'])){
				if(is_array($relation['meta']['identifiers'])){
					foreach($relation['meta']['identifiers'] as $identifierKey => $identifier){
						if($this->is_key_value_present_in_array($identifier_posts->data, 'id', $identifier)){
							$post = $this->get_array_by_key_value($identifier_posts->data, 'id', $identifier);
							if($post['meta']['basic_personal_info'] == "Yes"){
								$post['weight'] = $identifier_weight;
								$identifiers['basic_personal_info'][] = $post;
							}
							else{
								$post['weight'] = $identifier_weight;
								$identifiers[$relation['id']][] = $post;
							}
							$identifier_weight++;
						}
					}
				}
				else{
					if($this->is_key_value_present_in_array($identifier_posts->data, 'id', $relation['meta']['identifiers'])){
							$post = $this->get_array_by_key_value($identifier_posts->data, 'id', $relation['meta']['identifiers']);
							if($post['meta']['basic_personal_info'] == "Yes"){
								$post['weight'] = $identifier_weight;
								$identifiers['basic_personal_info'][] = $post;
							}
							else{
								$post['weight'] = $identifier_weight;
								$identifiers[$relation['id']][] = $post;
							}
							$identifier_weight++;
						}
				}
			}
		}
		$tmp = array();
		foreach ($identifiers['basic_personal_info'] as $k => $v) {
			if(isset($tmp[$v['id']])){
				if($tmp[$v['id']]['weight'] < $v['weight']){
					$tmp[$v['id']] = $v;
				}
			}
			else{
				$tmp[$v['id']] = $v;
			}
		}

		$identifiers['basic_personal_info'] = $tmp;
		return $identifiers;
	}
	public function get_jurisdictions($filter = array(), $context = 'view', $page = 1 ){
		$args = array(
			'orderby' => 'title',
			'order'   => 'ASC',
		);
		return $this->get_posts($filter, $context, 'jurisdiction', $page, $args);
	}
	public function get_operators($filter = array(), $context = 'view', $page = 1 ){
		$args = array(
			'orderby' => 'title',
			'order'   => 'ASC',
		);
		return $this->get_posts($filter, $context, 'operator', $page, $args);
	}
	public function get_jurisdiction_operators($juris_id){
		$post_type = 'operator';
		$args = array(
			'orderby' => 'title',
			'order'   => 'ASC',
			'meta_query' => array(
				array(
					'key' => 'jurisdiction',
					'value' => absint($juris_id),
					'compare' => 'LIKE'
				)
			)
		);
		return $this->get_posts(array(), 'view', $post_type, 1, $args);
	}
	public function get_jurisdiction_industries($jurisdiction_id){
		$operators = $this->get_jurisdiction_operators($jurisdiction_id);
		if(empty($operators->data)){
			return [];
		}

		$industry_ids = [];

		foreach ($operators->data as $operator_key => $operatorData) {
			if(isset($operatorData['meta']['operator_industry'])){
				$industry_ids[] = $operatorData['meta']['operator_industry'];
			}
		}

		$post_type = 'operator-industry';
		
		$args = array(
			'orderby' => 'title',
			'order'   => 'ASC',
			'post__in' => $industry_ids
		);
		return $this->get_posts(array(), 'view', $post_type, 1, $args);
	}
	public function get_operator_services($operator_id){
		//Get operator
		$operator = $this->get_post($operator_id);
		if(isset($operator->data["meta"]["services"])){
			$service_ids = $operator->data["meta"]["services"];
			if(!is_array($service_ids)){
				$service_ids = array($service_ids);
			}
		}
		else{
			return [];
		}

		$post_type = 'operator-service';
		$args = array(
			'orderby' => 'title',
			'order'   => 'ASC',
			'post__in' => $service_ids
		);
		return $this->get_posts(array(), 'view', $post_type, 1, $args);
	}
	public function get_operator_data_banks($operator_id){
		//Get operator
		$operator = $this->get_post($operator_id);
		if(isset($operator->data["meta"]["data_banks"])){
			$bank_ids = $operator->data["meta"]["data_banks"];
			if(!is_array($bank_ids)){
				$bank_ids = array($bank_ids);
			}
		}
		else{
			return [];
		}

		$post_type = 'data-bank';
		$args = array(
			'orderby' => 'title',
			'order'   => 'ASC',
			'post__in' => $bank_ids
		);
		return $this->get_posts(array(), 'view', $post_type, 1, $args);
	}

	public function get_service_request_components($service_id){
		//Get operator
		$service = $this->get_post($service_id);
		if(isset($service->data["meta"]["request_components"])){
			$request_component_ids = $service->data["meta"]["request_components"];
			if(!is_array($request_component_ids)){
				$request_component_ids = array($request_component_ids);
			}
		}
		else{
			return [];
		}
		$post_type = 'request-components';
		$args = array(
			'orderby' => 'title',
			'order'   => 'ASC',
			'post__in' => $request_component_ids
		);
		return $this->get_posts(array(), 'view', $post_type, 1, $args);
	}

	public function get_jurisdiction_industry_request_template($jurisdiction_id, $industry_id){
		$post_type = 'request-template';
		$args = array(
			'meta_query' => array(
				array(
					'key' => 'jurisdiction',
					'value' => absint($jurisdiction_id),
					'compare' => 'LIKE'
				),
				array(
					'key' => 'operator_industry',
					'value' => absint($industry_id),
					'compare' => 'LIKE'
				)
			)
		);
		return $this->get_posts(array(), 'view', $post_type, 1, $args);
	}

	public function get_jurisdiction_industry_operators($jurisdiction_id, $industry_id){
		$post_type = 'operator';
		$args = array(
			'meta_query' => array(
				array(
					'key' => 'jurisdiction',
					'value' => absint($jurisdiction_id),
					'compare' => 'LIKE'
				),
				array(
					'key' => 'operator_industry',
					'value' => absint($industry_id),
					'compare' => 'LIKE'
				)
			)
		);
		return $this->get_posts(array(), 'view', $post_type, 1, $args);
	}
	// ...
	public function is_key_value_present_in_array($array, $member, $value) {
	   foreach($array as $k => $v) {
	      if($v[$member] == $value){
	        return true;
	      }
	   }
	   return false;
}
	public function get_array_by_key_value($array, $member, $value) {
	   foreach($array as $k => $v) {
	      if($v[$member] == $value){
	        return $v;
	      }
	   }
	   return false;
}
}
?>