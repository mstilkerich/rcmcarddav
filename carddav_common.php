<?php
/*
    RCM CardDAV Plugin
    Copyright (C) 2013 Benjamin Schieder <blindcoder@scavenger.homeip.net>,
                       Michael Stilkerich <ms@mike2k.de>

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License along
    with this program; if not, write to the Free Software Foundation, Inc.,
    51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
*/


require_once("inc/http.php");
require_once("inc/sasl.php");

class carddav_common
{
	const DEBUG      = false; // set to true for basic debugging
	const DEBUG_HTTP = false; // set to true for debugging raw http stream

	const NSDAV     = 'DAV:';
	const NSCARDDAV = 'urn:ietf:params:xml:ns:carddav';

	// admin settings from config.inc.php
	private static $admin_settings;
	// encryption scheme
	public static $pwstore_scheme = 'base64';

	private $module_prefix = '';

	public function __construct($module_prefix = '')
	{{{
	$this->module_prefix = $module_prefix;
	}}}

	public static function concaturl($str, $cat)
	{{{
	preg_match(";(^https?://[^/]+)(.*);", $str, $match);
	$hostpart = $match[1];
	$urlpart  = $match[2];

	// is $cat already a full URL?
	if(strpos($cat, '://') !== FALSE) {
		return $cat;
	}

	// is $cat a simple filename?
	// then attach it to the URL
	if (substr($cat, 0, 1) != "/"){
		$urlpart .= "/$cat";

		// $cat is a full path, the append it to the
		// hostpart only
	} else {
		$urlpart = $cat;
	}

	// remove // in the path
	$urlpart = preg_replace(';//+;','/',$urlpart);
	return $hostpart.$urlpart;
	}}}

