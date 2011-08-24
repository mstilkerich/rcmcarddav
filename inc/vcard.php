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

    /**
     * Parses a vCard from one or more lines. Lines that are not property
     * lines, such as blank lines, are skipped. Returns false if there are
     * no more lines to be parsed.
     */
    function parse(&$lines)
    {
        $this->_map = null;
        $property = new VCardProperty();
        while ($property->parse($lines)) {
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

    /**
     * Returns the first property mapped to the specified name or null if
     * there are no properties with that name.
     */
    function getProperty($name)
    {
        return $this->_map[$name][0];
    }

    /**
     * Returns the properties mapped to the specified name or null if
     * there are no properties with that name.
     */
    function getProperties($name)
    {
        return $this->_map[$name];
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
    var $params;        // params[PARAM_NAME] => value[,value...]
    var $value;         // string

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
                $this->value = $tmp[1];
                $tmp = strtoupper($tmp[0]);
                $tmp = split_quoted_string(";", $tmp);
                $this->name = $tmp[0];
                $this->params = array();
                for ($i = 1; $i < count($tmp); $i++) {
                    $this->_parseParam($tmp[$i]);
                }
                if ($this->params['ENCODING'][0] == 'QUOTED-PRINTABLE') {
                    $this->_decodeQuotedPrintable($lines);
                }
                if ($this->params['CHARSET'][0] == 'UTF-8') {
                    $this->value = utf8_decode($this->value);
                }
                return true;
            }
        }
        return false;
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
            $value = $tmp[0]; 
            $name = $this->_paramName($value);
            $this->params[$name][] = $value;
        } else {
            $name = $tmp[0];
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
    function _decodeQuotedPrintable(&$lines)
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

?>
