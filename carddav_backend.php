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
  private $filter;
  private $result;
  public $coltypes;

  private $DEBUG = false;	# set to true for basic debugging
  private $DEBUG_HTTP = false;	# set to true for debugging raw http stream

  public function __construct()
  {{{
	$this->ready = true;
	$this->group = $_COOKIE["_cd_set_group"];
	$this->coltypes = array(
		'name'         => array('type' => 'text', 'size' => 40, 'maxlength' => 50, 'limit' => 1, 'label' => rcube_label('name'), 'category' => 'main'),
		'firstname'    => array('type' => 'text', 'size' => 19, 'maxlength' => 50, 'limit' => 1, 'label' => rcube_label('firstname'), 'category' => 'main'),
		'surname'      => array('type' => 'text', 'size' => 19, 'maxlength' => 50, 'limit' => 1, 'label' => rcube_label('surname'), 'category' => 'main'),
		'email'        => array('type' => 'text', 'size' => 40, 'maxlength' => 50, 'label' => rcube_label('email'), 'subtypes' => array('home','work','other','internet'), 'category' => 'main'),
		'middlename'   => array('type' => 'text', 'size' => 19, 'maxlength' => 50, 'limit' => 1, 'label' => rcube_label('middlename'), 'category' => 'main'),
		'prefix'       => array('type' => 'text', 'size' => 8,  'maxlength' => 20, 'limit' => 1, 'label' => rcube_label('nameprefix'), 'category' => 'main'),
		'suffix'       => array('type' => 'text', 'size' => 8,  'maxlength' => 20, 'limit' => 1, 'label' => rcube_label('namesuffix'), 'category' => 'main'),
		'nickname'     => array('type' => 'text', 'size' => 40, 'maxlength' => 50, 'limit' => 1, 'label' => rcube_label('nickname'), 'category' => 'main'),
		'jobtitle'     => array('type' => 'text', 'size' => 40, 'maxlength' => 50, 'limit' => 1, 'label' => rcube_label('jobtitle'), 'category' => 'main'),
		'organization' => array('type' => 'text', 'size' => 40, 'maxlength' => 50, 'limit' => 1, 'label' => rcube_label('organization'), 'category' => 'main'),
		'gender'       => array('type' => 'select', 'limit' => 1, 'label' => rcube_label('gender'), 'options' => array('male' => rcube_label('male'), 'female' => rcube_label('female')), 'category' => 'personal'),
		'phone'        => array('type' => 'text', 'size' => 40, 'maxlength' => 20, 'label' => rcube_label('phone'), 'subtypes' => array('home','home2','work','work2','mobile','cell','main','homefax','workfax','car','pager','video','assistant','other'), 'category' => 'main'),
		'address'      => array('type' => 'composite', 'label' => rcube_label('address'), 'subtypes' => array('home','work','other'), 'childs' => array(
			'street'     => array('type' => 'text', 'size' => 40, 'maxlength' => 50, 'label' => rcube_label('street'), 'category' => 'main'),
			'locality'   => array('type' => 'text', 'size' => 28, 'maxlength' => 50, 'label' => rcube_label('locality'), 'category' => 'main'),
			'zipcode'    => array('type' => 'text', 'size' => 8,  'maxlength' => 15, 'label' => rcube_label('zipcode'), 'category' => 'main'),
			'region'     => array('type' => 'text', 'size' => 12, 'maxlength' => 50, 'label' => rcube_label('region'), 'category' => 'main'),
			'country'    => array('type' => 'text', 'size' => 40, 'maxlength' => 50, 'label' => rcube_label('country'), 'category' => 'main'),), 'category' => 'main'),
		'birthday'     => array('type' => 'date', 'size' => 12, 'maxlength' => 16, 'label' => rcube_label('birthday'), 'limit' => 1, 'render_func' => 'rcmail_format_date_col', 'category' => 'personal'),
		'anniversary'  => array('type' => 'date', 'size' => 12, 'maxlength' => 16, 'label' => rcube_label('anniversary'), 'limit' => 1, 'render_func' => 'rcmail_format_date_col', 'category' => 'personal'),
		'website'      => array('type' => 'text', 'size' => 40, 'maxlength' => 50, 'label' => rcube_label('website'), 'subtypes' => array('homepage','work','blog','profile','other'), 'category' => 'main'),
		'notes'        => array('type' => 'textarea', 'size' => 40, 'rows' => 15, 'maxlength' => 500, 'label' => rcube_label('notes'), 'limit' => 1),
		'photo'        => array('type' => 'image', 'limit' => 1, 'category' => 'main'),
		'assistant'    => array('type' => 'text', 'size' => 40, 'maxlength' => 50, 'limit' => 1, 'label' => rcube_label('assistant'), 'category' => 'personal'),
		'manager'      => array('type' => 'text', 'size' => 40, 'maxlength' => 50, 'limit' => 1, 'label' => rcube_label('manager'), 'category' => 'personal'),
		'spouse'       => array('type' => 'text', 'size' => 40, 'maxlength' => 50, 'limit' => 1, 'label' => rcube_label('spouse'), 'category' => 'personal'),
		// TODO: define fields for vcards like GEO, KEY
	);
  }}}

  public function get_name()
  {{{
	return "CardDAV";
  }}}

  public function set_search_set($filter)
  {{{
	$newfilter = array('keys' => array(), 'value' => $filter['value']);
	foreach ($filter['keys'] as $key => $value){
		$xfilter = $value;
		if (strstr($xfilter, "*"))
			$xfilter = str_replace("*", ".*?", $value);
		foreach($this->coltypes as $key => $value){
			if (preg_match(";$xfilter;", $key)){
				if ($this->coltypes[$key]['subtypes']){
					foreach ($this->coltypes[$key]['subtypes'] AS $skey => $svalue){
						$newfilter['keys'][] = "$key:$svalue";
					}
				} else {
					$newfilter['keys'][] = $key;
				}
			}
		}
	}
	$this->filter = $newfilter;
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

  public function get_group()
  {{{
	$gid = $this->group ;
	$gid = str_replace("/", "_rcmcdslash_", $gid);
	$gid = str_replace(".", "_rcmcddot_", $gid);
	$gid = str_replace("%20", " ", $gid);
	return $gid;
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

	$reply = $this->cdfopen("list_groups", "", $opts);
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
	$reply = $this->cdfopen("create_group", "/".preg_replace(";[^A-Za-z0-9_-];", "_", $name), $opts);
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
	$reply = $this->cdfopen("delete_group", "/$gid", $opts);
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

  public function addvcards($reply, $try = 0)
  {{{
	$addresses = array();
	$ID = $name = null;
	$filter = $this->get_search_set();
	global $vcards;
	$vcards = array();
	$xml_parser = xml_parser_create_ns();
	xml_set_element_handler($xml_parser, "startElement_addvcards", "endElement_addvcards");
	xml_set_character_data_handler($xml_parser, "characterData_addvcards");
	xml_parse($xml_parser, $reply, true);
	xml_parser_free($xml_parser);
	$tryagain = array();
	foreach ($vcards as $vcard){
		$vcf = new VCard;
		if (!preg_match(";BEGIN;", $vcard['vcf'])){
# Seems like the server didn't give us the vcf data
			$tryagain[] = $vcard['href'];
			continue;
		}
		$save_data = $this->create_save_data_from_vcard($vcard['vcf']);
		if (!$save_data){
			write_log("carddav", "Couldn't parse vcard ".$vcard['vcf']);
			continue;
		}
		$ID = $vcard['href'];
		if ($this->group){
			$ID = preg_replace(";^".$this->group.";", "", $ID);
		}
		$ID = preg_replace(";\.;", "_rcmcddot_", $ID);
		$addresses[] = array('ID' => $ID, 'name' => $save_data['nickname'], 'save_data' => $save_data);
		$ID = $name = null;
	}
	$x = 0;
	foreach($this->array_sort($addresses, "name") as $a){
		if (strlen($filter["value"]) > 0){
			foreach ($filter["keys"] as $key => $value){
				if (is_array($a['save_data'][$value])){
					/* TODO: We should correctly iterate here ... Good enough for now*/
					foreach($a['save_data'][$value] AS $akey => $avalue){
						if (@preg_match(";".$filter["value"].";i", $avalue)){
							$x++;
							$a['save_data']['ID'] = $a['ID'];
							$a['save_data']['name'] = $a['save_data']['nickname']; // Holy Sh*t, RC 0.6 is inconsistent...
							$this->result->add($a['save_data']);
						}
					}
				} else {
					if (preg_match(";".$filter["value"].";i", $a['save_data'][$value])){
						$x++;
						$a['save_data']['ID'] = $a['ID'];
						$a['save_data']['name'] = $a['save_data']['nickname']; // see above
						$this->result->add($a['save_data']);
					}
				}
			}
		} else {
			$x++;
			$a['save_data']['ID'] = $a['ID'];
			$a['save_data']['name'] = $a['save_data']['nickname']; // see above
			$this->result->add($a['save_data']);
		}
	}
	if ($try < 3 && count($tryagain) > 0){
		$reply = $this->query_addressbook_multiget($tryagain);
		$reply = $reply["body"];
		$x += $this->addvcards($reply, ++$try);
	}
	return $x;
  }}}

  public function cdfopen($caller, $url, $opts)
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
		preg_match(";^(http.?://[^/]*)/;", $carddav['url'], $match);
		$url = preg_replace(";".$this->group.";", "", $url);
		$url = $match[1].$this->group.$url;
	}
	if ($this->DEBUG){
		write_log("carddav", "DEBUG cdfopen: $caller requesting $url");
	}
	$url = preg_replace("/:\/\//", "://".urlencode($carddav['username']).":".urlencode($carddav['password'])."@", $url);
	$error = $http->GetRequestArguments($url,$arguments);
	$arguments["RequestMethod"] = $opts['http']['method'];
	if (strlen($opts['http']['content']) > 0 && $opts['http']['method'] != "GET"){
		$arguments["Body"] = $opts['http']['content']."\r\n";
	}
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
					return -1;
				}
			} else {
				write_log("carddav", "cdfopen: Could not read reply header: ".$error);
				return -1;
			}
		} else {
			write_log("carddav", "cdfopen: Could not send request: ".$error);
			return -1;
		}
	} else {
		write_log("carddav", "cdfopen: Could not open: ".$error);
		return -1;
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
	$this->result = $this->count();

	$records = $this->list_records_sync_collection($cols, $subset);
	if ($records < 0){ /* returned error -1 */
		$records = $this->list_records_propfind_resourcetype($cols, $subset);
	}

	if ($records > 0){
		return $this->result;
	}

	return false;
  }}}

  public function list_records_sync_collection($cols, $subset)
  {{{
	$records = 0;
	$xmlquery =
		'<?xml version="1.0" encoding="utf-8" ?'.'>
			<D:sync-collection xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:carddav">
			<D:sync-token></D:sync-token>
			<D:prop>
				<D:getcontenttype/>
				<D:getetag/>
				<D:allprop/>
				<C:address-data>
					<C:allprop/>
				</C:address-data>
			</D:prop> ';
	$xmlquery .= $this->create_filter();
	$xmlquery .= ' </D:sync-collection>';
	$opts = array(
		'http'=>array(
			'method'=>"REPORT",
			'header'=>array("Depth: infinite", "Content-Type: text/xml; charset=\"utf-8\""),
			'content'=> $xmlquery
		)
	);

	if (($this->filter && !$this->group) || rcmail::get_instance()->action == "autocomplete"){
		$colls = $this->list_groups();
	} else {
		$colls = array(0 => array(ID => $this->get_group()));
	}

	foreach ($colls as $key => $value){
		if ($this->filter || rcmail::get_instance()->action == "autocomplete"){
			$this->set_group($value["ID"]);
		}
		$reply = $this->cdfopen("list_records_sync_collection", "", $opts);
		if ($reply == -1){ /* error occured, as opposed to "" which means empty reply */
			return -1;
		}
		$reply = $reply["body"];
		if (strlen($reply)) {
			$records += $this->addvcards($reply);
		}
	}
	return $records;
  }}}

  public function query_addressbook_multiget($hrefs)
  {{{
	$xmlquery =
		'<?xml version="1.0" encoding="utf-8" ?'.'>
			<C:addressbook-multiget xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:carddav">
				<D:prop>
					<D:getetag/>
					<C:address-data>
						<C:allprop/>
					</C:address-data>
				</D:prop> ';
	foreach ($hrefs as $href){
		$xmlquery .= "<D:href>$href</D:href> ";
	}
	$xmlquery .= "</C:addressbook-multiget>";

	$optsREPORT = array(
		'http' => array(
			'method'=>"REPORT",
			'header'=>array("Depth: 1", "Content-Type: text/xml; charset=\"utf-8\""),
			'content'=>$xmlquery
		)
	);

	$reply = $this->cdfopen("query_addressbook_multiget", "", $optsREPORT);
	return $reply;
  }}}

  public function list_records_propfind_resourcetype($cols, $subset)
  {{{
	$records = 0;
	$xmlquery =
		'<?xml version="1.0" encoding="utf-8" ?'.'>
			<a:propfind xmlns:a="DAV:">
				<a:prop>
					<a:resourcetype/>
				</a:prop>
			</a:propfind>';
	$opts = array(
		'http'=>array(
			'method'=>"PROPFIND",
			'header'=>array("Depth: 1", "Content-Type: text/xml; charset=\"utf-8\""),
			'content'=> $xmlquery
		)
	);

	if (($this->filter && !$this->group) || rcmail::get_instance()->action == "autocomplete"){
		$colls = $this->list_groups();
	} else {
		$colls = array(0 => array(ID => $this->get_group()));
	}

	foreach ($colls as $key => $value){
		if ($this->filter){
			$this->set_group($value["ID"]);
		}
		$reply = $this->cdfopen("list_records_propfind_resourcetype", "", $opts);
		if ($reply == -1){ /* error occured, as opposed to "" which means empty reply */
			return -1;
		}
		$reply = $reply["body"];
		if (strlen($reply)) {
			$xml_parser = xml_parser_create_ns();
			global $vcards;
			$vcards = array();
			xml_set_element_handler($xml_parser, "startElement_addvcards", "endElement_addvcards");
			xml_set_character_data_handler($xml_parser, "characterData_addvcards");
			xml_parse($xml_parser, $reply, true);
			xml_parser_free($xml_parser);
			$urls = array();
			foreach($vcards as $vcard){
				$urls[] = $vcard['href'];
			}
			$reply = $this->query_addressbook_multiget($urls);
			write_log("carddav", var_export($reply, true));
			$reply = $reply["body"];
			$records += $this->addvcards($reply);
		}
	}
	return $records;
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
	$opts = array(
		'http'=>array(
			'method'=>"GET",
		)
	);
	$reply = $this->cdfopen("get_record_from_carddav", "$uid", $opts);
	if (!strlen($reply["body"])) { return false; }
	if ($reply["status"] == 404){
		write_log("carddav", "Request for VCF '$uid' which doesn't exits on the server.");
		return false;
	}
	return $reply["body"];
  }}}

  public function get_record($oid, $assoc_return=false)
  {{{
	$this->result = $this->count();
	$uid = preg_replace(";_rcmcddot_;", ".", $oid);
	$vcard = $this->get_record_from_carddav($uid);
	if (!$vcard)
		return false;
	$retval = $this->create_save_data_from_vcard($vcard);
	if (!$retval)
		return false;
	$ID = $oid;
	if ($this->group){
		$ID = preg_replace(";^".$this->group.";", "", $ID);
	}
	$retval['ID'] = $ID;
	$this->result->add($retval);
	$sql_arr = $assoc_return && $this->result ? $this->result->first() : null;
	return $assoc_return && $sql_arr ? $sql_arr : $this->result;
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
	$reply = $this->cdfopen("put_record_to_carddav", "/$id", $opts);
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
	$id = preg_replace(";_rcmcddot_;", ".", $id);
	$reply = $this->cdfopen("delete_record_from_carddav", "$id", $opts);
	if ($reply["status"] == 204){
		return true;
	}
	return false;
  }}}

  public function guid()
  {{{
	return sprintf('%04X%04X-%04X-%04X-%04X-%04X%04X%04X', mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(16384, 20479), mt_rand(32768, 49151), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535));
  }}}

  public function create_vcard_from_save_data($id, $save_data)
  {{{
	    /* {{{
array (
  'name' => 'Test Account',
  'firstname' => 'Test',
  'surname' => 'Account',
  'email:home' =>
  array (
    0 => 'home@test.test',
    1 => 'home2@test.test',
  ),
  'email:work' =>
  array (
    0 => 'work@test.test',
  ),
  'email:other' =>
  array (
    0 => 'other@test.test',
  ),
  'email:internet' =>
  array (
    0 => 'internet@test.test',
  ),
  'middlename' => 'Testy',
  'prefix' => 'Herr',
  'suffix' => 'BLAH',
  'nickname' => 'Testy',
  'jobtitle' => 'Tester',
  'organization' => 'Test Company',
  'gender' => 'male',
  'phone:home' =>
  array (
    0 => '+491234567890',
    1 => '+501234567890',
  ),
  'phone:home2' =>
  array (
    0 => '+381234567890',
  ),
  'address:home' =>
  array (
    0 =>
    array (
      'street' => 'street',
      'locality' => 'city',
      'zipcode' => 'postal',
      'region' => 'subregion',
      'country' => 'country',
    ),
  ),
  'birthday' => '01.01.1970',
  'anniversary' => '01.08.1995',
  'website:homepage' =>
  array (
    0 => 'Website',
  ),
  'notes' => 'Just some short short notes.',
  'photo' => <binary data>,
  'assistant' => 'Assistent to Mr. Account',
  'manager' => 'Superior Officer',
  'spouse' => 'Misses Account',
)
}}} */
	$vcf = "BEGIN:VCARD\r\n".
		"VERSION:3.0\r\n".
		"UID:$id\r\n".
	$vcf .= "N:".$save_data['surname'].";".$save_data['firstname'].";".$save_data['middlename'].";".$save_data['prefix'].";".$save_data['suffix']."\r\n";
	$assoc = array('FN' => 'nickname', 'TITLE' => 'jobtitle', 'ORG' => 'organization', 'BDAY' => 'birthday',
		       'NOTE' => 'notes', 'X-ASSISTANT' => 'assistant', 'X-MANAGER' => 'manager', 'X-SPOUSE' => 'spouse',
		       'X-GENDER' => 'gender', 'X-ANNIVERSARY' => 'anniversary');
	foreach ($assoc as $key => $value){
		$vcf .= $key.":".$save_data[$value]."\r\n";
	}
	$assoc = array('EMAIL' => 'email', 'URL' => 'website', 'TEL' => 'phone');
	foreach ($assoc as $key => $value){
		foreach ($this->coltypes[$value]['subtypes'] AS $ckey => $cvalue){
			foreach($save_data[$value.':'.$cvalue] AS $ekey => $evalue){
				$vcf .= $key.";TYPE=".strtoupper($cvalue).":".$evalue."\r\n";
			}
		}
	}

	foreach ($this->coltypes['address']['subtypes'] AS $key => $value){
		if (in_array('address:'.$value, $save_data)){
			foreach($save_data['address:'.$value] AS $akey => $avalue){
				$vcf .= "ADR;TYPE=".strtoupper($value).":;;".$avalue['street'].";".$avalue['locality'].";".$avalue['region'].";".$avalue['zipcode'].";".$avalue['country']."\r\n";
			}
		}
	}

	$vcf .= "PHOTO;ENCODING=b:".base64_encode($save_data['photo'])."\r\n";
	$vcf .= "END:VCARD\r\n";

	return $vcf;
  }}}

  public function create_save_data_from_vcard($vcard)
  {{{
	$vcf = new VCard;
	$vcard = preg_replace(";\n ;", "", $vcard);
	if (!$vcf->parse(explode("\n", $vcard))){
		write_log("carddav", "Couldn't parse vcard ".$vcard);
		return false;
	}

	$assoc = array('FN' => 'nickname', 'TITLE' => 'jobtitle', 'ORG' => 'organization', 'BDAY' => 'birthday',
		       'NOTE' => 'notes', 'PHOTO' => 'photo', 'X-ASSISTANT' => 'assistant', 'X-MANAGER' => 'manager', 'X-SPOUSE' => 'spouse',
		       'X-GENDER' => 'gender');
	$retval = array('ID' => $oid);

	foreach ($assoc as $key => $value){
		$property = $vcf->getProperty($key);
		if ($property){
			$p = $property->getComponents();
			$retval[$value] = $p[0];
		}
	}
	$retval['photo'] = base64_decode($retval['photo']);
	$property = $vcf->getProperty("N");
	if ($property){
		$N = $property->getComponents();
		$retval['surname'] = $N[0];
		$retval['firstname'] = $N[1];
		$retval['middlename'] = $N[2];
		$retval['prefix'] = $N[3];
		$retval['suffix'] = $N[4];
	}
	$assoc = array('EMAIL' => 'email', 'TEL' => 'phone', 'URL' => 'website');
	foreach ($assoc as $key => $value){
		$property = $vcf->getProperties($key);
		if ($property){
			foreach ($property as $pkey => $pvalue){
				$p = $pvalue->getComponents();
				$k = strtolower($pvalue->params['TYPE'][0]);
				if (in_array($k, $this->coltypes[$value]['subtypes'])){
					$retval[$value.':'.$k][] = $p[0];
				} else {
					$retval[$value.':other'][] = $p[0];
				}
			}
		}
	}

	$property = $vcf->getProperties("ADR");
	if ($property){
		foreach ($property as $pkey => $pvalue){
			$p = $pvalue->getComponents();
			$k = strtolower($pvalue->params['TYPE'][0]);
			// post office box; the extended address; the street address; the locality (e.g., city); the region (e.g., state or province); the postal code; the country name
			$adr = array("pobox" => $p[0], "extended" => $p[1], "street" => $p[2], "locality" => $p[3], "region" => $p[4], "zipcode" => $p[5], "country" => $p[6]);
			if (in_array($k, $this->coltypes['address']['subtypes'])){
				$retval['address:'.$k][] = $adr;
			} else {
				$retval['address:other'][] = $adr;
			}
		}
	}
	return $retval;
  }}}

  public function insert($save_data, $check=false)
  {{{
	$id = $this->guid();
	while ($this->get_record_from_carddav($id.".vcf")){
		$id = $this->guid();
	}

	$vcf = $this->create_vcard_from_save_data($id, $save_data);

	if ($this->put_record_to_carddav($id, $vcf)){
		return $id;
	}
	return false;
  }}}

  public function update($oid, $save_data)
  {{{
	$oid = preg_replace("/_rcmcddot_/", ".", $oid);
	$id = preg_replace(";\.vcf$;", "", $oid);
	$vcf = $this->create_vcard_from_save_data($id, $save_data);

	return $this->put_record_to_carddav($oid, $vcf);
  }}}

  public function delete($ids)
  {{{
	foreach ($ids as $uid){
		return $this->delete_record_from_carddav($uid);
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
