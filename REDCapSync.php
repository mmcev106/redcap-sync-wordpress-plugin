<?php

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

CONST REDCAP_SYNC_CRON_HOOK = 'redcap_sync_cron_hook';

class REDCapSync{
	const REDCAP_PROJECT = 'redcap_project';
	const REDCAP_RECORD = 'redcap_record';

	function initializePlugin(){
		add_action('init', function(){
			register_post_type(self::REDCAP_PROJECT);
			register_post_type(self::REDCAP_RECORD);
		});

		add_action(REDCAP_SYNC_CRON_HOOK, function($args){
			// We use call_user_func_array to call the cronHook() method because I had to wrap the arguments in an extra array due to closure errors on everything but the first arg.  I'm not sure why exactly...
			call_user_func_array(array($this, "cronHook"), [$args]);
		});

		add_action('admin_menu', function(){
			add_submenu_page('options-general.php', 'REDCap Sync', 'REDCap Sync', 'manage_options', 'redcap-sync', function(){
				?>
				<style>
					#redcap-sync-wrap input:not([type=submit]){
						width: 275px
					}

					#redcap-sync-wrap table td{
						padding-right: 20px;
					}
				</style>
				<div id="redcap-sync-wrap" class="wrap">
					<h1>REDCap Sync</h1>
					<?php
					if($_SERVER['REQUEST_METHOD'] === 'POST'){
						$this->handlePost();
					}
					?>
					<br>

					<h3>Projects</h3>
					<table>
					<?php
					$query = $this->getProjectQuery();
					if($query->have_posts()){
						while($query->have_posts()){
							$query->the_post();
							?>
							<tr>
								<td><?=$this->get_post_meta('title')?> - PID <?=$this->get_post_meta('pid')?> at <?=$this->get_post_meta('url')?></td>
								<td>
									<form method="post">
										<input type="hidden" name="id-to-remove" value="<?=get_the_ID()?>">
										<button>Remove</button>
									</form>
								</td>
							</tr>
							<?php
						}
					}
					else{
						echo '<tr><td>None</tr></td>';
					}
					?>
					</table>
					<br>
					<br>
					<h3>Add A New Project</h3>
					<form method="post">
						<input name="url" placeholder="REDCap URL">
						<input name="token" placeholder="API Token">
						<button>Add</button>
					</form>
				</div>
				<?php
			});
		});
	}

	private function cronHook($jsonArgs)
	{
		$args = json_decode($jsonArgs, true);

		$url      = @$args['url'];
		$pid      = @$args['project-id'];
		$eventId  = @$args['event-id'];
		$recordId = @$args['record-id'];
		$action   = @$args['action'];

		try{
			$wordPressProjectId = $this->getWordPressProjectId(['url' => $url, 'pid' => $pid]);
			if (!$wordPressProjectId) {
				throw new Exception("Could not find project for url $url and pid $pid.");
			}

			$token = get_post_meta($wordPressProjectId, 'token', true);
			$recordIdFieldName = get_post_meta($wordPressProjectId, 'record_id_field_name', true);

			if($action == 'update-data-dictionary'){
//				$this->updateDataDictionary($args);
				throw new Exception("Action not implemented");
			}
			else if($action == 'update-record'){
				$this->updateRecord($url, $pid, $token, $recordIdFieldName, $eventId, $recordId);
			}
			else if($action == 'delete-record'){
				$this->deleteRecord($url, $pid, $recordIdFieldName, $recordId);
			}
			else{
				throw new Exception("Unknown action: $action");
			}
		}
		catch(Exception $e){
			$this->sendErrorEmail("The update request with the following arguments threw an exception:\n$jsonArgs\n\n" . $e->__toString());
		}
	}

	private function updateRecord($url, $pid, $token, $recordIdFieldName, $eventId, $recordId){

		$response = $this->request($url, $token, [
			'content' => 'record',
			'events' => $eventId,
			'filterLogic' => "([$recordIdFieldName] = '$recordId')"
		]);

		$recordData = $response[0];
		if(empty($recordData)){
			throw new Exception("Record data was empty");
		}

		$recordMetadataKeys = [
			'url' => $url,
			'pid' => $pid,
			'event_id' => $eventId,
			$recordIdFieldName => $recordId
		];

		$postData = [
			'post_type' => self::REDCAP_RECORD,
			'post_status' => 'publish',
			'meta_input' => array_merge($recordData, $recordMetadataKeys)
		];

		$wordPressRecordId = $this->getWordPressRecordId($recordMetadataKeys);
		if($wordPressRecordId){
			// This is an existing record.  Add the record id to the $postData so the existing record will be updated.
			$postData['ID'] = $wordPressRecordId;
		}

		// This method handles both inserts and updates.
		$id = wp_insert_post($postData);

		if(!$id){
			throw new Exception("An error occurred while adding/updating the record");
		}
	}

	private function deleteRecord($url, $pid, $recordIdFieldName, $recordId){
		if(empty($recordId)){
			throw new Exception("You must specify the record to delete a record.");
		}

		$wordPressRecordId = $this->getWordPressRecordId([
			'url' => $url,
			'pid' => $pid,
			$recordIdFieldName => $recordId
		]);

		if(empty($wordPressRecordId)){
			throw new Exception("Can't delete record because it does not exist.");
		}

		if(wp_delete_post($wordPressRecordId) === false){
			throw new Exception("An error occurred while deleting the record post!");
		}
	}

	private function handlePost(){
		$id = @$_POST['id-to-remove'];
		if (!empty($id)) {
			if(wp_delete_post($id) === false){
				throw new Exception("An error occurred while deleting the project post!");
			}
		} else {
			$url = $_POST['url'];
			$token = $_POST['token'];

			$this->addProject($url, $token);
		}
	}

	private function getWordPressProjectId($metadata){
		$query = $this->getProjectQuery($metadata);
		return $this->getSinglePostId($query, $metadata);
	}

	private function getWordPressRecordId($metadata){
		$query = $this->getRecordQuery($metadata);
		return $this->getSinglePostId($query, $metadata);
	}

	private function getSinglePostId($query, $metadata){
		if($query->have_posts()){
			$query->the_post();

			if($query->have_posts()){
				throw new Exception("Multiple {$query->query['post_type']} posts found matching metadata: " . json_encode($metadata));
			}

			return get_the_ID();
		}

		return null;
	}

	function getMetadataQuery($postType, $metadata = null){
		$queryArgs = [
			'post_type' => $postType
		];

		if($metadata){
			$metaQuery = ['relation' => 'AND'];

			foreach($metadata as $key=>$value){
				$metaQuery[] = [
					'key' => $key,
					'value' => $value
				];
			}

			$queryArgs['meta_query'] = $metaQuery;
		}

		return new WP_Query($queryArgs);
	}

	private function getProjectQuery($metadata = null){
		return $this->getMetadataQuery(self::REDCAP_PROJECT, $metadata);
	}

	private function getRecordQuery($metadata = null){
		return $this->getMetadataQuery(self::REDCAP_RECORD, $metadata);
	}

	private function addProject($url, $token){
		if($this->getWordPressProjectId(['url'=>$url, 'token'=>$token])){
			echo "This project has already been added.<br>";
			return;
		}

		$response = $this->request($url, $token, [
			'content' => 'project'
		]);

		$pid = $response['project_id'];
		$title = $response['project_title'];

		$response = $this->request($url, $token, [
			'content' => 'metadata'
		]);

		$id = wp_insert_post([
			'post_type' => self::REDCAP_PROJECT,
			'post_status' => 'publish',
			'meta_input' => [
				'pid' => $pid,
				'url' => $url,
				'token' => $token,
				'title' => $title,
				'record_id_field_name' => $response[0]['field_name']
			]
		]);

		if(!$id){
			echo 'An error occurred while adding the project post.<br>';
		}
	}

	private function request($url, $token, $params){
		$params = array_merge([
			'token' => $token,
			'content' => 'record',
			'format' => 'json',
			'type' => 'flat',
			'rawOrLabel' => 'raw',
			'rawOrLabelHeaders' => 'raw',
			'exportCheckboxLabel' => 'false',
			'exportSurveyFields' => 'false',
			'exportDataAccessGroups' => 'false'
		], $params);

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url . '/api/');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_AUTOREFERER, true);
		curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
		curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params, '', '&'));

		$output = curl_exec($ch);
		curl_close($ch);

		$response = json_decode($output, true);

		// We may have requests in the future where an empty response could be expected, in which case we should move the empty() check here to each caller.
		if(empty($response) || !is_array($response)){
			throw new Exception("An unknown error occurred when parsing the API response: " . json_encode($response));
		}
		else if(!empty($response['error'])){
			throw new Exception("The API returned an error: " . $response['error']);
		}

		return $response;
	}

	private function get_post_meta($key = null, $single = true){
		global $post;
		return get_post_meta($post->ID, $key, $single);
	}

	// This method is really just intended for troubleshooting.
	private function dumpRecords($metadata = null){
		echo "<h2>Records</h2>";
		$query = $this->getRecordQuery($metadata);
		while($query->have_posts()){
			$query->the_post();
			echo '<pre>';
			var_dump($this->get_post_meta());
			echo '</pre>';
		}
	}

	public function sendErrorEmail($body){
		$adminEmail = get_bloginfo('admin_email');
		if(!wp_mail($adminEmail, "REDCap Sync Plugin Error", "$body")){
			error_log(REDCAP_SYNC_CRON_HOOK . ': An error occurred while sending email with body: ' . $body);
		}
	}
}
