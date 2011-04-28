<?php

$rcmail = rcmail::get_instance();
$user = $rcmail->user;;
require_once(dirname(__FILE__) . "/config.".$user->data['username'].".inc.php");
require_once(dirname(__FILE__) . '/carddav_backend.php');

class carddav extends rcube_plugin
{
  private $abook_id = "CardDAV";
  
  public function init()
  {{{
    $this->add_hook('addressbooks_list', array($this, 'address_sources'));
    $this->add_hook('addressbook_get', array($this, 'get_address_book'));
    
    // use this address book for autocompletion queries
    // (maybe this should be configurable by the user?)
    $config = rcmail::get_instance()->config;
    $sources = (array) $config->get('autocomplete_addressbooks', array('sql'));
    if (!in_array($this->abook_id, $sources)) {
      $sources[] = $this->abook_id;
      $config->set('autocomplete_addressbooks', $sources);
    }
  }}}
  
  public function address_sources($p)
  {{{
    $abook = new carddav_backend;
    $p['sources'][$this->abook_id] = array(
      'id' => $this->abook_id,
      'name' => 'CardDAV',
      'readonly' => $abook->readonly,
      'groups' => $abook->groups,
    );
    return $p;
  }}}
  
  public function get_address_book($p)
  {{{
    if ($p['id'] === $this->abook_id) {
      $p['instance'] = new carddav_backend;
    }
    
    return $p;
  }}}
  
}
