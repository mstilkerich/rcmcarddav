<?php

/**
 * CardDAV backend class
 *
 * @author Benjamin Schieder <blindcoder@scavenger.homeip.net>
 */
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
	foreach (explode("\r\n", $reply) as $line){
		$line = htmlspecialchars_decode($line);
		if (preg_match("/^FN:(.*)$/", $line, $match)) { $name = $match[1]; }
		if (preg_match("/^N:(.*?);([^;]*)/", $line, $match)) { $surname = $match[1]; $firstname = $match[2]; }
		if (preg_match("/^UID:(.*)$/", $line, $match)) { $ID = $match[1]; }
		if (preg_match("/^EMAIL.*?:(.*)$/", $line, $match)) { $emails[] = $match[1]; }

		if (preg_match("/^END:VCARD$/", $line)){
			$name = $surname." ".$firstname;
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
	foreach($this->array_sort($addresses, "name") as $a){
		if (strlen($filter) > 0){
			if (preg_match("/$filter/", $a["name"]." ".$a["email"])){
			$a['ID'] = $a['ID']."+delim+".$a['email'];
			$a['ID'] = preg_replace("/@/", "+at+", $a['ID']);
			$a['ID'] = preg_replace("/\./", "+dot+", $a['ID']);
			$this->result->add(array('ID' => $a['ID'], 'name' => $a['name'], 'firstname' => $a['firstname'], 'surname' => $a['surname'], 'email' => $a['email']));
			}
		} else {
			$a['ID'] = $a['ID']."+delim+".$a['email'];
			$a['ID'] = preg_replace("/@/", "+at+", $a['ID']);
			$a['ID'] = preg_replace("/\./", "+dot+", $a['ID']);
			$this->result->add(array('ID' => $a['ID'], 'name' => $a['name'], 'firstname' => $a['firstname'], 'surname' => $a['surname'], 'email' => $a['email']));
		}
	}
  }}}

  public function cdfopen($caller, $url, $mode, $use_include_path, $context)
  {{{
	ob_flush();
	ob_start();
	$fd = fopen($url, $mode, $use_include_path, $context);
	$s = ob_get_clean();
	if (strlen($s) > 0) {
		write_log("carddav", "$caller: fopen_error: $s");
		return false;
	}
	return $fd;
  }}}

  public function list_records($cols=null, $subset=0)
  {{{
	$rcmail = rcmail::get_instance();
	$user = $rcmail->user;;
	if (!file_exists(dirname(__FILE__) . "/config.".$user->data['username'].".inc.php"))
		return null;
	include(dirname(__FILE__) . "/config.".$user->data['username'].".inc.php");
	$auth = base64_encode($carddav['username'].":".$carddav['password']);
	$addresses = array();
	$this->result = $this->count();
	$optsLISTVCF = array(
		'http'=>array(
			'method'=>"REPORT",
			'header'=>array("Authorization: Basic $auth", "Depth: infinite", "Content-type: text/xml"),
			'content'=>'<?xml version="1.0" encoding="utf-8" ?> <C:addressbook-query xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:carddav"> <D:prop> <D:getetag/> <C:address-data> <C:prop name="UID"/> <C:prop name="NICKNAME"/> <C:prop name="N"/> <C:prop name="EMAIL"/> <C:prop name="FN"/> </C:address-data> </D:prop> </C:addressbook-query>'
		)
	);

	$context = stream_context_create($optsLISTVCF);
	$ID = $name = $firstname = $surname = $email = NULL;
	$fd = $this->cdfopen("list_records", $carddav['url'], "r", false, $context);
	if (!$fd) { return false; }
	$replyheader = stream_get_meta_data($fd);
	$reply = stream_get_contents($fd);
	$reply = preg_replace("/\r\n[ \t]/","",$reply);
	$this->addvcards($reply);
	return $this->result;
  }}}

  public function search($fields, $value, $strict=false, $select=true, $nocount=false, $required=array())
  {{{
	$this->set_search_set($value);
	if (!$this->list_records()){
		return false;
	}
	if($this->result->first()){
		return $this->result;
	} else {
		return false;
	}
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
	$rcmail = rcmail::get_instance();
	$user = $rcmail->user;;
	if (!file_exists(dirname(__FILE__) . "/config.".$user->data['username'].".inc.php"))
		return null;
	include(dirname(__FILE__) . "/config.".$user->data['username'].".inc.php");
	$auth = base64_encode($carddav['username'].":".$carddav['password']);
	$this->result = $this->count();
	$optsGETVCF = array(
		'http'=>array(
			'method'=>"GET",
			'header'=>"Authorization: Basic $auth"
		)
	);
	$record = explode("+delim+", $uid);
	$id = $record[0];
	$mail = $record[1];
	$mail = preg_replace("/\+at\+/", "@", $mail);
	$mail = preg_replace("/\+dot\+/", ".", $mail);
	$context = stream_context_create($optsGETVCF);
	$fd = $this->cdfopen("get_record_from_carddav", $carddav['url']."/$id.vcf", "r", false, $context);
	if (!$fd)
		return false;
	$replyheader = stream_get_meta_data($fd);
	$reply = stream_get_contents($fd);
	$reply = preg_replace("/\r\n[ \t]/","",$reply);
	fclose($fd);
	if (preg_match("/^Resource Not Found/", $reply)){
		write_log("carddav", "Request for VCF '$uid' which doesn't exits on the server.");
		return null;
	}
	return $reply;
  }}}

  public function get_record($uid, $assoc=false)
  {{{
	$vcf = $this->get_record_from_carddav($uid);
	if (!$vcf)
		return false;
	$record = explode("+delim+", $uid);
	$id = $record[0];
	$mail = $record[1];
	$mail = preg_replace("/\+at\+/", "@", $mail);
	$mail = preg_replace("/\+dot\+/", ".", $mail);
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
	$rcmail = rcmail::get_instance();
	$user = $rcmail->user;;
	if (!file_exists(dirname(__FILE__) . "/config.".$user->data['username'].".inc.php"))
		return null;
	include(dirname(__FILE__) . "/config.".$user->data['username'].".inc.php");
	$auth = base64_encode($carddav['username'].":".$carddav['password']);
	$this->result = $this->count();
	$optsGETVCF = array(
		'http'=>array(
			'method'=>"PUT",
			'header'=>"Authorization: Basic $auth",
			'content'=>$vcf
		)
	);
	$context = stream_context_create($optsGETVCF);
	$fd = $this->cdfopen("put_record_to_carddav", $carddav['url']."/$id.vcf", "r", false, $context);
	if (!$fd)
		return false;
	$replyheader = stream_get_meta_data($fd);
	$reply = stream_get_contents($fd);
	fclose($fd);
	return true;
  }}}

  public function delete_record_from_carddav($id, $vcf)
  {{{
	$rcmail = rcmail::get_instance();
	$user = $rcmail->user;;
	if (!file_exists(dirname(__FILE__) . "/config.".$user->data['username'].".inc.php"))
		return null;
	include(dirname(__FILE__) . "/config.".$user->data['username'].".inc.php");
	$auth = base64_encode($carddav['username'].":".$carddav['password']);
	$this->result = $this->count();
	$optsGETVCF = array(
		'http'=>array(
			'method'=>"DELETE",
			'header'=>"Authorization: Basic $auth"
		)
	);
	$context = stream_context_create($optsGETVCF);
	$fd = $this->cdfopen("delete_record_from_carddav", $carddav['url']."/$id.vcf", "r", false, $context);
	if (!$fd)
		return false;
	$replyheader = stream_get_meta_data($fd);
	$reply = stream_get_contents($fd);
	fclose($fd);
	foreach ($replyheader["wrapper_data"] as $line){
		if (preg_match("/^HTTP.*204/", $line)){
			return true;
		}
	}
	return false;
  }}}

  public function update($oid, $save_cols)
  {{{
	$record = explode("+delim+", $oid);
	$id = $record[0];
	$mail = $record[1];
	$mail = preg_replace("/\+at\+/", "@", $mail);
	$mail = preg_replace("/\+dot\+/", ".", $mail);

	$vcf = $this->get_record_from_carddav($oid);
	if (!$vcf)
		return false;
	$vcfnew = preg_replace("/\nFN:.*?\r\n/", "\nFN:".$save_cols['name']."\r\n", $vcf);
	$vcfnew = preg_replace("/\nN:[^;]*;[^;]*/", "\nN:".$save_cols['surname'].";".$save_cols['firstname']."", $vcfnew);
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
		while ($this->get_record_from_carddav($id."+delim+")){
			$id = $this->guid();
		}
	}

	if ($update){
		$vcf = $this->get_record_from_carddav($id);
		$record = explode("+delim+", $id);
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
			$ID = $id."+delim+".$save_data['email'];
			$ID = preg_replace("/@/", "+at+", $ID);
			$ID = preg_replace("/\./", "+dot+", $ID);
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
		$record = explode("+delim+", $uid);
		$id = $record[0];
		$mail = $record[1];
		$mail = preg_replace("/\+at\+/", "@", $mail);
		$mail = preg_replace("/\+dot\+/", ".", $mail);
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
