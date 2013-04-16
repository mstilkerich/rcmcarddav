<?php

/*
 * File:
 *      vcard.php
 *
 * Project:
 *      vCard PHP <http://vcardphp.sourceforge.net>
 *
 * Author:
 *      Frank Hellwig <frank@hellwig.org>
 *
 * Usage:
 *      Use the following URL to display the help text:
 *
 *              http://host/path/vcard.php
 *
 * License:
 *      BSD
 */

/**
 * The VCard class encapsulates a single vCard object by maintaining a map of
 * property names to one or more property values associated with that name.
 * A name is the unique property identifier such as N, ADR, and TEL.
 */
class VCard
{
	/**
	 * An associative array where each key is the property name and each value
	 * is a VCardProperty array of properties which share that property name.
	 */
	var $_map;
	private $usedGroups = array();

	/**
	 * Parses a vCard from one or more lines. Lines that are not property
	 * lines, such as blank lines, are skipped. Returns false if there are
	 * no more lines to be parsed.
	 */
	function parse($lines)
	{
		if(!is_array($lines)) {
			$lines = preg_replace(";\r?\n[ \t];", "", $lines);
			$lines = explode("\n", $lines);
		}

		$this->_map = null;
		$property = new VCardProperty();
		while ($property->parse($lines)) {
			if($property->getGroup() && !in_array($property->getGroup(), $this->usedGroups))
				$this->usedGroups[] = $property->getGroup();

			if (is_null($this->_map)) {
				if ($property->name == 'BEGIN') {
					$this->_map = array();
				}
			} else {
				if ($property->name == 'END') {
					break;
				} else {
					$this->_map[$property->name][] = $property;
				}
			}
			// MDH: Create new property to prevent overwriting previous one
			// (PHP5)
			$property = new VCardProperty();
		}
		return $this->_map != null;
	}

	function genGroupLabel()
	{
		for($i=1; in_array("item$i", $this->usedGroups); $i++);
		$grp = "item$i";
		$this->usedGroups[] = $grp;
		return $grp;
	}

	function toString()
	{
		$line =  "BEGIN:VCARD\r\n";
		foreach($this->_map as $pname => $props) {
			foreach($props as $prop) {
				$line .= $prop->toString();
			}
		}
		$line .= "END:VCARD\r\n";
		return $line;
	}

	function deletePropertyByValue($name,$value,$delgroup=true)
	{
		$name = strtoupper($name);
		if(array_key_exists($name,$this->_map)) {
			for($i=count($this->_map[$name])-1; $i>=0; $i--) {
				if($this->_map[$name][$i]->getValue() === $value) {
					$this->deleteProperty($name,$delgroup,$i,1);
					return true;
				}
			}
		}
		return false;
	}

	function deletePropertyByGroup($name,$group,$delgroup=false)
	{
		$name = strtoupper($name);
		if(array_key_exists($name,$this->_map)) {
			for($i=count($this->_map[$name])-1; $i>=0; $i--) {
				if($this->_map[$name][$i]->getGroup() === $group) {
					$this->deleteProperty($name,$delgroup,$i,1);
					return true;
				}
			}
		}
		return false;
	}

	// delgroup: if true, for each deleted property delete all other properties
	//   of the vcard that belong to that group as well. Should be enabled if a
	//   "master property" is deleted
	function deleteProperty($name,$delgroup=true,$from=0,$numelem=0)
	{
		$name = strtoupper($name);
		if(array_key_exists($name,$this->_map)) {
			if($numelem == 0)
				$numelem = count($this->_map[$name]) - $from;

			$del = array_splice($this->_map[$name], $from, $numelem);

			if(count($this->_map[$name])==0) { // delete entry as well
				unset($this->_map[$name]);
			}

			if($delgroup) {
				$delgroups = array();
				foreach($del as $d) {
					if($d->getGroup())
						$delgroups[] = $d->getGroup();
				}
				if(count($delgroups)) {
				// not very efficient by should be ok for typically sized vcards...
				foreach($this->_map as $pkey => &$props) {
					for($i=count($props)-1; $i>=0; $i--) {
						if(in_array($props[$i]->getGroup(), $delgroups))
							array_splice($props, $i, 1);
					}
					if(count($props) == 0)
						unset($this->_map[$pkey]);
				} }
			}
		}
	}

	// changes the value of a property or creates the property if it does not exist
	function setProperty($name, $value, $idx=0, $pvidx=0, $group='',$pvdelim=';')
	{
		$name = strtoupper($name);
		// does the mapping exist at all?
		if(!array_key_exists($name,$this->_map)) {
			$this->_map[$name] = array();
		}

		// idx < 0? => append new
		if($idx < 0)
			$idx = count($this->_map[$name]);

		// do we need to create a new property?
		if(count($this->_map[$name]) <= $idx) {
			$idx = count($this->_map[$name]);
			$this->_map[$name][] = new VCardProperty();
			// init name and group
			$this->_map[$name][$idx]->name  = $name;
			$this->_map[$name][$idx]->group = $group;
		}

		// modify the (now) existing property
		$this->_map[$name][$idx]->setComponent($value, $pvidx, $pvdelim);
		return $idx;
	}