	// log helpers
	private function getCaller()
	{{{
	// determine calling function for debug output
	if (version_compare(PHP_VERSION, "5.4", ">=")){
		$caller=debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS,3);
	} else {
		$caller=debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
	}
	$caller=$caller[2]['function'];
	return $caller;
	}}}

	public function warn()
	{{{
	$caller=self::getCaller();
	write_log("carddav.warn", $this->module_prefix . "($caller) " . implode(' ', func_get_args()));
	}}}

	public function debug()
	{{{
	if(self::DEBUG) {
		$caller=self::getCaller();
		write_log("carddav", $this->module_prefix . "($caller) " . implode(' ', func_get_args()));
	}
	}}}

	public function debug_http()
	{{{
	if(self::DEBUG_HTTP) {
		$caller=self::getCaller();
		write_log("carddav", $this->module_prefix . "($caller) " . implode(' ', func_get_args()));
	}
	}}}

	// XML helpers
	public function checkAndParseXML($reply) {
		if(!is_array($reply))
			return false;

		if(!self::check_contenttype($reply['headers']['content-type'], ';(text|application)/xml;'))
			return false;

		$xml = new SimpleXMLElement($reply['body']);
		$this->registerNamespaces($xml);
		return $xml;
	}

	public function registerNamespaces($xml) {
		// Use slightly complex prefixes to avoid conflicts
		$xml->registerXPathNamespace('RCMCC', self::NSCARDDAV);
		$xml->registerXPathNamespace('RCMCD', self::NSDAV);
	}

	// HTTP helpers
	/**
	 * @param $url: url of the requested resource
	 *
	 * @param $http_opts: Options for the HTTP request, keys:
	 *             - method: request method (GET, PROPFIND, etc.)
	 *             - content: request body
	 *             - header: array of HTTP request headers as simple strings
	 *
	 * @param $carddav: config array containing at least the keys
	 *             - url: base url, used if $url is a relative url
	 *             - username
	 *             - password: password (encoded/encrypted form as stored in DB)
	 */
	public function cdfopen($url, $http_opts, $carddav)
	{{{
	$redirect_limit = 5;
	$rcmail = rcmail::get_instance();

	$username=$carddav['username'];
	$password = self::decrypt_password($carddav['password']);
	$baseurl=$carddav['url'];

	// determine calling function for debug output
	$caller=self::getCaller();

	$local = $rcmail->user->get_username('local');

	// Substitute Placeholders
	if($username == '%u')
		$username = $_SESSION['username'];
	if($username == '%l')
		$username = $local;
	if($password == '%p')
		$password = $rcmail->decrypt($_SESSION['password']);
	$baseurl = str_replace("%u", $username, $carddav['url']);
	$url = str_replace("%u", $username, $url);
	$baseurl = str_replace("%l", $local, $carddav['url']);
	$url = str_replace("%l", $local, $url);

	// if $url is relative, prepend the base url
	$url = self::concaturl($baseurl, $url);

	do {
		$isRedirect = false;
		$http=new http_class;
		$http->timeout=10;
		$http->data_timeout=0;
		$http->user_agent="RCM CardDAV plugin/1.0.0";
		$http->prefer_curl=1;
		if (self::DEBUG){ $this->debug("$caller requesting $url [RL $redirect_limit]"); }

		$url = preg_replace(";://;", "://".urlencode($username).":".urlencode($password)."@", $url);
		$error = $http->GetRequestArguments($url,$arguments);
		$arguments["RequestMethod"] = $http_opts['method'];
		if (array_key_exists('content',$http_opts) && strlen($http_opts['content'])>0 && $http_opts['method'] != "GET"){
			$arguments["Body"] = $http_opts['content']."\r\n";
		}
		if(array_key_exists('header',$http_opts)) {
			foreach ($http_opts['header'] as $header){
				$h = explode(": ", $header);
				if (strlen($h[0]) > 0 && strlen($h[1]) > 0){
					// Only append headers with key AND value
					$arguments["Headers"][$h[0]] = $h[1];
				}
			}
		}
		if ($carddav["preemptive_auth"] == '1'){
			$arguments["Headers"]["Authorization"] = "Basic ".base64_encode($username.":".$password);
		}
		$error = $http->Open($arguments);
		if ($error == ""){
			$error=$http->SendRequest($arguments);
			if (self::DEBUG_HTTP){ $this->debug_http("SendRequest: ".var_export($http, true)); }

			if ($error == ""){
				$error=$http->ReadReplyHeaders($headers);
				if ($http->response_status == 401){ # Should be handled by http class, but sometimes isn't...
					if (self::DEBUG){ $this->debug("retrying forcefully"); }
					$isRedirect = true;
					$carddav["preemptive_auth"] = "1";
				} else {
					if ($error == ""){
						$scode = $http->response_status;
						if (self::DEBUG){ $this->debug("Code: $scode"); }
						$isRedirect = ($scode>300 && $scode<304) || $scode==307;
						if( ! // These message types must not include a message-body
							(($scode>=100 && $scode < 200)
							|| $scode == 204
							|| $scode == 304)
						) {
							$error = $http->ReadWholeReplyBody($body);
						}
						if($isRedirect && strlen($headers['location'])>0) {
							$url = self::concaturl($baseurl, $headers['location']);

						} else if ($error == ""){
							$reply["status"] = $scode;
							$reply["headers"] = $headers;
							$reply["body"] = $body;
							if (self::DEBUG_HTTP){ $this->debug_http("success: ".var_export($reply, true)); }
							return $reply;
						} else {
							$this->warn("Could not read reply body: $error");
						}
					} else {
						$this->warn("Could not read reply header: $error");
					}
				}
			} else {
				$this->warn("Could not send request: $error");
			}
		} else {
			$this->warn("Could not open: $error");
			if (self::DEBUG_HTTP){ $this->debug_http("failed: ".var_export($http, true)); }
			return -1;
		}

	} while($redirect_limit-->0 && $isRedirect);

	return $http->response_status;
	}}}

	public function check_contenttype($ctheader, $expectedct)
	{{{
	if(!is_array($ctheader)) {
		$ctheader = array($ctheader);
	}

	foreach($ctheader as $ct) {
		if(preg_match($expectedct, $ct))
			return true;
	}

	return false;
	}}}

	// password helpers
	private function carddav_des_key()
	{{{
	$rcmail = rcmail::get_instance();
	$imap_password = $rcmail->decrypt($_SESSION['password']);
	while(strlen($imap_password)<24) {
		$imap_password .= $imap_password;
	}
	return substr($imap_password, 0, 24);
	}}}

	public function encrypt_password($clear)
	{{{
	if(strcasecmp(self::$pwstore_scheme, 'plain')===0)
		return $clear;

	if(strcasecmp(self::$pwstore_scheme, 'encrypted')===0) {

		// encrypted with IMAP password
		$rcmail = rcmail::get_instance();

		$imap_password = self::carddav_des_key();
		$deskey_backup = $rcmail->config->set('carddav_des_key', $imap_password);

		$crypted = $rcmail->encrypt($clear, 'carddav_des_key');

		// there seems to be no way to unset a preference
		$deskey_backup = $rcmail->config->set('carddav_des_key', '');

		return '{ENCRYPTED}'.$crypted;
	}

	// default: base64-coded password
	return '{BASE64}'.base64_encode($clear);
	}}}

	public function password_scheme($crypt)
	{{{
	if(strpos($crypt, '{ENCRYPTED}') === 0)
		return 'encrypted';

	if(strpos($crypt, '{BASE64}') === 0)
		return 'base64';

	// unknown scheme, assume cleartext
	return 'plain';
	}}}

	public function decrypt_password($crypt)
	{{{
	if(strpos($crypt, '{ENCRYPTED}') === 0) {
		$crypt = substr($crypt, strlen('{ENCRYPTED}'));
		$rcmail = rcmail::get_instance();

		$imap_password = self::carddav_des_key();
		$deskey_backup = $rcmail->config->set('carddav_des_key', $imap_password);

		$clear = $rcmail->decrypt($crypt, 'carddav_des_key');

		// there seems to be no way to unset a preference
		$deskey_backup = $rcmail->config->set('carddav_des_key', '');

		return $clear;
	}

	if(strpos($crypt, '{BASE64}') === 0) {
		$crypt = substr($crypt, strlen('{BASE64}'));
		return base64_decode($crypt);
	}

	// unknown scheme, assume cleartext
	return $crypt;
	}}}

	// admin settings from config.inc.php
	public static function get_adminsettings()
	{{{
	if(is_array(self::$admin_settings))
		return self::$admin_settings;

	$rcmail = rcmail::get_instance();
	$prefs = array();
	$configfile = dirname(__FILE__)."/config.inc.php";
	if (file_exists($configfile)){
		require("$configfile");
	}
	self::$admin_settings = $prefs;

	if(is_array($prefs['_GLOBAL'])) {
		$scheme = $prefs['_GLOBAL']['pwstore_scheme'];
		if(preg_match("/^(plain|base64|encrypted)$/", $scheme))
			self::$pwstore_scheme = $scheme;
	}
	return $prefs;
	}}}
}

?>
