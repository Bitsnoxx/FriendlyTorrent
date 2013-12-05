<?php
/**
 * Transmission bittorrent client communication class
 * @copyright Copyright (C) 2010 Johan Adriaans <johan.adriaans@gmail.com>
 * @package Transmission
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * Transmission RPC class for transmission-deamon (not patched one)
 *
 * @author <johan.adriaans@gmail.com>
 * @author <tanguy.pruvot@gmail.com>
 *
 * Usage example:
 * <?php
 *   $rpc = Transmission::getInstance();
 *   $result = $rpc->add( $url_or_path_to_torrent, $target_folder );
 * ?>
 *
 * RPC Specs :
 * https://trac.transmissionbt.com/browser/trunk/extras/rpc-spec.txt
 */
 
class Transmission
{
	/**
	 * Transmission-daemon >= 2.40 support
	 */
	public static $recent_status = true;

	/**
	 * The URL to the bittorent client you want to communicate with
	 * the port (default: 9091) can be set in you Tranmission preferences
	 * @var string
	 */
	public $url = 'http://127.0.0.1:9091/transmission/rpc';

	/**
	 * If your Transmission RPC requires authentication, supply username here
	 * @var string
	 */
	public $username = 'transmission';

	/**
	 * If your Transmission RPC requires authentication, supply password here
	 * @var string
	 */
	public $password = '';

	/**
	 * Transmission uses a session id to prevent CSRF attacks
	 * @var string
	 */
	protected $session_id = '';
	
	/**
	 * Transmission last error
	 * @var string
	 */
	public $lastError = '';
	
	/**
	 * Torrents cache
	 * @var array
	 */
	public $torrents = array();
	
	/**
	 * Unit info 1000 or 1024 bytes
	 * integer
	 */
	public $kbytes;

	/**
	 * Transmission version (from session vars)
	 */
	public $version;

	/**
	 * Constructor
	 */
	public function __construct($cfg = array()) {


		if (isset($cfg['transmission_rpc_host']))
			$this->url = str_replace('127.0.0.1',$cfg['transmission_rpc_host'],$this->url);
		if (isset($cfg['transmission_rpc_port']))
			$this->url = str_replace('9091',$cfg['transmission_rpc_port'],$this->url);

		if (!isset($cfg['transmissionrpc_version'])) {
			$req = $this->session_get(array("version","rpc-version","units"));
			$cfg['transmissionrpc_version'] = $req['arguments']['version'];
			$cfg['transmissionrpc_kbytes'] = $req['arguments']['units']['size-bytes'];
		}
		$this->version = $cfg['transmissionrpc_version'];
		$this->kbytes  = $cfg['transmissionrpc_kbytes'];

		self::$recent_status = ($this->version >= "2.40");

		global $Transmission_inst;
		$Transmission_inst = & $this;
	}

	/**
	 * Start one or more torrents
	 *
	 * @param int|array ids A list of transmission torrent ids
	 */
	public function start( $ids )
	{
		if( !is_array( $ids ) ) $ids = array( $ids );
		$request = array( "ids" => $ids );
		return $this->request( "torrent-start", $request );
	}

	/**
	 * Stop one or more torrents
	 *
	 * @param int|array ids A list of transmission torrent ids
	 */
	public function stop( $ids )
	{
		if( !is_array( $ids ) ) $ids = array( $ids );
		$request = array( "ids" => $ids );
		return $this->request( "torrent-stop", $request );
	}

	/**
	 * Reannounce one or more torrents
	 *
	 * @param int|array ids A list of transmission torrent ids
	 */
	public function reannounce( $ids )
	{
		if( !is_array( $ids ) ) $ids = array( $ids );
		$request = array( "ids" => $ids );
		return $this->request( "torrent-reannounce", $request );
	}

	/**
	 * Verify one or more torrents
	 *
	 * @param int|array ids A list of transmission torrent ids
	 */
	public function verify( $ids )
	{
		if( !is_array( $ids ) ) $ids = array( $ids );
		$request = array( "ids" => $ids );
		return $this->request( "torrent-verify", $request );
	}

