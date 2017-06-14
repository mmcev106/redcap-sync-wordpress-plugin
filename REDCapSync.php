<?php

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

CONST REDCAP_SYNC_CRON_HOOK = 'redcap_sync_cron_hook';

class REDCapSync{
	function initializePlugin(){
		add_action('init', function(){
			register_post_type('redcap_project');
			register_post_type('redcap_record');
		});

		add_action(REDCAP_SYNC_CRON_HOOK, function(){
			error_log('redcap_sync_cron_hook ran');
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
					$query = new WP_Query(['post_type' => 'redcap_project']);
					if($query->have_posts()){
						while($query->have_posts()){
							$query->the_post();
							?>
							<tr>
								<td><?=$this->get_post_meta('title')?> - PID <?=$this->get_post_meta('project_id')?> at <?=$this->get_post_meta('url')?></td>
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
						<input name="url" placeholder="REDCap URL" value="http://localhost">
						<input name="token" placeholder="API Token" value="C72D23718701190D030F1CF812CA68AC">
						<button>Add</button>
					</form>
				</div>
				<?php
			});
		});
	}

	private function handlePost(){
		$id = @$_POST['id-to-remove'];
		if (!empty($id)) {
			wp_delete_post($id);
		} else {
			$url = $_POST['url'];
			$token = $_POST['token'];

			$this->addProject($url, $token);
		}
	}

	private function getProjectId($url, $token){
		$query = new WP_Query([
			'post_type' => 'redcap_project',
			'meta_query' => [
				'relation' => 'AND',
				[
					'key' => 'url',
					'value' => $url
				],
				[
					'key' => 'token',
					'value' => $token
				]
			]
		]);

		if($query->have_posts()){
			$query->the_post();
			return get_the_ID();
		}

		return null;
	}

	private function addProject($url, $token){
		if($this->getProjectId($url, $token)){
			echo "This project has already been added.<br>";
			return;
		}

		$response = $this->request($url, $token, [
			'content' => 'project'
		]);

		if(empty($response) || !empty($response['error'])){
			echo "An error occurred while adding the project: " . $response['error'] . '<br>';
			return;
		}

		$id = wp_insert_post([
			'post_type' => 'redcap_project',
			'meta_input' => [
				'project_id' => $response['project_id'],
				'url' => $url,
				'token' => $token,
				'title' => $response['project_title']
			]
		]);

		if(!$id){
			echo 'An error occurred while adding the project post.<br>';
		}
	}

	public function queueRecord($url, $pid, $eventId, $recordId){

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

		return json_decode($output, true);
	}

	private function get_post_meta($key, $single = true){
		global $post;
		return get_post_meta($post->ID, $key, $single);
	}
}