<?php

/**
 * Reddit object types
 */
define('REDDIT_COMMENT', 1);
define('REDDIT_ACCOUNT', 2);
define('REDDIT_LINK', 3);
define('REDDIT_MESSAGE', 4);
define('REDDIT_SUBREDDIT', 5);

/**
 * Reddit vote directions
 */
define('REDDIT_VOTE_UP', 1);
define('REDDIT_VOTE_RESCIND', 0);
define('REDDIT_VOTE_DOWN', -1);

class RedditBot {
	
	private $_userName;
	private $_password = false;
	private $_hash;
	private $_cookie;
	private $_runCallback;
	
	/**
	 * The data field for this bot
	 * @var string
	 */
	public $data;
	
	/**
	 * Unix timestamp of the last time this bot was updated
	 * @var int
	 */
	public $lastUpdated;
	
	/**
	 * @param mixed $ident The row ID or user name of the bot to load
	 * @param string $password The password to use for the bot in case one was not provided in the database
	 */
	public function __construct($ident, $password = false) {
		
		$loaded = false;
		
		// Attempt to load a previous session
		if (is_int($ident)) {
			$loaded = $this->_loadSessionById($ident);
		} else {
			$loaded = $this->_loadSessionByUserName($ident);
		}
		
		if ($loaded) {
			$hasSession = strlen($this->_hash) > 0 && strlen($this->_cookie) > 0;
			if (!$hasSession) {
				$this->_password = false === $this->_password ? $password : $this->_password;
				if (false !== $this->_password) {
					$this->_login();
				}
			}
		}
		
	}
	
	/**
	 * Logs the user in and saves the hash/cookie for later sessions
	 */
	private function _login() {
		$retVal = false;
		$obj = $this->_doAction('api/login/' . $this->_userName, array('user'=>$this->_userName, 'passwd'=>$this->_password, 'api_type'=>'json'));
		if (is_string($obj)) {
			$obj = json_decode($obj);
			if (is_object($obj) && count($obj->json->errors) == 0) {
				$obj = $obj->json;
				$this->_hash = $obj->data->modhash;
				$this->_cookie = $obj->data->cookie;
				$this->_persist();
			}
		}
	}
	
	/**
	 * Submits a vote on a reddit object
	 * @param int $direction What kind of vote to submit (upvote, downvote, or rescind)
	 * @param string $id The ID of the object to post to
	 * @param string $type The type of reddit object to post to
	 * @return boolean Returns whether the vote was posted successfully
	 */
	public function Vote($direction, $id, $type) {
		$retVal = false;
		$obj = $this->_doAction('api/vote/', array('id'=>'t' . $type . '_' . $id, 'dir'=>$direction, 'uh'=>$this->_hash), $this->_cookie);
		echo $obj;
		if ($obj == '{}') {
			$retVal = true;
		}
		return $retVal;
	}
	
	/**
	 * Posts a comment on a reddit object
	 * @param string $text The text body of the comment
	 * @param string $id The ID of the object to post to
	 * @param string $type The type of reddit object to post to
	 * @return boolean Returns whether the comment was posted successfully
	 */
	public function Comment($text, $id, $type) {
		$retVal = false;
		$obj = $this->_doAction('api/comment/', array('thing_id'=>'t' . $type . '_' . $id, 'text'=>$text, 'uh'=>$this->_hash), $this->_cookie);
		if (is_string($obj)) {
			$retVal = strpos($obj, 'contentHTML') !== false;
		}
		return $retVal;
	}
	
	/**
	 * Reports on a reddit object (e.g. comment, post, link)
	 * @param string $id The thing to report, e.g. t3_203xa
	 * @return boolean Returns whether the thing was reported successfully
	 */
	public function Report($id) {
		$retVal = false;
		$obj = $this->_doAction('api/report/', array('id'=>$id, 'uh'=>$this->_hash), $this->_cookie);
		if ($obj == '{}') {
			$retVal = true;
		}
		return $retVal;
	}
	
	/**
	 * Updated GetPageListing to be more generic, sends your cookie using cURL so you get your results, not the general result
	 * @param string $page The page to get. Ex: 'r/anime' returns the data object for the anime subreddit; '/message/inbox' returns the data object from your inbox
	 * @return object Returns the data object retrieved
	 */
	
	public function GetListing($page) {
		$retVal = false;
		$file = $this->_doAction($page,null,$this->_cookie);
		
		if (strlen($file) > 0) {
			$obj = json_decode($file);
			if (is_object($obj) && is_array($obj->data->children)) {
				$retVal = $obj->data->children;
			}
		}
		return $retVal;
	}
	
