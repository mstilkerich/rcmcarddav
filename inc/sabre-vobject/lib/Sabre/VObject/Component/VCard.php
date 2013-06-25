<?php

namespace Sabre\VObject\Component;

use Sabre\VObject;

/**
 * The VCard component
 *
 * This component represents the BEGIN:VCARD and END:VCARD found in every
 * vcard.
 *
 * @copyright Copyright (C) 2007-2013 fruux GmbH (https://fruux.com/).
 * @author Evert Pot (http://evertpot.com/)
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
class VCard extends VObject\Document {

    /**
     * The default name for this component.
     *
     * This should be 'VCALENDAR' or 'VCARD'.
     *
     * @var string
     */
    static $defaultName = 'VCARD';

    /**
     * Caching the version number
     *
     * @var int
     */
    private $version = null;

    /**
     * List of value-types, and which classes they map to.
     *
     * @var array
     */
    static public $valueMap = array(
        'BINARY'           => 'Sabre\\VObject\\Property\\Binary',
        'BOOLEAN'          => 'Sabre\\VObject\\Property\\Boolean',
        'CONTENT-ID'       => 'Sabre\\VObject\\Property\\FlatText',   // vCard 2.1 only
        'DATE'             => 'Sabre\\VObject\\Property\\VCard\\Date',
        'DATE-TIME'        => 'Sabre\\VObject\\Property\\VCard\\DateTime',
        'DATE-AND-OR-TIME' => 'Sabre\\VObject\\Property\\VCard\\DateAndOrTime', // vCard only
        'FLOAT'            => 'Sabre\\VObject\\Property\\Float',
        'INTEGER'          => 'Sabre\\VObject\\Property\\Integer',
        'LANGUAGE-TAG'     => 'Sabre\\VObject\\Property\\VCard\\LanguageTag',
        'TIMESTAMP'        => 'Sabre\\VObject\\Property\\VCard\\TimeStamp',
        'TEXT'             => 'Sabre\\VObject\\Property\\Text',
        'TIME'             => 'Sabre\\VObject\\Property\\Time',
        'UNKNOWN'          => 'Sabre\\VObject\\Property\\Unknown', // jCard / jCal-only.
        'URI'              => 'Sabre\\VObject\\Property\\Uri',
        'URL'              => 'Sabre\\VObject\\Property\\Uri', // vCard 2.1 only
        'UTC-OFFSET'       => 'Sabre\\VObject\\Property\\UtcOffset',
    );

    /**
     * List of properties, and which classes they map to.
     *
     * @var array
     */
    static public $propertyMap = array(

        // vCard 2.1 properties and up
        'N'       => 'Sabre\\VObject\\Property\\Text',
        'FN'      => 'Sabre\\VObject\\Property\\FlatText',
        'PHOTO'   => 'Sabre\\VObject\\Property\\Binary', // Todo: we should add a class for Binary values.
        'BDAY'    => 'Sabre\\VObject\\Property\\VCard\\DateAndOrTime',
        'ADR'     => 'Sabre\\VObject\\Property\\Text',
        'LABEL'   => 'Sabre\\VObject\\Property\\FlatText', // Removed in vCard 4.0
        'TEL'     => 'Sabre\\VObject\\Property\\FlatText',
        'EMAIL'   => 'Sabre\\VObject\\Property\\FlatText',
        'MAILER'  => 'Sabre\\VObject\\Property\\FlatText', // Removed in vCard 4.0
        'GEO'     => 'Sabre\\VObject\\Property\\FlatText',
        'TITLE'   => 'Sabre\\VObject\\Property\\FlatText',
        'ROLE'    => 'Sabre\\VObject\\Property\\FlatText',
        'LOGO'    => 'Sabre\\VObject\\Property\\Binary',
        // 'AGENT'   => 'Sabre\\VObject\\Property\\',      // Todo: is an embedded vCard. Probably rare, so
                                 // not supported at the moment
        'ORG'     => 'Sabre\\VObject\\Property\\Text',
        'NOTE'    => 'Sabre\\VObject\\Property\\FlatText',
        'REV'     => 'Sabre\\VObject\\Property\\VCard\\TimeStamp',
        'SOUND'   => 'Sabre\\VObject\\Property\\FlatText',
        'URL'     => 'Sabre\\VObject\\Property\\Uri',
        'UID'     => 'Sabre\\VObject\\Property\\FlatText',
        'VERSION' => 'Sabre\\VObject\\Property\\FlatText',
        'KEY'     => 'Sabre\\VObject\\Property\\FlatText',

        // vCard 3.0 properties
        'CATEGORIES'  => 'Sabre\\VObject\\Property\\Text',
        'SORT-STRING' => 'Sabre\\VObject\\Property\\FlatText',
        'PRODID'      => 'Sabre\\VObject\\Property\\FlatText',
        'NICKNAME'    => 'Sabre\\VObject\\Property\\Text',
        'CLASS'       => 'Sabre\\VObject\\Property\\FlatText', // Removed in vCard 4.0

        // rfc2739 properties
        'FBURL'        => 'Sabre\\VObject\\Property\\Uri',
        'CAPURI'       => 'Sabre\\VObject\\Property\\Uri',
        'CALURI'       => 'Sabre\\VObject\\Property\\Uri',

        // rfc4770 properties
        'IMPP'         => 'Sabre\\VObject\\Property\\Uri',

        // vCard 4.0 properties
        'XML'          => 'Sabre\\VObject\\Property\\FlatText',
        'ANNIVERSARY'  => 'Sabre\\VObject\\Property\\VCard\\DateAndOrTime',
        'CLIENTPIDMAP' => 'Sabre\\VObject\\Property\\Text',
        'LANG'         => 'Sabre\\VObject\\Property\\VCard\\LanguageTag',
        'GENDER'       => 'Sabre\\VObject\\Property\\Text',
        'KIND'         => 'Sabre\\VObject\\Property\\FlatText',

    );

    /**
     * Returns the current document type.
     *
     * @return void
     */
    public function getDocumentType() {

        if (!$this->version) {
            $version = (string)$this->VERSION;
            switch($version) {
                case '2.1' :
                    $this->version = self::VCARD21;
                    break;
                case '3.0' :
                    $this->version = self::VCARD30;
                    break;
                case '4.0' :
                    $this->version = self::VCARD40;
                    break;
                default :
                    $this->version = self::UNKNOWN;
                    break;

            }
        }

        return $this->version;

    }

    /**
     * VCards with version 2.1, 3.0 and 4.0 are found.
     *
     * If the VCARD doesn't know its version, 4.0 is assumed.
     */
    const DEFAULT_VERSION = self::VCARD21;

    /**
     * Validates the node for correctness.
     *
     * The following options are supported:
     *   - Node::REPAIR - If something is broken, and automatic repair may
     *                    be attempted.
     *
     * An array is returned with warnings.
     *
     * Every item in the array has the following properties:
     *    * level - (number between 1 and 3 with severity information)
     *    * message - (human readable message)
     *    * node - (reference to the offending node)
     *
     * @param int $options
     * @return array
     */
    public function validate($options = 0) {

        $warnings = array();

        $versionMap = array(
            self::VCARD21 => '2.1',
            self::VCARD30 => '3.0',
            self::VCARD40 => '4.0',
        );

        $version = $this->select('VERSION');
        if (count($version)!==1) {
            $warnings[] = array(
                'level' => 1,
                'message' => 'The VERSION property must appear in the VCARD component exactly 1 time',
                'node' => $this,
            );
            if ($options & self::REPAIR) {
                $this->VERSION = $versionMap[self::DEFAULT_VERSION];
            }
        } else {
            $version = (string)$this->VERSION;
            if ($version!=='2.1' && $version!=='3.0' && $version!=='4.0') {
                $warnings[] = array(
                    'level' => 1,
                    'message' => 'Only vcard version 4.0 (RFC6350), version 3.0 (RFC2426) or version 2.1 (icm-vcard-2.1) are supported.',
                    'node' => $this,
                );
                if ($options & self::REPAIR) {
                    $this->VERSION = $versionMap[self::DEFAULT_VERSION];
                }
            }

        }
        $fn = $this->select('FN');
        if (count($fn)!==1) {
            $warnings[] = array(
                'level' => 1,
                'message' => 'The FN property must appear in the VCARD component exactly 1 time',
                'node' => $this,
            );
            if (($options & self::REPAIR) && count($fn) === 0) {
                // We're going to try to see if we can use the contents of the
                // N property.
                if (isset($this->N)) {
                    $value = explode(';', (string)$this->N);
                    if (isset($value[1]) && $value[1]) {
                        $this->FN = $value[1] . ' ' . $value[0];
                    } else {
                        $this->FN = $value[0];
                    }

                // Otherwise, the ORG property may work
                } elseif (isset($this->ORG)) {
                    $this->FN = (string)$this->ORG;
                }

            }
        }

        return array_merge(
            parent::validate($options),
            $warnings
        );

    }

    /**
     * This method should return a list of default property values.
     *
     * @return array
     */
    protected function getDefaults() {

        return array(
            'VERSION' => '3.0',
            'PRODID' => '-//Sabre//Sabre VObject ' . VObject\Version::VERSION . '//EN',
        );

    }

    /**
     * This method returns an array, with the representation as it should be
     * encoded in json. This is used to create jCard or jCal documents.
     *
     * @return array
     */
    public function jsonSerialize() {

        // A vcard does not have sub-components, so we're overriding this
        // method to remove that array element.
        $properties = array();

        foreach($this->children as $child) {
            $properties[] = $child->jsonSerialize();
        }

        return array(
            strtolower($this->name),
            $properties,
        );

    }

}

