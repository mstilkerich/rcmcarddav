<?php
/*
    RCM CardDAV Plugin
    Copyright (C) 2011 Benjamin Schieder <blindcoder@scavenger.homeip.net>

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

require("inc/http.php");
require("inc/sasl.php");
$carddav_error_message = "";
function myErrorHandler($errno, $errstr, $errfile, $errline)
{{{
	global $carddav_error_message;
	if (!(error_reporting() & $errno)) {
		// This error code is not included in error_reporting
		return;
	}
	switch ($errno) {
		case E_WARNING:
			$carddav_error_message = $errstr;
			break;

		default:
			$carddav_error_message = "Unknown error type: [$errno] $errstr";
			break;
	}

	/* Also execute PHP internal error handler */
	return false;
}}}

class carddav_backend extends rcube_addressbook
{
  public $primary_key = 'email';
  public $readonly = false;
  public $groups = false;

  private $filter;
  private $result;

  public function __construct()
  {{{
	$this->ready = true;
  }}}

  public function get_name()
  {{{
	return "CardDAV";
  }}}

  public function set_search_set($filter)
  {{{
	$this->filter = $filter;
  }}}

  public function get_search_set()
  {{{
	return $this->filter;
  }}}

  public function reset()
  {{{
	$this->result = null;
	$this->filter = null;
  }}}

  public function list_groups($search = null)
  {{{
	return null;
  }}}

  public function array_sort($array, $on, $order=SORT_ASC)
  {{{
	$new_array = array();
	$sortable_array = array();

	if (count($array) > 0) {
		foreach ($array as $k => $v) {
			if (is_array($v)) {
				foreach ($v as $k2 => $v2) {
					if ($k2 == $on) {
						$sortable_array[$k] = $v2;
					}
				}
			} else {
				$sortable_array[$k] = $v;
			}
		}

		switch ($order) {
			case SORT_ASC:
				asort($sortable_array);
			break;
			case SORT_DESC:
				arsort($sortable_array);
			break;
		}

		foreach ($sortable_array as $k => $v) {
			$new_array[$k] = $array[$k];
		}
	}

	return $new_array;
  }}}

  public function addvcards($reply)
  {{{
	$addresses = array();
	$emails = array();
	$ID = $name = $firstname = $surname = $email = NULL;
	$filter = $this->get_search_set();
	foreach (explode("\n", $reply) as $line){
		$line = preg_replace("/[\r\n]*$/", "", $line);
		$line = htmlspecialchars_decode($line);
		if (preg_match("/^FN:(.*)$/", $line, $match)) { $name = $match[1]; }
		if (preg_match("/^N:(.*?);([^;]*)/", $line, $match)) { $surname = $match[1]; $firstname = $match[2]; }
		if (preg_match("/<href>.*\/([^\/]*)\.vcf.*<\/href>/", $line, $match)) { $ID = $match[1]; }
		if (preg_match("/^EMAIL.*?:(.*)$/", $line, $match)) { $emails[] = $match[1]; }
		if (preg_match("/<\/response>/", $line)){
			if ($emails[0]){
				foreach ($emails as $email){
					$addresses[] = array('ID' => $ID, 'name' => $name, 'firstname' => $firstname, 'surname' => $surname, 'email' => $email);
				}
				$emails = array();
				$ID = $name = $firstname = $surname = $email = NULL;
			} else {
				$addresses[] = array('ID' => $ID, 'name' => $name, 'firstname' => $firstname, 'surname' => $surname, 'email' => $email);
				$emails = array();
				$ID = $name = $firstname = $surname = $email = NULL;
			}
		}
	}
	$x = 0;
	foreach($this->array_sort($addresses, "name") as $a){
		if (strlen($filter) > 0){
			if (preg_match("/$filter/i", $a["name"]." ".$a["email"])){
				$x++;
				$a['ID'] = $a['ID']."_rcmcddelim_".$a['email'];
				$a['ID'] = preg_replace("/@/", "_rcmcdat_", $a['ID']);
				$a['ID'] = preg_replace("/\./", "_rcmcddot_", $a['ID']);
				$this->result->add(array('ID' => $a['ID'], 'name' => $a['name'], 'firstname' => $a['firstname'], 'surname' => $a['surname'], 'email' => $a['email']));
			}
		} else {
			$x++;
			$a['ID'] = $a['ID']."_rcmcddelim_".$a['email'];
			$a['ID'] = preg_replace("/@/", "_rcmcdat_", $a['ID']);
			$a['ID'] = preg_replace("/\./", "_rcmcddot_", $a['ID']);
			$this->result->add(array('ID' => $a['ID'], 'name' => $a['name'], 'firstname' => $a['firstname'], 'surname' => $a['surname'], 'email' => $a['email']));
		}
	}
	return $x;
  }}}