	/**
	 * Returns the first property mapped to the specified name or null if
	 * there are no properties with that name.
	 */
	function getProperty($name, $group='')
	{
		$name = strtoupper($name);

		if(array_key_exists($name,$this->_map)) {
			foreach($this->_map[$name] as $v) {
				// if $group is not set the first will match
				if($v->getGroup() === $group)
					return $v;
			}
		}
		return null;
	}

	/**
	 * Returns the properties mapped to the specified name or an empty array if
	 * there are no properties with that name.
	 */
	function getProperties($name)
	{
		$name = strtoupper($name);
		if(array_key_exists($name,$this->_map))
			return $this->_map[$name];
		return array();
	}

	/**
	 * Returns an array of categories for this card or a one-element array with
	 * the value 'Unfiled' if no CATEGORIES property is found.
	 */
	function getCategories()
	{
		$property = $this->getProperty('CATEGORIES');
		// The Mac OS X Address Book application uses the CATEGORY property
		// instead of the CATEGORIES property.
		if (!$property) {
			$property = $this->getProperty('CATEGORY');
		}
		if ($property) {
			$result = $property->getComponents(',');
		} else {
			$result = array('Unfiled');
		}
		$result[] = "All";      // Each card is always a member of "All"
		return $result;
	}

	/**
	 * Returns true if the card belongs to at least one of the categories.
	 */
	function inCategories(&$categories)
	{
		$our_categories = $this->getCategories();
		foreach ($categories as $category) {
			if (in_array_case($category, $our_categories)) {
				return true;
			}
		}
		return false;
	}
}

/**
 * The VCardProperty class encapsulates a single vCard property consisting
 * of a name, zero or more parameters, and a value.
 *
 * The parameters are stored as an associative array where each key is the
 * parameter name and each value is an array of parameter values.
 */
class VCardProperty
{
	var $name;          // string
	var $params=array();// params[PARAM_NAME] => value[,value...]
	var $value;         // string
	var $group='';      // group prefix

	/**
	 * Parses a vCard property from one or more lines. Lines that are not
	 * property lines, such as blank lines, are skipped. Returns false if
	 * there are no more lines to be parsed.
	 */
	function parse(&$lines)
	{
		while (list(, $line) = each($lines)) {
			$line = rtrim($line);
			$tmp = split_quoted_string(":", $line, 2);
			if (count($tmp) == 2) {
				$this->value=preg_replace('/\\\\r\\\\n|\\\\n|\\\\r/',"\n",$tmp[1]);
				// parameter values can be case sensitive
				$tmp = split_quoted_string(";", $tmp[0]);
				$this->name = $tmp[0];
				for ($i = 1; $i < count($tmp); $i++) {
					$this->_parseParam($tmp[$i]);
				}
				$tmp = split_quoted_string(".", $this->name, 2);
				if (count($tmp) == 2) {
					# XXX (RCMCardDAV) store group prefix
					# XXX see http://tools.ietf.org/html/draft-ietf-vcarddav-carddav-10#section-10.4.2
					$this->group = $tmp[0];
					$this->name = strtoupper($tmp[1]);
				} else {
					$this->name = strtoupper($this->name);
				}
				if (array_key_exists('ENCODING', $this->params)
					&& $this->params['ENCODING'][0] == 'QUOTED-PRINTABLE') {
					$this->_decodeQuotedPrintable($lines);
				}
				if (array_key_exists('CHARSET', $this->params)
					&& $this->params['CHARSET'][0] == 'UTF-8') {
					$this->value = utf8_decode($this->value);
				}
				return true;
			}
		}
		return false;
	}

	function toString()
	{
		$line = $this->name;
		if($this->group) $line = $this->group . ".$line";
		foreach($this->params as $pname => $values) {
			foreach($values as $value) {
				$line .= ";$pname=". dquote($value);
			}
		}
		$this->value=preg_replace("/\r\n|\r|\n/","\\n",$this->value);
		$line .= ':' . $this->value;

		// fold lines to 75 characters length
		$flines = str_split($line, 75);
		$line = implode("\r\n ", $flines);
		return ($line . "\r\n");
	}

	/**
	 * Splits the value on unescaped delimiter characters.
	 */
	function getComponents($delim = ";")
	{
		$value = $this->value;
		// Save escaped delimiters.
		$value = str_replace("\\$delim", "\x00", $value);
		// Tag unescaped delimiters.
		$value = str_replace("$delim", "\x01", $value);
		// Restore the escaped delimiters.
		$value = str_replace("\x00", "$delim", $value);
		// Split the line on the delimiter tag.
		return explode("\x01", $value);
	}

	function getValue() {
		return $this->value;
	}

	function deleteParam($name, $from=0, $numelem=0)
	{
		$name = strtoupper($name);
		if(array_key_exists($name, $this->params)) {
			if($numelem == 0)
				$numelem = count($this->params[$name]) - $from;

			array_splice($this->params[$name], $from, $numelem);
			if(count($this->params[$name]) == 0)
				unset($this->params[$name]);
		}
	}

