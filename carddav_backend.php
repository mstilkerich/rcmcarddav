<?php

/**
 * CardDAV backend class
 *
 * @author Benjamin Schieder <blindcoder@scavenger.homeip.net>
 */
class carddav_backend extends rcube_addressbook
{
  public $primary_key = 'ID';
  public $readonly = true;
  public $groups = true;
  
  private $filter;
  private $result;
  
  public function __construct()
  {{{
    $this->ready = true;
  }}}
  
  public function set_search_set($filter)
  {{{
    write_log('carddav', "New filter: $filter");
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

  function list_groups($search = null)
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
/*
   <response>
   ...
   <href>/caldav.php/blindcoder/personalAB/ID.vcf</href>
	<VC:address-data>BEGIN:VCARD
VERSION:3.0
EMAIL;TYPE=HOME:mail@example.com
EMAIL;TYPE=HOME:anothermail@example.com
FN:Full Name
N:Name;Full;;;
TEL;TYPE=CELL:+491111111111
TEL;TYPE=HOME:+492222222222
TEL;TYPE=WORK:+493333333333
UID:12345678-90ab-cdef-1234-567890abcdef
END:VCARD
</VC:address-data>
...
</response>
*/
    $addresses = array();
    $emails = array();
    $ID = $name = $firstname = $surname = $email = NULL;
    $filter = $this->get_search_set();
    foreach (explode("\r\n", $reply) as $line){
	//if (preg_match("/^FN:(.*)$/", $line, $match)) { $name = $match[1]; }
	if (preg_match("/^N:(.*?);(.*?);/", $line, $match)) { $surname = $match[1]; $firstname = $match[2]; }
	if (preg_match("/^UID:(.*)$/", $line, $match)) { $ID = $match[1]; }
	if (preg_match("/^EMAIL.*?:(.*)$/", $line, $match)) { $emails[] = $match[1]; }

	if (preg_match("/^END:VCARD$/", $line)){
	    $name = $surname." ".$firstname;
	    if ($emails[0]){
	        foreach ($emails as $email){
	          write_log("carddav", "Adding: $ID as $name ($surname $firstname) with $email");
	          $addresses[] = array('ID' => $ID, 'name' => $name, 'firstname' => $firstname, 'surname' => $surname, 'email' => $email);
	        }
	        $emails = array();
	        $ID = $name = $firstname = $surname = $email = NULL;
	    } else {
	          write_log("carddav", "Adding: $ID as $name ($surname $firstname) with no email");
	          $addresses[] = array('ID' => $ID, 'name' => $name, 'firstname' => $firstname, 'surname' => $surname, 'email' => $email);
	          $emails = array();
	          $ID = $name = $firstname = $surname = $email = NULL;
	    }
	}
    }
    foreach($this->array_sort($addresses, "name") as $a){
	if (strlen($filter) > 0){
	    if (preg_match("/$filter/", $a["name"]." ".$a["email"])){
		$this->result->add(array('ID' => $a['ID'], 'name' => $a['name'], 'firstname' => $a['firstname'], 'surname' => $a['surname'], 'email' => $a['email']));
	    }
	} else {
	    $this->result->add(array('ID' => $a['ID'], 'name' => $a['name'], 'firstname' => $a['firstname'], 'surname' => $a['surname'], 'email' => $a['email']));
	}
    }
  }}}

  public function list_records($cols=null, $subset=0)
  {{{
    $rcmail = rcmail::get_instance();
    $user = $rcmail->user;;
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
    //$fd = fopen("https://ical.anderdonau.de/caldav.php/blindcoder/personalAB/", "r", false, $context);
    write_log('carddav', $carddav['url']);
    $fd = fopen($carddav['url'], "r", false, $context);
    $replyheader = stream_get_meta_data($fd);
    $reply = stream_get_contents($fd);
    $this->addvcards($reply);
    return $this->result;
  }}}

  public function search($fields, $value, $strict=false, $select=true, $nocount=false, $required=array())
  {{{
    $this->set_search_set($value);
    $this->list_records();
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

  public function get_record($id, $assoc=false)
  {{{
    $rcmail = rcmail::get_instance();
    $user = $rcmail->user;;
    include(dirname(__FILE__) . "/config.".$user->data['username'].".inc.php");
    $auth = base64_encode($carddav['username'].":".$carddav['password']);
    $this->result = $this->count();
    $optsGETVCF = array(
	'http'=>array(
	    'method'=>"GET",
	    'header'=>"Authorization: Basic $auth"
	)
    );
    $context = stream_context_create($optsGETVCF);
    $fd = fopen($carddav['url']."/$id.vcf", "r", false, $context);
    $replyheader = stream_get_meta_data($fd);
    $reply = stream_get_contents($fd);
    foreach (explode("\r\n", $reply) as $line){
	if (preg_match("/^FN:(.*)$/", $line, $match)) { $name = $match[1]; }
	if (preg_match("/^N:(.*?);(.*?);/", $line, $match)) { $surname = $match[1]; $firstname = $match[2]; }
	if (preg_match("/^EMAIL.*?:(.*)$/", $line, $match)) { $email = $match[1]; }
	if (preg_match("/^UID:(.*)$/", $line, $match)) { $ID = $match[1]; }
    }
    $this->result->add(array('ID' => $ID, 'name' => $name, 'surname' => $surname, 'firstname' => $firstname, 'email' => $email));
    $sql_arr = $assoc && $this->result ? $this->result->first() : null;
    return $assoc && $sql_arr ? $sql_arr : $this->result;
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