  public function cdfopen($caller, $url, $mode, $use_include_path, $opts)
  {{{
	global $carddav_error_message;

	$rcmail = rcmail::get_instance();
	$carddav = $rcmail->config->get('carddav', array());

	$http=new http_class;
	$http->timeout=10;
	$http->data_timeout=0;
	$http->user_agent="RCM CardDAV plugin/0.4";
	$http->follow_redirect=1;
	$http->redirection_limit=5;
	$http->prefer_curl=1;
	$url = $carddav['url'].$url;
	$url = preg_replace("/:\/\//", "://".urlencode($carddav['username']).":".urlencode($carddav['password'])."@", $url);
	$error = $http->GetRequestArguments($url,$arguments);
	$arguments["RequestMethod"] = $opts['http']['method'];
	$arguments["Body"] = $opts['http']['content']."\r\n";
	if (is_array($opts['http']['header'])){
		foreach ($opts['http']['header'] as $key => $value){
			$h = explode(": ", $value);
			$arguments["Headers"][$h[0]] = $h[1];
		}
	} else {
		$h = explode($opts['http']['header']);
		$arguments["Headers"][$h[0]] = $h[1];
	}
	$error = $http->Open($arguments);
	if ($error == ""){
		$error=$http->SendRequest($arguments);
		if ($error == ""){
			$error=$http->ReadReplyHeaders($headers);
			if ($error == ""){
				$error = $http->ReadWholeReplyBody($body);
				if ($error == ""){
					$reply["status"] = $http->response_status;
					$reply["headers"] = $headers;
					$reply["body"] = $body;
					return $reply;
				} else {
					write_log("carddav", "cdfopen: Could not read reply body: ".$error);
				}
			} else {
				write_log("carddav", "cdfopen: Could not read reply header: ".$error);
			}
		} else {
			write_log("carddav", "cdfopen: Could not send request: ".$error);
		}
	} else {
		write_log("carddav", "cdfopen: Could not open: ".$error);
	}
	return "";
  }}}

  public function list_records($cols=null, $subset=0)
  {{{
	$addresses = array();
	$this->result = $this->count();
	$opts = array(
		'http'=>array(
			'method'=>"REPORT",
			'header'=>array("Depth: infinite", "Content-Type: text/xml; charset=\"utf-8\""),
			'content'=>'<?xml version="1.0" encoding="utf-8" ?> <C:addressbook-query xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:carddav"> <D:prop> <D:getetag/> <C:address-data> <C:prop name="UID"/> <C:prop name="NICKNAME"/> <C:prop name="N"/> <C:prop name="EMAIL"/> <C:prop name="FN"/> </C:address-data> </D:prop> </C:addressbook-query>'
		)
	);

	$reply = $this->cdfopen("list_records", "", "r", false, $opts);
	$reply = $reply["body"];
	if (!strlen($reply)) { return false; }
	$reply = preg_replace("/\r\n[ \t]/","",$reply);
	$records = $this->addvcards($reply);
	if ($records > 0){
		return $this->result;
	} else {
		return false;
	}
  }}}