	/**
	 * Get information on torrents in transmission, if the ids parameter is
	 * empty all torrents will be returned. The fields array can be used to return certain
	 * fields. Default fields are: "id", "name", "status", "doneDate", "haveValid", "totalSize".
	 *
	 * @param array fields An array of return fields
	 * @param int|array ids A list of transmission torrent ids
	 * @return object
	 */
	public function get( $ids = array(), $fields = array() )
	{
		if( !is_array( $ids ) ) $ids = array( $ids );
		if( count( $fields ) == 0 ) $fields = array( "id", "name", "status", "doneDate", "haveValid", "totalSize" );

		$request = array(
			"fields" => $fields,
			"ids" => $ids
		);

		return $this->request( "torrent-get", $request );
	}

	/**
	 * Set properties on one or more torrents, available fields are:
	 *   "bandwidthPriority"   | number     this torrent's bandwidth tr_priority_t
	 *   "downloadLimit"       | number     maximum download speed (in K/s)
	 *   "downloadLimited"     | boolean    true if "downloadLimit" is honored
	 *   "files-wanted"        | array      indices of file(s) to download
	 *   "files-unwanted"      | array      indices of file(s) to not download
	 *   "honorsSessionLimits" | boolean    true if session upload limits are honored
	 *   "ids"                 | array      torrent list, as described in 3.1
	 *   "location"            | string     new location of the torrent's content
	 *   "peer-limit"          | number     maximum number of peers
	 *   "priority-high"       | array      indices of high-priority file(s)
	 *   "priority-low"        | array      indices of low-priority file(s)
	 *   "priority-normal"     | array      indices of normal-priority file(s)
	 *   "seedRatioLimit"      | double     session seeding ratio
	 *   "seedRatioMode"       | number     which ratio to use.  See tr_ratiolimit
	 *   "uploadLimit"         | number     maximum upload speed (in K/s)
	 *   "uploadLimited"       | boolean    true if "uploadLimit" is honored
	 *
	 *   "queuePosition"       | number     position of this torrent in its queue [0...n)
	 *   "seedIdleLimit"       | number     torrent-level number of minutes of seeding inactivity
	 *   "seedIdleMode"        | number     which seeding inactivity to use.  See tr_inactvelimit
	 *   "trackerAdd"          | array      strings of announce URLs to add
	 *   "trackerRemove"       | array      ids of trackers to remove
	 *   "trackerReplace"      | array      pairs of <trackerId/new announce URLs>
	 *
	 * See https://trac.transmissionbt.com/browser/trunk/extras/rpc-spec.txt for more information
	 *
	 * @param ids int|array ids A list of transmission torrent ids
	 * @param arguments array arguments An associative array of arguments to set
	 */
	public function set( $ids = array(), $arguments = array() )
	{
		if( !is_array( $ids ) ) $ids = array( $ids );
		if( !isset( $arguments['ids'] ) ) $arguments['ids'] = $ids;

		return $this->request( "torrent-set", $arguments );
	}

	/**
	 * Add a new torrent
	 *
	 * Available extra options:
	 *  key                  | value type & description
	 *  ---------------------+-------------------------------------------------
	 *  "download-dir"       | string      path to download the torrent to
	 *  "filename"           | string      filename or URL of the .torrent file
	 *  "metainfo"           | string      base64-encoded .torrent content
	 *  "paused"             | boolean     if true, don't start the torrent
	 *  "peer-limit"         | number      maximum number of peers
	 *  "bandwidthPriority"  | number      torrent's bandwidth tr_priority_t
	 *  "files-wanted"       | array       indices of file(s) to download
	 *  "files-unwanted"     | array       indices of file(s) to not download
	 *  "priority-high"      | array       indices of high-priority file(s)
	 *  "priority-low"       | array       indices of low-priority file(s)
	 *  "priority-normal"    | array       indices of normal-priority file(s)
	 *
	 *   Either "filename" OR "metainfo" MUST be included.
	 *     All other arguments are optional.
	 *
	 * @param torrent_location The URL or path to the torrent file
	 * @param save_path Folder to save torrent in
	 * @param extra options Optional extra torrent options
	 */
	public function add( $torrent_location, $save_path = '', $extra_options = array() )
	{
		$extra_options['download-dir'] = $save_path;
		$extra_options['filename'] = $torrent_location;

		return $this->request( "torrent-add", $extra_options );
	}

	/**
	 * Remove torrent from transmission
	 *
	 * @param bool delete_local_data Also remove local data?
	 * @param int|array ids A list of transmission torrent ids
	 */
	public function remove( $ids, $delete_local_data = false )
	{
		if( !is_array( $ids ) ) $ids = array( $ids );
		$request = array(
			"ids" => $ids,
			"delete-local-data" => $delete_local_data
		);
		return $this->request( "torrent-remove", $request );
	}

