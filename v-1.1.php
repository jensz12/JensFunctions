<?php
/* VERSION 1.1 */
class JensFunctions {
	const CACHE_DIR = './cache/';

	const YOUTUBE_SUB_COUNT_FILE = self::CACHE_DIR.'youtube_sub_count.txt';
	const YOUTUBE_LATEST_VIDEOS_FILE = self::CACHE_DIR.'youtube_latest_videos.json';
	const YOUTUBE_PLAYLISTS_FILE = self::CACHE_DIR.'youtube_playlists.json';

	const TWITCH_SUBS_FILE = self::CACHE_DIR.'twitch_subs.json';
	const TWITCH_DEFAULT_AVATAR = 'https://static-cdn.jtvnw.net/jtv_user_pictures/xarth/404_user_150x150.png';

	static private $config = array();

	static public function loadConfig($config) {
		self::$config = include $config;
	}

	static public function checkCronjob() {
		if (isset($_GET['cronjob_key']) && $_GET['cronjob_key'] == self::$config['cronjob_key'])
			self::runCronjob();
	}

	static private function runCronjob() {
		error_reporting(E_ALL);
		ini_set('display_errors', '1');

		if (!is_dir(self::CACHE_DIR)) {
			mkdir(self::CACHE_DIR, 0777);
			chmod(self::CACHE_DIR, 0777);
		}

		self::fetchYouTubeData();
		self::fetchTwitchData();

		exit;
	}

	static public function getYouTubeSubCount() {
		if (!is_readable(self::YOUTUBE_SUB_COUNT_FILE))
			return 0;

		return file_get_contents(self::YOUTUBE_SUB_COUNT_FILE);
	}

	static public function getYouTubeLatestVideo() {
		if (!is_readable(self::YOUTUBE_LATEST_VIDEOS_FILE))
			return array();

		$videos = file_get_contents(self::YOUTUBE_LATEST_VIDEOS_FILE);
		$videos = json_decode($videos, true);

		return (object) $videos[0];
	}

	static public function getYouTubeLatestVideos() {
		if (!is_readable(self::YOUTUBE_LATEST_VIDEOS_FILE))
			return array();

		$videos = file_get_contents(self::YOUTUBE_LATEST_VIDEOS_FILE);
		return json_decode($videos);
	}

	static public function getYouTubePlaylists() {
		if (!is_readable(self::YOUTUBE_PLAYLISTS_FILE))
			return array();

		$playlists = file_get_contents(self::YOUTUBE_PLAYLISTS_FILE);
		return json_decode($playlists);
	}

	static public function getTwitchSubCount() {
		if (!is_readable(self::TWITCH_SUBS_FILE))
			return 0;

		$subs = file_get_contents(self::TWITCH_SUBS_FILE);
		return count(json_decode($subs));
	}

	static public function getTwitchSubs() {
		if (!is_readable(self::TWITCH_SUBS_FILE))
			return array();

		$subs = file_get_contents(self::TWITCH_SUBS_FILE);
		return json_decode($subs);
	}

	static private function fetchYouTubeData() {
		if (empty(self::$config['youtube_key']))
			return;

		if (empty(self::$config['youtube_id']))
			return;

		if (empty(self::$config['youtube_video_count']))
			return;

		//Get sub count
		$params = array(
			'key' => self::$config['youtube_key'],
			'part' => 'statistics',
			'id' => self::$config['youtube_id']
		);

		$channel = self::curlGetJSON('https://www.googleapis.com/youtube/v3/channels', $params, true);

		if (!empty($channel)) {
			$sub_count = $channel['items'][0]['statistics']['subscriberCount'];

			file_put_contents(self::YOUTUBE_SUB_COUNT_FILE, $sub_count);

			//Get latest videos
			$params = array(
				'key' => self::$config['youtube_key'],
				'part' => 'snippet',
				'channelId' => self::$config['youtube_id'],
				'maxResults' => self::$config['youtube_video_count'],
				'order' => 'date',
				'type' => 'video'
			);

			$search = self::curlGetJSON('https://www.googleapis.com/youtube/v3/search', $params);
			$videos = array();

			foreach ($search->items as $video) {
				$videos[] = array(
					'id' => $video->id->videoId,
					'title' => $video->snippet->title,
					'thumbnail' => $video->snippet->thumbnails->medium->url
				);
			}

			file_put_contents(self::YOUTUBE_LATEST_VIDEOS_FILE, json_encode($videos));
		}

		//Get playlists
		$playlists = array();
		$page_token = '';

		do {
			$params = array(
				'key' => self::$config['youtube_key'],
				'part' => 'snippet',
				'channelId' => self::$config['youtube_id'],
				'maxResults' => 50,
				'order' => 'date'
			);

			if (!empty($page_token))
				$params['pageToken'] = $page_token;

			$playlist_search = self::curlGetJSON('https://www.googleapis.com/youtube/v3/playlists', $params);

			if (!empty($playlist_search)) {
				foreach ($playlist_search->items as $playlist) {
					$playlists[] = array(
						'id' => $playlist->id,
						'title' => $playlist->snippet->title,
						'thumbnail' => $playlist->snippet->thumbnails->medium->url
					);
				}

				if (isset($playlist_search->nextPageToken))
					$page_token = $playlist_search->nextPageToken;
				else
					$page_token = '';
			}

		} while (!empty($page_token));

		file_put_contents(self::YOUTUBE_PLAYLISTS_FILE, json_encode($playlists));
	}

	static private function fetchTwitchData() {
		if (empty(self::$config['twitch_client_id']))
			return;

		if (empty(self::$config['twitch_oauth_token']))
			return;

		//Get subs
		$subs = array();
		$sub_count = 0;
		$offset = 0;
		$limit = 100;

		do {
			$params = array(
				'api_version' => 5,
				'oauth_token' => self::$config['twitch_oauth_token'],
				'client_id' => self::$config['twitch_client_id'],
				'limit' => $limit,
				'offset' => $offset
			);

			$twitch_subs = self::curlGetJSON('https://api.twitch.tv/kraken/channels/'.self::$config['twitch_id'].'/subscriptions', $params);

			if (empty($twitch_subs))
				return;

			foreach ($twitch_subs->subscriptions as $sub) {
				$sub_count++;

				if ($sub->user->_id == self::$config['twitch_id'])
					continue;

				if (isset($sub->user->logo))
					$avatar = $sub->user->logo;
				else
					$avatar = self::TWITCH_DEFAULT_AVATAR;

				$subs[] = array(
					'name' => $sub->user->display_name,
					'avatar' => $avatar,
					'plan' => $sub->sub_plan
				);
			}

			$offset = ($offset + $limit);

		} while ($twitch_subs->_total > $sub_count);

		usort($subs, function($a, $b) {
		    return strtolower($a['name']) <=> strtolower($b['name']);
		});

		file_put_contents(self::TWITCH_SUBS_FILE, json_encode($subs));
	}

	static private function curlGetJSON($url, array $params = null, $as_array = false) {
		$curl = curl_init();

		if (isset($params))
			$url = $url.'?'.http_build_query($params);

		curl_setopt_array($curl, array(
			CURLOPT_RETURNTRANSFER => 1,
			CURLOPT_TIMEOUT => 5,
			CURLOPT_URL => $url
		));

		$result = curl_exec($curl);

		if ($result !== false)
			return json_decode($result, $as_array);

		else {
			print_r(array('url' => $url, 'error' => curl_error($curl)));
			return array();
		}
	}
}
?>