  public function search($fields, $value, $strict=false, $select=true, $nocount=false, $required=array())
  {{{
	$this->set_search_set($value);
	if (!$this->list_records()){
		return false;
	}
	return $this->result;
  }}}

  public function count()
  {{{
	return new rcube_result_set(1, ($this->list_page-1) * $this->page_size);
  }}}

  public function get_result()
  {{{
	return $this->result;
  }}}

  public function get_record_from_carddav($uid)
  {{{
	$this->result = $this->count();
	$opts = array(
		'http'=>array(
			'method'=>"GET",
		)
	);
	$record = explode("_rcmcddelim_", $uid);
	$id = $record[0];
	$mail = $record[1];
	$mail = preg_replace("/_rcmcdat_/", "@", $mail);
	$mail = preg_replace("/_rcmcddot_/", ".", $mail);
	$reply = $this->cdfopen("get_record_from_carddav", "/$id.vcf", "r", false, $opts);
	if (!strlen($reply["body"])) { return false; }
	if ($reply["status"] == 404){
		write_log("carddav", "Request for VCF '$uid' which doesn't exits on the server.");
		return false;
	}
	return $reply["body"];
  }}}

  public function get_record($uid, $assoc=false)
  {{{
	$vcf = $this->get_record_from_carddav($uid);
	if (!$vcf)
		return false;
	$record = explode("_rcmcddelim_", $uid);
	$id = $record[0];
	$mail = $record[1];
	$mail = preg_replace("/_rcmcdat_/", "@", $mail);
	$mail = preg_replace("/_rcmcddot_/", ".", $mail);
	foreach (explode("\r\n", $vcf) as $line){
		if (preg_match("/^FN:(.*)$/", $line, $match)) { $name = $match[1]; }
		if (preg_match("/^N:(.*?);([^;]*)/", $line, $match)) { $surname = $match[1]; $firstname = $match[2]; }
		if (preg_match("/^EMAIL.*?:(.*)$/", $line, $match)) { $email = ($match[1] == $mail ? $mail : $email); }
		if (preg_match("/^UID:(.*)$/", $line, $match)) { $ID = $match[1]; }
	}
	$this->result->add(array('ID' => $uid, 'name' => $name, 'surname' => $surname, 'firstname' => $firstname, 'email' => $email));
	$sql_arr = $assoc && $this->result ? $this->result->first() : null;
	return $assoc && $sql_arr ? $sql_arr : $this->result;
  }}}

  public function put_record_to_carddav($id, $vcf)
  {{{
	$this->result = $this->count();
	$opts = array(
		'http'=>array(
			'method'=>"PUT",
			'content'=>$vcf,
			'header'=>"Content-Type: text/vcard"
		)
	);
	$reply = $this->cdfopen("put_record_to_carddav", "/$id.vcf", "r", false, $opts);
	if ($reply["status"] >= 200 && $reply["status"] < 300) { return true; }
	return true;
  }}}

  public function delete_record_from_carddav($id, $vcf)
  {{{
	$this->result = $this->count();
	$opts = array(
		'http'=>array(
			'method'=>"DELETE",
		)
	);
	$context = stream_context_create($optsGETVCF);
	$reply = $this->cdfopen("delete_record_from_carddav", "/$id.vcf", "r", false, $opts);
	if ($reply["status"] == 204){
		return true;
	}
	return false;
  }}}