	/**
	 * Move local storage location
	 *
	 * @param int|array ids A list of transmission torrent ids
	 * @param string target_location The new storage location
	 * @param string move_existing_data Move existing data or scan new location for available data
	 */
	public function move( $ids, $target_location, $move_existing_data = true )
	{
		if( !is_array( $ids ) ) $ids = array( $ids );
		$request = array(
			"ids" => $ids,
			"location" => $target_location,
			"move" => $move_existing_data
		);
		return $this->request( "torrent-set-location", $request );
	}

	/**
	 * Clean up the request array. Removes any empty fields from the request
	 *
	 * @param array array The request associative array to clean
	 * @returns array The cleaned array
	 */
	protected function cleanRequestData( $array )
	{
		if( !is_array( $array ) || count( $array ) == 0 ) return null;
		foreach( $array as $index => $value ) {
			if( is_array( $array[$index] ) ) $array[$index] = $this->cleanRequestData( $array[$index] ); // Recursion
			if( empty( $value ) && $value!=0) unset( $array[$index] );
		}
		return $array;
	}

	/**
	 * Clean up the result object. Replaces all minus(-) characters in the object properties with underscores
	 * and converts any object with any all-digit property names to an array.
	 *
	 * @param object The request result to clean
	 * @returns array The cleaned object
	 */
	protected function cleanResultData( $object )
	{
		// Prepare and cast object to array
		$return_as_array = false;
		$array = $object;
		if( !is_array( $array ) ) $array = (array) $array;
		foreach( $array as $index => $value ) {
			if( is_array( $array[$index] ) || is_object( $array[$index] ) ) {
				$array[$index] = $this->cleanResultData( $array[$index] ); // Recursion
			}
			if( strstr( $index, '-' ) ) {
				$valid_index = str_replace( '-', '_', $index );
				$array[$valid_index] = $array[$index];
				unset( $array[$index] );
				$index = $valid_index;
			}
			// Might be an array, check index for digits, if so, an array should be returned
			if( ctype_digit( (string) $index ) ) { $return_as_array = true; }
			if( empty( $value ) ) unset( $array[$index] );
		}
		// Return array cast to object
		return $return_as_array ? $array : (object) $array;
	}

	/**
	 * The request handler method handles all requests to the Transmission client
	 *
	 * @param string method The request method to use
	 * @param array arguments The request arguments
	 * @returns array The request result
	 */
	protected function request( $method, $args=array() )
	{
		$arguments = $this->cleanRequestData( $args );
		//$arguments = $args;

		// Build request array
		$data = array(
			"method" => $method,
			"arguments" => $arguments
		);

		// Init curl
		$handle = curl_init();

		// Set curl options
		curl_setopt( $handle, CURLOPT_URL, $this->url );
		curl_setopt( $handle, CURLOPT_MAXREDIRS, 2);
		curl_setopt( $handle, CURLOPT_POST, true );
		curl_setopt( $handle, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $handle, CURLOPT_HEADER, true );
		curl_setopt( $handle, CURLOPT_POSTFIELDS, json_encode( $data ) );
		curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT, 1 );

		// Setup authentication
		if( $this->username ) {
			curl_setopt( $handle, CURLOPT_HTTPAUTH, CURLAUTH_BASIC );
			curl_setopt( $handle, CURLOPT_USERPWD, $this->username . ':' . $this->password );
		}

		// Handle session_id
		if( $this->session_id ) {
			curl_setopt( $handle, CURLOPT_HTTPHEADER, array( 'X-Transmission-Session-Id: ' . $this->session_id ) );
		}

		// Execute request
		$raw_response = curl_exec( $handle );
		if( $raw_response === false ) {
			throw new Exception("\nThe Transmission server at {$this->url} did not respond. Please make sure Transmission is running and the web client is enabled..\n");
		} elseif (strpos($raw_response,'Unauthorized') !== false) {
			throw new Exception("\nBad Transmission RPC authentification informations (session_id ?) !\n $raw_response");
		}
		// Get response headers and body
		list( $header, $body ) = explode( "\r\n\r\n", $raw_response, 2 );

		// Get http code
		$http_code = curl_getinfo( $handle, CURLINFO_HTTP_CODE );