	/**
	 * Checks to see if this bot has been logged in and has a valid hash and cookie
	 */
	public function HasSession() {
		return is_object($this->_userObj) && is_string($this->_hash) && is_string($this->_cookie);
	}
	
	/**
	 * Runs the callback set for this bot
	 */
	public function Run() {
		$retVal = false;
		if (is_callable($this->_runCallback)) {
			$retVal = call_user_func($this->_runCallback, $this);
		}
		return $retVal;
	}
	
	/**
	 * Saves this bot to the database
	 */
	public function Save() {
		$this->_persist();
	}
	
	/**
	 * Loads a reddit bot's data by row ID
	 */
	private function _loadSessionById($id) {
		$retVal = false;
		
		$result = Db::Query('SELECT bot_name, bot_password, bot_hash, bot_cookie, bot_data, bot_updated, bot_callback FROM bot_users WHERE bot_id=:id LIMIT 1', array(':id'=>$id));
		while ($row = Db::Fetch($result)) {
			$this->_userName = $row->bot_name;
			$this->_password = $row->bot_password;
			$this->_hash = $row->bot_hash;
			$this->_cookie = $row->bot_cookie;
			$this->data = $row->bot_data;
			$this->lastUpdated = $row->bot_updated;
			$this->_runCallback = $row->bot_callback;
			$retVal = true;
		}
		
		return $retVal;
	}
	
	/**
	 * Loads a reddit bot's data by username
	 */
	private function _loadSessionByUserName($userName) {
		$retVal = false;
		
		$result = Db::Query('SELECT bot_name, bot_password, bot_hash, bot_cookie, bot_data, bot_updated, bot_callback FROM bot_users WHERE bot_name=:name LIMIT 1', array(':name'=>$userName));
		while ($row = Db::Fetch($result)) {
			$this->_userName = $row->bot_name;
			$this->_password = $row->bot_password;
			$this->_userObj = new stdClass();
			$this->_hash = $row->bot_hash;
			$this->_cookie = $row->bot_cookie;
			$this->data = $row->bot_data;
			$this->lastUpdated = $row->bot_updated;
			$this->_runCallback = $row->bot_callback;
			$retVal = true;
		}
		
		return $retVal;
	}
	
	/**
	 * Saves the bot data back to the database. If the bot has not been created, it will create it.
	 */
	private function _persist() {
		$retVal = false;
		$result = Db::Query('SELECT bot_id FROM bot_users WHERE bot_name=:user', array(':user'=>$this->_userName));
		if ($result->count > 0) {
			$params = array(':hash'=>$this->_hash, ':cookie'=>$this->_cookie, ':name'=>$this->_userName, ':data'=>$this->data);
			$retVal = Db::Query('UPDATE bot_users SET bot_updated=UNIX_TIMESTAMP(NOW()), bot_hash=:hash, bot_cookie=:cookie, bot_data=:data WHERE bot_name=:name', $params) > 0;
		} else {
			$retVal = Db::Query('INSERT INTO bot_users (bot_created, bot_name, bot_password, bot_cookie, bot_hash, bot_enabled) VALUES (UNIX_TIMESTAMP(NOW()), :user, :password, :cookie, :hash, 0)', array(':password'=>$this->_password, ':hash'=>$this->_hash, ':cookie'=>$this->_cookie, ':user'=>$this->_userName)) > 0;
		}
	}
	
	/**
	 * Wrapper to perform an HTTP POST action to reddit. Requires the PHP cURL extension
	 */
	private function _doAction($url, $data = null, $cookie = false) {
		$retVal = false;
		
		$c = curl_init('http://www.reddit.com/'.$url.'.json');
		if ($c) {
			curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
			if (is_array($data)) {
				curl_setopt($c, CURLOPT_POST, true);
				
				$post = '';
				foreach ($data as $key=>$value) {
					$post .= $key . '=' . urlencode($value) . '&';
				}
				$post = substr($post, 0, strlen($post) - 1);
				
				curl_setopt($c, CURLOPT_POSTFIELDS, $post);
				curl_setopt($c, CURLINFO_HEADER_OUT, true);
			}
			
			if (false !== $cookie) {
				curl_setopt($c, CURLOPT_COOKIE, 'reddit_session=' . $cookie);
			}
			$retVal = curl_exec($c);
		}
		
		return $retVal;
	}
	
}