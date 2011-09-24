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
require("inc/vcard.php");

function carddavconfig(){{{
	$rcmail = rcmail::get_instance();
	$prefs = $rcmail->config->get('carddav', array());
	$dont_override = $rcmail->config->get('dont_override', array());

	// Set some defaults
	$use_carddav = false;
	$username = "";
	$password = "";
	$url = "";
	$lax_resource_checking = false;

	if (file_exists("plugins/carddav/config.inc.php")){
		require("plugins/carddav/config.inc.php");
	}

	$retval = array();
	$retval['use_carddav'] = $use_carddav;
	$retval['username'] = $username;
	$retval['password'] = $password;
	$retval['url'] = str_replace("%u", $username, $url);
	$retval['lax_resource_checking'] = $lax_resource_checking;

	foreach ($retval as $key => $value){
		if (!in_array("carddav_$key", $dont_override)){
			if (in_array($key, $prefs)){
				$retval[$key] = $prefs[$key];
			}
		}
	}
	return $retval;
}}}

function startElement_addvcards($parser, $n, $attrs) {{{
	global $ctag;

	$n = str_replace("SYNC-", "", $n);
	if (strlen($n)>0){ $ctag .= "||$n";}
#if ($this->DEBUG){
#write_log("carddav", "ctag is now: $ctag");
#}
}}}
function endElement_addvcards($parser, $n) {{{
	global $ctag;
	global $vcards;
	global $cur_vcard;

	$n = str_replace("SYNC-", "", $n);
	$ctag = preg_replace(";\|\|$n$;", "", $ctag);
#if ($this->DEBUG){
#write_log("carddav", "ctag is now: $ctag");
#}
	if ($n == "DAV::RESPONSE"){
		$vcards[] = $cur_vcard;
		$cur_vcard = array();
	}
}}}
function characterData_addvcards($parser, $data) {{{
	global $ctag; global $cur_vcard;
	if ($ctag == "||DAV::MULTISTATUS||DAV::RESPONSE||DAV::HREF"){
		$cur_vcard['href'] = $data;
	}
	if ($ctag == "||DAV::MULTISTATUS||DAV::RESPONSE||DAV::PROPSTAT||DAV::PROP||URN:IETF:PARAMS:XML:NS:CARDDAV:ADDRESS-DATA"){
		$cur_vcard['vcf'] .= $data;
	}
	if ($ctag == "||DAV::MULTISTATUS||DAV::RESPONSE||DAV::PROPSTAT||DAV::PROP||DAV::GETETAG"){
		$cur_vcard['etag'] .= $data;
	}
	if ($ctag == "||DAV::MULTISTATUS||DAV::RESPONSE||DAV::PROPSTAT||DAV::PROP||DAV::GETCONTENTTYPE"){
		$cur_vcard['content-type'] = $data;
	}
#if ($this->DEBUG){
#write_log("carddav", "data is: $data");
#}
}}}

function startElement_listgroups($parser, $n, $attrs) {{{
	global $name; global $path; global $isab; global $ctag;

	if ($n == "URN:IETF:PARAMS:XML:NS:CARDDAV:ADDRESSBOOK"){
		$isab = true;
	}
	if (strlen($n)>0){ $ctag .= "||$n";}
}}}
function endElement_listgroups($parser, $n) {{{
	global $name; global $path; global $isab; global $ctag;
	global $colls;

	$carddav = carddavconfig();

	$ctag = preg_replace(";\|\|$n$;", "", $ctag);
	if ($n == "DAV::RESPONSE"){
		if ($isab || $carddav['lax_resource_checking']){
			$path = preg_replace(";/;", "_rcmcdslash_", $path);
			$path = preg_replace(";\\.;", "_rcmcddot_", $path);
			$c["ID"] = $path;
			$c["name"] = $name;
			$colls[] = $c;
		}
		$name = "Unnamed";
		$path = "";
		$isab = false;
	}
}}}
function characterData_listgroups($parser, $data) {{{
	global $name; global $path; global $isab; global $ctag;
	if ($ctag == "||DAV::MULTISTATUS||DAV::RESPONSE||DAV::HREF"){
		$path = $data;
	}

	if ($ctag == "||DAV::MULTISTATUS||DAV::RESPONSE||DAV::PROPSTAT||DAV::PROP||DAV::DISPLAYNAME"){
		$name = $data;
	}
}}}
class carddav_backend extends rcube_addressbook
{
  public $primary_key = 'ID';
  public $readonly = false;
  public $groups = true;