  public function update($oid, $save_cols)
  {{{
	$record = explode("_rcmcddelim_", $oid);
	$id = $record[0];
	$mail = $record[1];
	$mail = preg_replace("/_rcmcdat_/", "@", $mail);
	$mail = preg_replace("/_rcmcddot_/", ".", $mail);

	$vcf = $this->get_record_from_carddav($oid);
	if (!$vcf)
		return false;
	$vcfnew = preg_replace("/\nFN:.*?\r\n/", "\nFN:".$save_cols['name']."\r\n", $vcf);
	$vcfnew = preg_replace("/\nN:[^;]*;[^;]*/", "\nN:".$save_cols['surname'].";".$save_cols['firstname'], $vcfnew);
	if (strlen($mail) < 1){
		$vcfnew = preg_replace("/\nEND:VCARD/", "\nEMAIL;TYPE=HOME:".$save_cols['email']."\r\nEND:VCARD", $vcfnew);
	} else {
		$vcfnew = preg_replace("/\nEMAIL(;TYPE=[^:]*)?:$mail\r\n/", "\nEMAIL\\1:".$save_cols['email']."\r\n", $vcfnew);
	}
	return $this->put_record_to_carddav($id, $vcfnew);
  }}}

  public function guid()
  {{{
	return sprintf('%04X%04X-%04X-%04X-%04X-%04X%04X%04X', mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(16384, 20479), mt_rand(32768, 49151), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535));
  }}}

  public function insert($save_data, $check=false)
  {{{
	$this->search(null, $save_data['surname']);
	$update = false;
	$id = false;
	$f = false;
	if ($this->result->first()){
		foreach ($this->result->iterate() as $record){
			if ($record['firstname'] == $save_data['firstname'] &&
			$record['surname'] == $save_data['surname'] &&
			$record['name'] == $save_data['name']){
				$id = $record["ID"];
				$update = true;
			}
		}
	} else {
		$id = $this->guid();
		while ($this->get_record_from_carddav($id."_rcmcddelim_")){
			$id = $this->guid();
		}
	}

	if ($update){
		$vcf = $this->get_record_from_carddav($id);
		$record = explode("_rcmcddelim_", $id);
		$vcf = preg_replace("/END:VCARD/", "EMAIL;TYPE=HOME:".$save_data['email']."\r\nEND:VCARD", $vcf);
		return $this->put_record_to_carddav($record[0], $vcf);
	} else {
		$vcf = "BEGIN:VCARD\r\n".
			"VERSION:3.0\r\n".
			"FN:".$save_data['name']."\r\n".
			"N:".$save_data['surname'].";".$save_data['firstname'].";;;\r\n".
			"EMAIL;TYPE=HOME:".$save_data['email']."\r\n".
			"UID:$id\r\n".
			"END:VCARD\r\n";
		if ($this->put_record_to_carddav($id, $vcf)){
			$ID = $id."_rcmcddelim_".$save_data['email'];
			$ID = preg_replace("/@/", "_rcmcdat_", $ID);
			$ID = preg_replace("/\./", "_rcmcddot_", $ID);
			$save_data["ID"] = $ID;
			return $save_data;
		} else {
			return false;
		}
	}
	return false;
  }}}

  public function delete($ids)
  {{{
	$ids = explode(",", $ids);
	foreach ($ids as $uid){
		$record = explode("_rcmcddelim_", $uid);
		$id = $record[0];
		$mail = $record[1];
		$mail = preg_replace("/_rcmcdat_/", "@", $mail);
		$mail = preg_replace("/_rcmcddot_/", ".", $mail);
		$vcf = $this->get_record_from_carddav($uid);
		$vcfnew = preg_replace("/EMAIL[^\r]*".$mail."[^\r]*\r\n/", "", $vcf);
		if (!preg_match("/\nEMAIL/", $vcfnew)){
			return $this->delete_record_from_carddav($id);
		} else {
			return $this->put_record_to_carddav($id, $vcfnew);
		}
	}
	return false;
  }}}

  function create_group($name)
  {{{
	$result = false;

	return $result;
  }}}

  function delete_group($gid)
  {{{
	return false;
  }}}

  function rename_group($gid, $newname)
  {{{
	return $newname;
  }}}

  function add_to_group($group_id, $ids)
  {{{
	return false;
  }}}

  function remove_from_group($group_id, $ids)
  {{{
	return false;
  }}}

}