	function getParam($name, $which=-1)
	{
		$name = strtoupper($name);
		if(!array_key_exists($name, $this->params))
			return null;
		$p = $this->params[$name];

		if($which < 0) // return array of all
			return $p;

		if($which < count($p))
			return $p[$which];

		// no one at requested index present
		return null;
	}

	// $which=-1 means append, -2 prepend new param value
	function setParam($name, $value, $which=-1) {
		$name = strtoupper($name);
		if(!array_key_exists($name, $this->params))
			$this->params[$name] = array();

		$p = &$this->params[$name];
		$value = dquote($value);

		if($which == -2) {
			array_unshift($p, $value);
		} else if($which<0 || $which>=count($p)) {
			$p[] = $value;
		} else {
			$p[$which] = $value;
		}
	}

	function setComponent($newvalue, $idx=0, $delim=";")
	{
		$comps = $this->getComponents($delim);
		// create empty intermediate components if needed
		for($i=count($comps); $i < $idx; $i++) {
			$comps[] = '';
		}
		$comps[$idx] = $newvalue;

		// escape delimiters
		foreach($comps as &$comp) {
			$comp = str_replace($delim, "\\$delim", $comp);
		}
		$this->value = implode($delim, $comps);
	}

	function getGroup() {
		return $this->group;
	}

	function setGroup($group) {
		return $this->group = $group;
	}

	// ----- Private methods -----

	/**
	 * Parses a parameter string where the parameter string is either in the
	 * form "name=value[,value...]" such as "TYPE=WORK,CELL" or is a
	 * vCard 2.1 parameter value such as "WORK" in which case the parameter
	 * name is determined from the parameter value.
	 */
	function _parseParam($param)
	{
		$tmp = split_quoted_string('=', $param, 2);
		if (count($tmp) == 1) {
			$value = strtoupper($tmp[0]);
			$name = $this->_paramName($value);
			$this->params[$name][] = $value;
		} else {
			$name = strtoupper($tmp[0]);
			$values = split_quoted_string(',', $tmp[1]);
			foreach ($values as $value) {
				$this->params[$name][] = $value;
			}
		}
	}

	/**
	 * The vCard 2.1 specification allows parameter values without a name.
	 * The parameter name is then determined from the unique parameter value.
	 */
	function _paramName($value)
	{
		static $types = array (
			'DOM', 'INTL', 'POSTAL', 'PARCEL','HOME', 'WORK',
			'PREF', 'VOICE', 'FAX', 'MSG', 'CELL', 'PAGER',
			'BBS', 'MODEM', 'CAR', 'ISDN', 'VIDEO',
			'AOL', 'APPLELINK', 'ATTMAIL', 'CIS', 'EWORLD',
			'INTERNET', 'IBMMAIL', 'MCIMAIL',
			'POWERSHARE', 'PRODIGY', 'TLX', 'X400',
			'GIF', 'CGM', 'WMF', 'BMP', 'MET', 'PMB', 'DIB',
			'PICT', 'TIFF', 'PDF', 'PS', 'JPEG', 'QTIME',
			'MPEG', 'MPEG2', 'AVI',
			'WAVE', 'AIFF', 'PCM',
			'X509', 'PGP');
		static $values = array (
			'INLINE', 'URL', 'CID');
		static $encodings = array (
			'7BIT', 'QUOTED-PRINTABLE', 'BASE64');
		$name = 'UNKNOWN';
		if (in_array($value, $types)) {
			$name = 'TYPE';
		} elseif (in_array($value, $values)) {
			$name = 'VALUE';
		} elseif (in_array($value, $encodings)) {
			$name = 'ENCODING';
		}
		return $name;
	}

	/**
	 * Decodes a quoted printable value spanning multiple lines.
	 */
	function _decodeQuotedPrintable($lines)
	{
		$value = &$this->value;
		while ($value[strlen($value) - 1] == "=") {
			$value = substr($value, 0, strlen($value) - 1);
			if (!(list(, $line) = each($lines))) {
				break;
			}
			$value .= rtrim($line);
		}
		$value = quoted_printable_decode($value);
	}
}

// ----- Utility Functions -----

/**
 * Splits a string. Similar to the split function but uses a single character
 * delimiter and ignores delimiters in double quotes.
 */
function split_quoted_string($d, $s, $n = 0)
{
	$quote = false;
	$len = strlen($s);
	for ($i = 0; $i < $len && ($n == 0 || $n > 1); $i++) {
		$c = $s{$i};
		if ($c == '"') {
			$quote = !$quote;
		} else if (!$quote && $c == $d) {
			$s{$i} = "\x00";
			if ($n > 0) {
				$n--;
			}
		}
	}
	return explode("\x00", $s);
}

/**
 * Places a string in double quotes "$s" if the string contains any of the
 * characters [,:;]. Occurances of " within $s are removed.
 */
function dquote($s) {
	$s = str_replace('"', '', $s);
	if(preg_match('/[,:;]/', $s)) {
		$s = '"' . $s . '"';
	}
	return $s;
}
?>