  private $group;
  private $filter = array();
  private $result;

  private $DEBUG = false;	# set to true for basic debugging
  private $DEBUG_HTTP = false;	# set to true for debugging raw http stream

  public function __construct()
  {{{
	$this->ready = true;
	$this->group = $_COOKIE["_cd_set_group"];
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

  public function set_group($gid)
  {{{
	$gid = str_replace("_rcmcdslash_", "/", $gid);
	$gid = str_replace("_rcmcddot_", ".", $gid);
	$gid = str_replace(" ", "%20", $gid);
	$this->group = $gid;
	setcookie("_cd_set_group", $gid);
	return $gid;
  }}}

  public function list_groups($search = null)
  {{{
	$xmlquery = '<?xml version="1.0" encoding="utf-8" ?'.'> <a:propfind xmlns:a="DAV:"> <a:allprop/> </a:propfind>';

	$opts = array(
		'http'=>array(
			'method'=>"PROPFIND",
			'header'=>array("Depth: 1", "Content-Type: text/xml; charset=\"utf-8\""),
			'content'=> $xmlquery
		)
	);

	$reply = $this->cdfopen("list_groups", "", "r", false, $opts);
	$reply = $reply["body"];

	global $colls;
	$colls = array();
	$name = "Unnamed";
	$path = "";
	$isab = false;
	$ctag = "";
	$xml_parser = xml_parser_create_ns();
	xml_set_element_handler($xml_parser, "startElement_listgroups", "endElement_listgroups");
	xml_set_character_data_handler($xml_parser, "characterData_listgroups");
	xml_parse($xml_parser, $reply, true);
	xml_parser_free($xml_parser);

	return $colls;
  }}}

  public function create_group($name)
  {{{
	$result = false;

	$xmlquery = '<?xml version="1.0" encoding="utf-8" ?'.'> <D:mkcol xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:carddav"> <D:set> <D:prop> <D:resourcetype> <D:collection/> <C:addressbook/> </D:resourcetype> <D:displayname>'.$name.'</D:displayname> </D:prop> </D:set> </D:mkcol>';
	$opts = array(
		'http'=>array(
			'method'=>"MKCOL",
			'header'=>array("Content-Type: text/xml; charset=\"utf-8\""),
			'content'=> $xmlquery
		)
	);
	$reply = $this->cdfopen("create_group", "/".preg_replace(";[^A-Za-z0-9_-];", "_", $name), "r", false, $opts);
	if ($reply["status"] == 201){
		$carddav = carddavconfig();

		$ID = $carddav['url']."/$name";
		$ID = preg_replace(";^http.?://[^/]*;", "", $ID);
		$ID = preg_replace(";//;", "/", $ID);
		$ID = preg_replace(";/;", "_rcmcdslash_", $ID);
		$ID = preg_replace(";\\.;", "_rcmcddot_", $ID);
		$result = array();
		$result["id"] = $ID;
		$result["name"] = $name;
	}

	return $result;
  }}}

  public function delete_group($gid)
  {{{
	$result = false;

	$opts = array(
		'http'=>array(
			'method'=>"DELETE",
		)
	);
	$gid = str_replace("_rcmcdslash_", "/", $gid);
	$gid = str_replace("_rcmcddot_", ".", $gid);
	$gid = preg_replace(";/$;", "", $gid);
	$gid = preg_replace(";^.*/;", "", $gid);
	$reply = $this->cdfopen("delete_group", "/$gid", "r", false, $opts);
	if ($reply["status"] == 204){
		$result = true;
	}

	return $result;
  }}}

  public function rename_group($gid, $newname)
  {{{
	return;
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
	$ctag = "";
	global $vcards;
	$vcards = array();
	$cur_vcard = array();
	$xml_parser = xml_parser_create_ns();
	xml_set_element_handler($xml_parser, "startElement_addvcards", "endElement_addvcards");
	xml_set_character_data_handler($xml_parser, "characterData_addvcards");
	xml_parse($xml_parser, $reply, true);
	xml_parser_free($xml_parser);
	foreach ($vcards as $vcard){
		$vcf = new VCard;
		$vcf->parse(explode("\n", $vcard['vcf'])) || write_log("carddav", "Couldn't parse vcard ".$vcard['vcf']);
		$property = $vcf->getProperty("FN");
		if ($property){
			$name = $property->getComponents();
			$name = $name[0];
		}
		$property = $vcf->getProperty("N");
		if ($property){
			$N = $property->getComponents();
			$surname = $N[0];
			$firstname = $N[1];
		}
		$ID = $vcard['href'];
		if ($this->group){
			$ID = preg_replace(";^".$this->group.";", "", $ID);
		}
		$e = $vcf->getProperties("EMAIL");
		if ($e){
			foreach ($e as $f){
				$f = $f->getComponents();
				$emails[] = $f[0];
			}
		}
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
	$x = 0;
	foreach($this->array_sort($addresses, "name") as $a){
		$a['ID'] = $a['ID']."_rcmcddelim_".$a['email'];
		$a['ID'] = preg_replace("/@/", "_rcmcdat_", $a['ID']);
		$a['ID'] = preg_replace("/\./", "_rcmcddot_", $a['ID']);
		if (strlen($filter["value"]) > 0){
			foreach ($filter["keys"] as $key => $value){
				if (preg_match(";".$filter["value"].";i", $a[$value])){
					$x++;
					$this->result->add(array('ID' => $a['ID'], 'name' => $a['name'], 'firstname' => $a['firstname'], 'surname' => $a['surname'], 'email' => $a['email']));
				}
			}
		} else {
			$x++;
			$this->result->add(array('ID' => $a['ID'], 'name' => $a['name'], 'firstname' => $a['firstname'], 'surname' => $a['surname'], 'email' => $a['email']));
		}
	}
	return $x;
  }}}

  public function cdfopen($caller, $url, $mode, $use_include_path, $opts)
  {{{
	$carddav = carddavconfig();

	$http=new http_class;
	$http->timeout=10;
	$http->data_timeout=0;
	$http->user_agent="RCM CardDAV plugin/TRUNK";
	$http->follow_redirect=1;
	$http->redirection_limit=5;
	$http->prefer_curl=1;
	if ($caller == "list_groups" || $caller == "create_group" || $caller == "delete_group"){
		$url = $carddav['url'].$url;
	} else {
		preg_match("/^(http.?:\/\/[^\/]*)\//", $carddav['url'], $match);
		$url = $match[1].$this->group.$url;
	}
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
		$h = explode(": ", $opts['http']['header']);
		$arguments["Headers"][$h[0]] = $h[1];
	}
	$error = $http->Open($arguments);
	if ($error == ""){
		$error=$http->SendRequest($arguments);
		if ($this->DEBUG_HTTP){
			write_log("carddav", "DEBUG_HTTP cdfopen SendRequest: ".var_export($http, true));
		}
		if ($error == ""){
			$error=$http->ReadReplyHeaders($headers);
			if ($error == ""){
				$error = $http->ReadWholeReplyBody($body);
				if ($error == ""){
					$reply["status"] = $http->response_status;
					$reply["headers"] = $headers;
					$reply["body"] = $body;
					if ($this->DEBUG_HTTP){
						write_log("carddav", "DEBUG_HTTP cdfopen success: ".var_export($reply, true));
					}
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
	if ($this->DEBUG_HTTP){
		write_log("carddav", "DEBUG_HTTP cdfopen failed: ".var_export($http, true));
	}
	return "";
  }}}

  public function create_filter()
  {{{
# This is just a stub to satisfy Apples CalenderServer
# We should really use this more but for now we filter client side (in $this->addvcards)
	return "<C:filter/>";
  }}}

  public function list_records($cols=null, $subset=0)
  {{{
	$addresses = array();
	$this->result = $this->count();

	$xmlquery = '<?xml version="1.0" encoding="utf-8" ?'.'> <D:sync-collection xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:carddav"> <D:sync-token></D:sync-token> <D:prop> <D:getcontenttype/> <D:getetag/> <D:allprop/> <C:address-data> <C:allprop/> </C:address-data> </D:prop> ';
	$xmlquery .= $this->create_filter();
	$xmlquery .= ' </D:sync-collection>';
	$opts = array(
		'http'=>array(
			'method'=>"REPORT",
			'header'=>array("Depth: infinite", "Content-Type: text/xml; charset=\"utf-8\""),
			'content'=> $xmlquery
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
	$f = array();
	if (is_array($fields)){
		foreach ($fields as $k => $v){
			$f["keys"][] = $v;
		}
	} else {
		$f["keys"][] = $fields;
	}
	$f["value"] = $value;
	$this->set_search_set($f);
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
	$id = preg_replace("/_rcmcdat_/", "@", $id);
	$id = preg_replace("/_rcmcddot_/", ".", $id);
	$mail = $record[1];
	$mail = preg_replace("/_rcmcdat_/", "@", $mail);
	$mail = preg_replace("/_rcmcddot_/", ".", $mail);
	$reply = $this->cdfopen("get_record_from_carddav", "/$id", "r", false, $opts);
	if (!strlen($reply["body"])) { return false; }
	if ($reply["status"] == 404){
		write_log("carddav", "Request for VCF '$uid' which doesn't exits on the server.");
		return false;
	}
	return $reply["body"];
  }}}

  public function get_record($uid, $assoc=false)
  {{{
	$vcard = $this->get_record_from_carddav($uid);
	if (!$vcard)
		return false;
	$record = explode("_rcmcddelim_", $uid);
	$id = $record[0];
	$id = preg_replace("/_rcmcdat_/", "@", $id);
	$id = preg_replace("/_rcmcddot_/", ".", $id);
	$mail = $record[1];
	$mail = preg_replace("/_rcmcdat_/", "@", $mail);
	$mail = preg_replace("/_rcmcddot_/", ".", $mail);
	$vcf = new VCard;
	$vcf->parse(explode("\n", $vcard)) || write_log("carddav", "Couldn't parse vcard ".$vcard);
	$property = $vcf->getProperty("FN");
	if ($property){
		$name = $property->getComponents();
		$name = $name[0];
	}
	$property = $vcf->getProperty("N");
	if ($property){
		$N = $property->getComponents();
		$surname = $N[0];
		$firstname = $N[1];
	}
	$propfind = $vcf->getProperty("UID");
	if ($property){
		$UID = $property->getComponents();
		$uid = $UID[0];
	}
	$this->result->add(array('ID' => $uid, 'name' => $name, 'surname' => $surname, 'firstname' => $firstname, 'email' => $mail));
	$sql_arr = $assoc && $this->result ? $this->result->first() : null;
	return $assoc && $sql_arr ? $sql_arr : $this->result;
  }}}

  public function put_record_to_carddav($id, $vcf)
  {{{
	$id = preg_replace("/_rcmcdat_/", "@", $id);
	$id = preg_replace("/_rcmcddot_/", ".", $id);
	$this->result = $this->count();
	$opts = array(
		'http'=>array(
			'method'=>"PUT",
			'content'=>$vcf,
			'header'=>"Content-Type: text/vcard"
		)
	);
	$reply = $this->cdfopen("put_record_to_carddav", "/$id", "r", false, $opts);
	if ($reply["status"] >= 200 && $reply["status"] < 300) { return true; }
	return true;
  }}}

  public function delete_record_from_carddav($id, $vcf = "")
  {{{
	$this->result = $this->count();
	$opts = array(
		'http'=>array(
			'method'=>"DELETE",
		)
	);
	$reply = $this->cdfopen("delete_record_from_carddav", "/$id", "r", false, $opts);
	if ($reply["status"] == 204){
		return true;
	}
	return false;
  }}}

  public function update($oid, $save_cols)
  {{{
	$record = explode("_rcmcddelim_", $oid);
	$id = $record[0];
	$id = preg_replace("/_rcmcdat_/", "@", $id);
	$id = preg_replace("/_rcmcddot_/", ".", $id);
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
		$this->result->seek(0);
		while($record = $this->result->iterate()){
			if ($record['firstname'] == $save_data['firstname'] &&
			$record['surname'] == $save_data['surname'] &&
			$record['name'] == $save_data['name']){
				$id = $record["ID"];
				$update = true;
			}
		}
	}

	if ($update){
		$vcf = $this->get_record_from_carddav($id);
		$record = explode("_rcmcddelim_", $id);
		$vcf = preg_replace("/END:VCARD/", "EMAIL;TYPE=HOME:".$save_data['email']."\r\nEND:VCARD", $vcf);
		return $this->put_record_to_carddav($record[0], $vcf);
	} else {
		$id = $this->guid().".vcf";
		while ($this->get_record_from_carddav($id."_rcmcddelim_")){
			$id = $this->guid();
		}
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
			$ID = preg_replace(";^/;", "", $ID);
			$save_data["ID"] = $ID;
			return $ID;
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
		$id = preg_replace("/_rcmcdat_/", "@", $id);
		$id = preg_replace("/_rcmcddot_/", ".", $id);
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

  function add_to_group($group_id, $ids)
  {{{
	return false;
  }}}

  function remove_from_group($group_id, $ids)
  {{{
	return false;
  }}}

}
