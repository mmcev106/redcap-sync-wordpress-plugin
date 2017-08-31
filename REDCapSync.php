<?php
require_once __DIR__ . '/vendor/autoload.php';

use PHPSQLParser\PHPSQLParser;
use PHPSQLParser\PHPSQLCreator;

CONST REDCAP_SYNC_CRON_HOOK = 'redcap_sync_cron_hook';

class REDCapSync{
	const REDCAP_PROJECT = 'redcap_project';
	const REDCAP_FIELD = 'redcap_field';
	const REDCAP_RECORD = 'redcap_record';

	function initializePlugin(){
		add_action('init', function(){
			register_post_type(self::REDCAP_PROJECT);
			register_post_type(self::REDCAP_FIELD);
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
										<button class="remove-project">Remove</button>
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
					<form method="post" class="add-project">
						<input name="url" placeholder="REDCap URL">
						<input name="token" placeholder="API Token">
						<button>Add</button>
					</form>
				</div>
				<script>
					jQuery(function(){
						$ = jQuery

						$('#redcap-sync-wrap form.add-project').submit(function(){
							var button = $(this).find('button')
							button.prop('disabled', true)
							button.html('Adding...')
						})

						$('#redcap-sync-wrap button.remove-project').click(function(e){
							if(!confirm('Are you sure you want to remove this project and stop receiving updates from REDCap?')){
								e.preventDefault()
							}
						})
					})
				</script>
				<?php
			});
		});
	}

	private function cronHook($jsonArgs)
	{
		// Used for debugging.
		error_log('REDCap Sync Cron Hook Executiong: ' . $jsonArgs);

		$args = json_decode($jsonArgs, true);

		$url      = @$args['url'];
		$pid      = @$args['project-id'];
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
				$this->updateDataDictionary($url, $pid, $token);
			}
			else if($action == 'update-record'){
				$recordData = $this->getRecordDataFromREDCap($url, $token, $recordIdFieldName, $recordId);
				$this->insertOrUpdateRecord($url, $pid, $recordIdFieldName, $recordData);
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

	private function getRecordDataFromREDCap($url, $token, $recordIdFieldName, $recordId)
	{
		$response = $this->request($url, $token, [
			'content' => 'record',
			'filterLogic' => "([$recordIdFieldName] = '$recordId')"
		]);

		$recordData = $response[0];
		if(empty($recordData)){
			throw new Exception("Record data was empty");
		}

		return $recordData;
	}

	private function insertOrUpdateRecord($url, $pid, $recordIdFieldName, $recordData){
		$this->insertOrUpdateProjectMetadata(self::REDCAP_RECORD, $url, $pid, $recordIdFieldName, $recordData);
	}

	private function insertOrUpdateField($url, $pid, $data){
		$this->insertOrUpdateProjectMetadata(self::REDCAP_FIELD, $url, $pid, 'field_name', $data);
	}

	private function insertOrUpdateProjectMetadata($postType, $url, $pid, $primaryKeyFieldName, $data){
		$primaryKey = $data[$primaryKeyFieldName];
		$metadataKeys = [
			'url' => $url,
			'pid' => $pid,
			$primaryKeyFieldName => $primaryKey
		];

		$newPostMeta = array_merge($data, $metadataKeys);

		$postData = [
			'post_type' => $postType,
			'post_status' => 'publish'
		];

		$query = $this->getMetadataQuery($postType, $metadataKeys);
		$existingPostId = $this->getSinglePostId($query, $metadataKeys);
		if($existingPostId){
			// This is an existing post.  Add the id to the $postData so the existing post will be updated.
			$postData['ID'] = $existingPostId;

			if($postType == self::REDCAP_RECORD){
				$this->deleteCachedFiles($url, $pid, $primaryKey);
			}

			$oldPostMeta = get_post_meta($existingPostId);
			foreach($oldPostMeta as $key=>$value){
				if(!isset($newPostMeta[$key])){
					// This field no longer exists on the record.  Remove the old value from WordPress.
					delete_post_meta($existingPostId, $key);
				}
			}
		}

		$postData['meta_input'] = $newPostMeta;

		// This method handles both inserts and updates.
		$id = wp_insert_post($postData);

		if(!$id){
			throw new Exception("An error occurred while adding/updating the post: " . json_encode($postData));
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

		$this->deleteCachedFiles($url, $pid, $recordId);

		if(empty($wordPressRecordId)){
			throw new Exception("Can't delete record because it does not exist.");
		}

		if(wp_delete_post($wordPressRecordId) === false){
			throw new Exception("An error occurred while deleting the record post!");
		}
	}

	private function deleteCachedFiles($url, $pid, $recordId){
		$domain = $this->getDomain($url);
		$this->rrmdir($this->getFileCacheDir($domain, $pid, $recordId));
	}

	public function getFileCacheDir($domain, $pid, $recordId){
		if(empty($domain) || empty($pid) || empty($recordId)){
			throw new Exception("None of the " . __FUNCTION__ . " parameters can be null!");
		}

		return __DIR__ . "/../../uploads/redcap-sync-file-cache/$domain/$pid/$recordId";
	}

	public function getDomain($url){
		$parts = explode('://', $this->get_post_meta('url'));
		return $parts[1];
	}

	# Taken from here: https://stackoverflow.com/questions/3338123/how-do-i-recursively-delete-a-directory-and-its-entire-contents-files-sub-dir
	private function rrmdir($dir) {
		if (is_dir($dir)) {
			$objects = scandir($dir);
			foreach ($objects as $object) {
				if ($object != "." && $object != "..") {
					if (is_dir($dir."/".$object))
						rrmdir($dir."/".$object);
					else
						unlink($dir."/".$object);
				}
			}
			rmdir($dir);
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
			'post_type' => $postType,
			'posts_per_page' => -1
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

	public function getProjectQuery($metadata = null){
		return $this->getMetadataQuery(self::REDCAP_PROJECT, $metadata);
	}

	public function getRecordQuery($metadata = null){
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

		$fields = $this->updateDataDictionary($url, $pid, $token);

		$recordIdFieldName = $fields[0]['field_name'];

		$id = wp_insert_post([
			'post_type' => self::REDCAP_PROJECT,
			'post_status' => 'publish',
			'meta_input' => [
				'pid' => $pid,
				'url' => $url,
				'token' => $token,
				'title' => $title,
				'record_id_field_name' => $recordIdFieldName
			]
		]);

		if(!$id){
			throw new Exception('An error occurred while adding the project post.');
		}

		$records = $this->request($url, $token, [
			'content' => 'record',
		]);

		foreach($records as $record){
			$this->insertOrUpdateRecord($url, $pid, $recordIdFieldName, $record);
		}
	}

	private function updateDataDictionary($url, $pid, $token){
		$fields = $this->request($url, $token, [
			'content' => 'metadata'
		]);

		foreach($fields as $fieldData){
			$this->insertOrUpdateField($url, $pid, $fieldData);
		}

		return $fields;
	}

	public function getLabel($pid, $fieldName, $value){
		$choices = $this->getChoices($pid, $fieldName);
		return $choices[$value];
	}

	public function getChoices($pid, $fieldName){
		$result = $this->queryFields("select select_choices_or_calculations where pid = $pid and field_name = '$fieldName'");
		$row = mysqli_fetch_assoc($result);
		$lines = explode(' | ', $row['select_choices_or_calculations']);

		$choices = [];
		foreach($lines as $line){
			$separatorIndex = strpos($line, ', ');
			$key = substr($line, 0, $separatorIndex);
			$value = substr($line, $separatorIndex+2);

			$choices[$key] = $value;
		}

		return $choices;
	}

	public function request($url, $token, $params){
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

		if($params['content'] == 'file' && $params['action'] == 'export' && $output[0] != '{'){
			// This is a non-error response to a file export request.  Return the raw binary response.
			return $output;
		}

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

	public function get_post_meta($key = null, $single = true){
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

	public function queryRecords($pseudoQuery){
		return $this->query(self::REDCAP_RECORD, $pseudoQuery);
	}

	public function queryProjects($pseudoQuery){
		return $this->query(self::REDCAP_PROJECT, $pseudoQuery);
	}

	public function queryFields($pseudoQuery){
		return $this->query(self::REDCAP_FIELD, $pseudoQuery);
	}

	private function query($postType, $pseudoQuery){
		$parser = new PHPSQLParser();
		$parsed = $parser->parse($pseudoQuery);

		$fields = [];
		$this->processPseudoQuery($parsed['SELECT'], $fields, true);
		$this->processPseudoQuery($parsed['WHERE'], $fields, false);

		$creator = new PHPSQLCreator();
		$select = $creator->create(['SELECT' => $parsed['SELECT']]);
		$where = substr($creator->create($parsed), strlen($select));

		$fields = array_unique($fields);
		$firstField = $fields[0];
		$from = ' from (select 1) dummyTable';
		foreach($fields as $field){
			$from .= " left join wp_postmeta $field on $field.meta_key = '$field'";

			if($field != $firstField){
				$from .= " and $field.post_id = $firstField.post_id";
			}
		}

		$from .= " join wp_posts post on post.ID = $firstField.post_id and post.post_type = '$postType' ";

		$sql = implode(' ', [$select, $from, $where]);

		// All query methods build into WordPress load all result rows into memory first.
		// We've already run into cases where we run out of memory on a project with less than 100 large records.
		// To get around this issue, we query the database directly and return the result object to iterate over (instead of loading all rows into memory).
		global $wpdb;
		$dbh = $wpdb->__get('dbh');
		$result = mysqli_query($dbh, $sql);

		if($result === false){
			echo "Error executing query: $sql";
		}

		return $result;
	}

	private function processPseudoQuery(&$parsed, &$fields, $addAs)
	{
		for ($i = 0; $i < count($parsed); $i++) {
			$item =& $parsed[$i];
			$subtree =& $item['sub_tree'];

			if (is_array($subtree)) {
				$this->processPseudoQuery($subtree, $fields, $addAs);
			} else if ($item['expr_type'] == 'colref') {
				$field = $item['base_expr'];
				$fields[] = $field;

				$newField = "$field.meta_value";

				if($addAs && $item['alias'] == false){
					$newField .= " as $field";
				}

				$item['base_expr'] = $newField;
			}
		}
	}

}