		// Close connection
		curl_close( $handle );

		// CSRF session fix
		if( $http_code == 409 && !$this->session_id ) {
			$matches = array();
			$session_id = preg_match( "/X-Transmission-Session-Id: ([A-z0-9]*)/", $header, $matches );
			if( isset( $matches[1] ) ) $this->session_id = $matches[1];
			if( !$this->session_id ) {
				throw new Exception("Needed a session id but could not find one..\n");
			}
			return $this->request( $method, $arguments ); // Recursion, loop should be blocked by elseif below this line
		} elseif( $http_code == 409 && $this->session_id ) {
			throw new Exception("Session id '{$this->session_id}' was found and set but not accepted by transmission..\n");
		} elseif( $http_code == 401 && !$this->username ) {
			throw new Exception("\nThe Transmission web client at {$this->url} needs authentication..\n");
		} elseif( $http_code == 401 && $this->username ) {
			throw new Exception("\nThe Transmission web client at {$this->url} needs authentication, the username and password you provided seem to be incorrect.\n");
		}

		//return $this->cleanResultData( json_decode( $body ) );
		$response = json_decode( $body, true );
		if (is_array($response) && $response['result'] != "success") {
			$this->lastError = serialize($response);
		}
		return $response; 
	}

	/*
	 * get session variable
	 */
	public function session_get() {
		$req = $this->request('session-get');
		$this->session = $req;
		return $this->session;
	}

	/*
	 * set session variables
	 * @param arguments array associative
	 */
	public function session_set($arguments) {
		if( !is_array($arguments) ) $arguments = array();
		$response = $this->request('session-set',$arguments);
		return $response;
	}

	/*
	 * Static Transmission::isRunning()
	 * @return boolean
	 */
	public function isRunning() {
		$session = $this->session_get();
		return  (isset($session['result']) && $session['result'] == 'success');
	}

	/*
	 * Convert RPC Status to TorrentFlux running
	 */
	public function status_to_tf($status) {
		// 1 - waiting to verify
		// 2 - verifying
		// 4 - downloading
		// 5 - queued (incomplete)
		// 8 - seeding
		// 9 - queued (complete)
		// 16 - paused
		switch ((int) $status) {
			case 1:
			case 2:
			case 4:
			case 5:
				$tfstatus=1;
				break;
			case 8:
			case 9:
				$tfstatus=1;
				break;
			case 0:
			case 16:
			default:
				$tfstatus=0;
		};
		//0: Stopped
		//1: Running
		//2: New
		//3: Queued (Qmgr)
		return $tfstatus;
	}

	/**
	 * Allow to handle new like older Transmission daemon status
	 */
	public static function status_compat($rpcStatus) {
		$oldStatus = $rpcStatus;
		if (self::$recent_status) {
			/**
			 * previous versions :
			 * TR_STATUS_CHECK_WAIT     = ( 1 << 0 ), // 1  Waiting in queue to check files
			 * TR_STATUS_CHECK          = ( 1 << 1 ), // 2  Checking files
			 * TR_STATUS_DOWNLOAD       = ( 1 << 2 ), // 4  Downloading
			 * TR_STATUS_SEED           = ( 1 << 3 ), // 8  Seeding
			 * TR_STATUS_STOPPED        = ( 1 << 4 )  // 16 Torrent is stopped
			 *
			 * recent versions (> 2.40) :
			 * TR_STATUS_STOPPED        = 0, // Torrent is stopped
			 * TR_STATUS_CHECK_WAIT     = 1, // Queued to check files
			 * TR_STATUS_CHECK          = 2, // Checking files
			 * TR_STATUS_DOWNLOAD_WAIT  = 3, // Queued to download
			 * TR_STATUS_DOWNLOAD       = 4, // Downloading 
			 * TR_STATUS_SEED_WAIT      = 5, // Queued to seed
			 * TR_STATUS_SEED           = 6  // Seeding
			 */
			if ($rpcStatus == 3) $oldStatus = 5;
			// seeding
			if ($rpcStatus == 6) $oldStatus = 8;
			if ($rpcStatus == 5) $oldStatus = 9;
			// stopped
			if ($rpcStatus == 0) $oldStatus = 16;
		}
		return $oldStatus;
	}

	/*
	 * RPC Struct to TorrentFlux Names
	 * @param array : stat single torrent rpc data array
	 * @return array
	*/
	public function rpc_to_tf($stat) {
		
		if ($stat['uploadRatio'] == -1)
			$stat['uploadRatio'] = 0;

		$status = self::status_compat($stat['status']);

		$tfStat = array(
			'rpcid' => $stat['id'],
			'name' => $stat['name'],

			'status' => $status,
			'running' => self::status_to_tf($status),

			'size' => (float) $stat['totalSize'], 

			'percentDone' => (float) $stat['percentDone']*100.0, 
			'sharing' => (float) $stat['uploadRatio']*100.0, 
			
			'seeds' => $stat['peersSendingToUs'], 
			'peers' => $stat['peersGettingFromUs'], 
			'cons' => $stat['peersConnected'], 
			'peersList' => $stat['peers'], 
			'files' => $stat['files'], 
			
			"error" => $stat['error'],
			"errorString" => $stat['errorString'],
			'downloadDir' => $stat['downloadDir'],
			
			'downTotal' => $stat['downloadedEver'],
			'upTotal' => $stat['uploadedEver'],
			
			'eta' => $stat['eta'], 
			
			'speedDown' => (int) $stat['rateDownload'],
			'speedUp' => (int) $stat['rateUpload'],
			
			'drate' => $stat['downloadLimit'],
			'urate' => $stat['uploadLimit'],
			
			'seedlimit' => round($stat['seedRatioLimit']*100),
			'seedRatioLimit' => round($stat['seedRatioLimit']*100),
			'seedRatioMode' => $stat['seedRatioMode'],
			'trackerStats' => $stat['trackerStats']
		);

		if (self::$recent_status &&
		    $tfStat['percentDone'] == 100.0 && $tfStat['downTotal'] < $tfStat['size']) {
			// bug in v2.51 ??
			$tfStat['downTotal'] = $tfStat['size'];
		}

		return $tfStat;
	}

	/*
	 * Get all torrents in torrentflux compatible format
	 * @param array ids
	 * @return array
	*/
	public function torrent_get_tf_array($ids=array()) {

		$this->torrents = array();
		
		// available fields :
		// https://trac.transmissionbt.com/browser/trunk/extras/rpc-spec.txt
		$fields = array(
			'id',
			'name',
			'status',
			'hashString',
			'totalSize',
			'downloadedEver','uploadedEver',
			'downloadLimit','uploadLimit',
//			'downloadLimited','uploadLimited',
			"rateDownload", "rateUpload",
			'peersConnected', 'peersGettingFromUs', 'peersSendingToUs',
			'percentDone','uploadRatio',
			'seedRatioLimit','seedRatioMode',
			'downloadDir',
			'eta', 'peers', 'files',
			'error', 'errorString', 'trackerStats'
		);

		$req = $this->get($ids, $fields);
		if ($req && $req['result'] == 'success') {
			$torrents = (array) $req['arguments']['torrents'];

			foreach($torrents as $t) {
				$this->torrents[$t['hashString']] = $this->rpc_to_tf($t);
			}
		}

		return $this->torrents;
	}

	/*
	 * Get all torrents in torrentflux compatible format (shell)
	 * @param array ids
	 * @return array
	*/
	public function torrent_get_tf($ids=array()) {
		return $this->torrent_get_tf_array($ids);
	}

	/*
	 * Filter torrents (torrentflux compatible format)
	 * @param array filter
	 * @return array
	*/
	public function torrent_filter_tf($filter = NULL) {
		if (is_null($filter))
			$filter = $this->filter;
		if (empty($filter))
			return;

		$torrents = array();
		foreach ($this->torrents as $name => $tfstat) {

			$bDrop = false;
			if (isset($filter['running'])) {
				$bDrop = ($tfstat['running'] != $filter['running']);
			}
			if (isset($filter['status'])) {
				$bDrop = ($tfstat['status'] != $filter['status']);
			}
			if (isset($filter['speedUp'])) {
				$bDrop = ($tfstat['speedUp'] == 0);
			}
			if (isset($filter['speedDown'])) {
				$bDrop = ($tfstat['speedDown'] == 0);
			}
			if (isset($filter['speed'])) {
				$bDrop = ($tfstat['speedUp'] == 0 && $tfstat['speedDown'] == 0);
			}

			if (!$bDrop) {
				$torrents[$name] = $tfstat;
			}
		}

		return $torrents;
	}
}

?>
