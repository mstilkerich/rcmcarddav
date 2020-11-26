<?php

namespace MStilkerich\CardDavAddressbook4Roundcube;

use Psr\Log\LoggerInterface;
use Sabre\VObject;
use Sabre\VObject\Component\VCard;
use MStilkerich\CardDavClient\AddressbookCollection;
use carddav;
use rcube_utils;

class DataConversion
{
    /** @var array VCF2RC maps VCard property names to roundcube keys */
    private const VCF2RC = [
        'simple' => [
            'BDAY' => 'birthday',
            'FN' => 'name',
            'NICKNAME' => 'nickname',
            'NOTE' => 'notes',
            'PHOTO' => 'photo',
            'TITLE' => 'jobtitle',
            'UID' => 'cuid',
            'X-ABShowAs' => 'showas',
            'X-ANNIVERSARY' => 'anniversary',
            'X-ASSISTANT' => 'assistant',
            'X-GENDER' => 'gender',
            'X-MANAGER' => 'manager',
            'X-SPOUSE' => 'spouse',
            'X-MAIDENNAME' => 'maidenname',
            // the two kind attributes should not occur both in the same vcard
            //'KIND' => 'kind',   // VCard v4
            'X-ADDRESSBOOKSERVER-KIND' => 'kind', // Apple Addressbook extension
        ],
        'multi' => [
            'EMAIL' => 'email',
            'TEL' => 'phone',
            'URL' => 'website',
            'ADR' => 'address',
            'IMPP' => 'im',
            'X-AIM' => 'im',
            'X-GADUGADU' => 'im',
            'X-GOOGLE-TALK' => 'im',
            'X-GROUPWISE' => 'im',
            'X-ICQ' => 'im',
            'X-JABBER' => 'im',
            'X-MSN' => 'im',
            'X-SKYPE' => 'im',
            'X-TWITTER' => 'im',
            'X-YAHOO' => 'im',
        ],
    ];

    /** @var string[] IM_XPROPS Maps IMPP uri scheme to X- property for IM services for that an X- property exists */
    private const IM_XPROPS = [
        'aim' => 'X-AIM',
        'gadugadu' => 'X-GADUGADU',
        'googletalk' => 'X-GOOGLE-TALK',
        'icq' => 'X-ICQ',
        'jabber' => 'X-JABBER',
        'msn' => 'X-MSN',
        'skype' => 'X-SKYPE',
        'twitter' => 'X-TWITTER',
        'yahoo' => 'X-YAHOO',
    ];

    /** @var array $coltypes Descriptions on the different attributes of address objects for roundcube */
    private $coltypes = [
        'name' => [],
        'firstname' => [],
        'surname' => [],
        'maidenname' => [],
        'email' => [
            'subtypes' => ['home','work','other','internet'],
        ],
        'middlename' => [],
        'prefix' => [],
        'suffix' => [],
        'nickname' => [],
        'jobtitle' => [],
        'organization' => [],
        'department' => [],
        'gender' => [],
        'phone' => [
            'subtypes' => [
                'home','work','home2','work2','mobile','main','homefax','workfax','car','pager','video',
                'assistant','other'
            ],
        ],
        'address' => [
            'subtypes' => ['home','work','other'],
        ],
        'birthday' => [],
        'anniversary' => [],
        'website' => [
            'subtypes' => ['homepage','work','blog','profile','other'],
        ],
        'notes' => [],
        'photo' => [],
        'assistant' => [],
        'manager' => [],
        'spouse' => [],
        'im' => [
            'subtypes' => [
                'AIM',
                'GaduGadu',
                'GoogleTalk',
                'Groupwise',
                'ICQ',
                'IRC',
                'Jabber',
                'Kakaotalk',
                'Kik',
                'Line',
                'Matrix',
                'MSN',
                'QQ',
                'SIP',
                'Skype',
                'Telegram',
                'Twitter',
                'XMPP',
                'WeChat',
                'Yahoo',
                'Zoom',
                'other'
            ],
            'subtypealias' => [
                'gg' => 'gadugadu',
            ]
        ],
    ];

    /** @var array $xlabels custom labels defined in the addressbook */
    private $xlabels = [];

    /** @var string $abookId Database ID of the Addressbook this converter is associated with */
    private $abookId;

    /** @var LoggerInterface $logger */
    private $logger;

    /** @var Database The database object to use for DB access */
    private $db;

    /** @var \rcube_cache $cache */
    private $cache;

    /**
     * Constructs a data conversion instance.
     *
     * The instance is bound to an Addressbook because some properties of the conversion such as specific labels are
     * specific for an addressbook.
     *
     * The data converter may need access to the database and the carddav server for specific operations such as storing
     * the custom labels or downloading resources from the server that are referenced by an URI within a VCard. These
     * dependencies are injected with the constructor to allow for testing of this class using stub versions.
     *
     * @param string $abookId The database ID of the addressbook the data conversion object is bound to.
     * @param Database $db The database object.
     * @param \rcube_cache $cache The roundcube cache object.
     * @param LoggerInterface $logger The logger object.
     */
    public function __construct(string $abookId, Database $db, \rcube_cache $cache, LoggerInterface $logger)
    {
        $this->abookId = $abookId;
        $this->db = $db;
        $this->cache = $cache;
        $this->logger = $logger;

        $this->addextrasubtypes();
    }

    public function getColtypes(): array
    {
        return $this->coltypes;
    }

    /**
     * Creates the roundcube representation of a contact from a VCard.
     *
     * If the card contains a URI referencing an external photo, this
     * function will download the photo and inline it into the VCard.
     * The returned array contains a boolean that indicates that the
     * VCard was modified and should be stored to avoid repeated
     * redownloads of the photo in the future. The returned VCard
     * object contains the modified representation and can be used
     * for storage.
     *
     * @param  VCard $vcard Sabre VCard object
     *
     * @return array Roundcube representation of the VCard
     */
    public function toRoundcube(VCard $vcard, AddressbookCollection $davAbook): array
    {
        $save_data = [
            // DEFAULTS
            'kind'   => 'individual',
        ];

        foreach (self::VCF2RC['simple'] as $vkey => $rckey) {
            $property = $vcard->{$vkey};
            if (!empty($property)) {
                $save_data[$rckey] = (string) $property;
            }
        }

        // Set a proxy for photo computation / retrieval on demand
        if (key_exists('photo', $save_data) && isset($vcard->PHOTO)) {
            $save_data["photo"] = new DelayedPhotoLoader($vcard, $davAbook, $this->cache, $this->logger);
        }

        $property = $vcard->N;
        if (isset($property)) {
            $attrs = [ "surname", "firstname", "middlename", "prefix", "suffix" ];
            $N = $property->getParts();
            for ($i = 0; $i <= count($N); $i++) {
                if (!empty($N[$i])) {
                    $save_data[$attrs[$i]] = $N[$i];
                }
            }
        }

        $property = $vcard->ORG;
        if (isset($property)) {
            $ORG = $property->getParts();
            $organization = $ORG[0];
            if (!empty($organization)) {
                $save_data['organization'] = $organization;
            }
            $department = implode("; ", array_slice($ORG, 1));
            if (!empty($department)) {
                $save_data['department'] = $department;
            }
        }

        foreach (self::VCF2RC['multi'] as $vkey => $rckey) {
            $properties = $vcard->{$vkey};
            if (isset($properties)) {
                foreach ($properties as $prop) {
                    $label = $this->getAttrLabel($vcard, $prop, $rckey);

                    if (method_exists($this, "toRoundcube$vkey")) {
                        // special handler for structured property
                        $propValue = call_user_func([$this, "toRoundcube$vkey"], $prop);
                    } else {
                        $propValue = (string) $prop;
                    }

                    /** @var string[] */
                    $existingValues = $save_data["$rckey:$label"] ?? [];
                    if (!empty($propValue) && !in_array($propValue, $existingValues)) {
                        $save_data["$rckey:$label"][] = $propValue;
                    }
                }
            }
        }

        // set displayname if not set from VCard
        if (empty($save_data["name"])) {
            $save_data["name"] = self::composeDisplayname($save_data);
        }

        return $save_data;
    }


    /**
     * Creates roundcube address data from an ADR VCard property.
     *
     * @param VObject\Property The ADR property to use as input.
     * @return string[] The roundcube address data created from the property.
     */
    private function toRoundcubeADR(VObject\Property $prop): array
    {
        $attrs = [
            'pobox',    // post office box
            'extended', // extended address
            'street',   // street address
            'locality', // locality (e.g., city)
            'region',   // region (e.g., state or province)
            'zipcode',  // postal code
            'country'   // country name
        ];
        $p = $prop->getParts();
        $addr = [];
        for ($i = 0; $i < count($p); $i++) {
            if (!empty($p[$i])) {
                $addr[$attrs[$i]] = $p[$i];
            }
        }
        return $addr;
    }

    /**
     * Creates roundcube instant messaging data
     *
     * @param VObject\Property The IMPP property to use as input.
     * @return string The roundcube data created from the property.
     */
    private function toRoundcubeIMPP(VObject\Property $prop): string
    {
        // Examples:
        // From iCloud Web Addressbook: IMPP;X-SERVICE-TYPE=aim;TYPE=HOME;TYPE=pref:aim:jdoe@example.com
        // From Nextcloud: IMPP;TYPE=SKYPE:jdoe@example.com
        // Note: the nextcloud example does not have an URI value, thus it's not compliant with RFC 4770
        $comp = explode(":", (string) $prop, 2);
        return count($comp) == 2 ? $comp[1] : $comp[0];
    }

    /**
     * Creates a new or updates an existing vcard from save data.
     *
     * @param array $save_data The roundcube representation of the contact / group
     * @param ?VCard $vcard The original VCard from that the address data was originally passed to roundcube. If a new
     *                      VCard should be created, this parameter must be null.
     * @return VCard Returns the created / updated VCard. If a VCard was passed in the $vcard parameter, it is updated
     *               in place.
     */
    public function fromRoundcube(array $save_data, ?VCard $vcard = null): VCard
    {
        unset($save_data['vcard']);

        $isGroup = (($save_data['kind'] ?? "") === "group");

        if (empty($save_data["name"])) {
            if (!$isGroup) {
                $save_data["showas"] = $this->determineShowAs($save_data);
            }
            $save_data["name"] = $this->composeDisplayname($save_data);
        }

        if (!isset($vcard)) {
            // create fresh minimal vcard
            $vcard = new VObject\Component\VCard(['VERSION' => '3.0']);
        }

        // update revision
        $vcard->REV = $this->dateTimeString();

        // N is mandatory
        if ($isGroup) {
            $vcard->N = [$save_data['name'],"","","",""];
        } else {
            $vcard->N = [
                $save_data['surname'] ?? "",
                $save_data['firstname'] ?? "",
                $save_data['middlename'] ?? "",
                $save_data['prefix'] ?? "",
                $save_data['suffix'] ?? ""
            ];
        }

        $this->setOrgProperty($save_data, $vcard);
        $this->setSingleValueProperties($save_data, $vcard);
        $this->setMultiValueProperties($save_data, $vcard);

        return $vcard;
    }

    /**
     * Returns an RFC2425 date-time string for the current time in UTC.
     *
     * Example: 2020-11-12T16:18:41Z
     *
     * T is used as a delimiter to separate date and time.
     * Z is the zone designator for the zero UTC offset.
     * See also ISO 8601.
     */
    private function dateTimeString(): string
    {
        return gmdate("Y-m-d\TH:i:s\Z");
    }

    /**
     * Sets the ORG property in a VCard from roundcube contact data.
     *
     * The ORG property is populated from the organization and department attributes of roundcube's data.
     * The department is split into several components separated by semicolon and stored as different parts of the ORG
     * property.
     *
     * If neither organization nor department are given (or empty), the ORG property is deleted from the VCard.
     *
     * @param array $save_data The roundcube representation of the contact
     * @param VCard $vcard The VCard to set the ORG property for.
     */
    private function setOrgProperty(array $save_data, VCard $vcard): void
    {
        $orgParts = [];
        if (!empty($save_data['organization'])) {
            $orgParts[] = $save_data['organization'];
        }

        if (!empty($save_data['department'])) {
            // the first element of ORG corresponds to organization, if that field is not filled but organization is
            // we need to store an empty value explicitly (otherwise, department would become organization when reading
            // back the VCard).
            if (empty($orgParts)) {
                $orgParts[] = "";
            }
            $orgParts = array_merge($orgParts, preg_split('/\s*;\s*/', $save_data['department']));
        }

        if (empty($orgParts)) {
            unset($vcard->ORG);
        } else {
            $vcard->ORG = $orgParts;
        }
    }

    /**
     * Sets properties with a single value in a VCard from roundcube contact data.
     *
     * About the contents of save_data:
     *   - Empty / deleted fields in roundcube either are missing from save_data or contain an empty string as value.
     *     It is not really clear under what circumstances a field is present empty and when it's missing entirely.
     *   - Special case photo: It is only set if it was edited. If it is deleted, it is set to an empty string. If it
     *                         was not changed, no photo key is present in save_data.
     *
     * @param array $save_data The roundcube representation of the contact
     * @param VCard $vcard The VCard to set the ORG property for.
     */
    private function setSingleValueProperties(array $save_data, VCard $vcard): void
    {
        foreach (self::VCF2RC['simple'] as $vkey => $rckey) {
            if (empty($save_data[$rckey])) {
                // not set or empty value -> delete EXCEPT PHOTO, which is only deleted if value is set to empty string
                if ($rckey !== "photo" || isset($save_data["photo"])) {
                    unset($vcard->{$vkey});
                } // else keep a possibly existing old property
            } else {
                $vcard->{$vkey} = $save_data[$rckey];

                // Special handling for PHOTO
                // If PHOTO is set from roundcube data, set the parameters properly
                if ($rckey === "photo" && isset($vcard->PHOTO)) {
                    $vcard->PHOTO['ENCODING'] = 'b';
                    $vcard->PHOTO['VALUE'] = 'binary';
                }
            }
        }
    }

    /**
     * Sets properties with possibly multiple values in a VCard from roundcube contact data.
     *
     * The current approach is to completely erase existing properties from the VCard and to create from roundcube data
     * from scratch. The implication of this is that only subtype (the one selected in roundcube) can be preserved, if a
     * property had multiple subtypes, the other ones will be lost.
     *
     * About the contents of save_data:
     *   - Multi-value fields (email, address, phone, website) have a key that includes the subtype setting delimited by
     *     a colon (e.g. "email:home"). The value of each setting is an array. These arrays may include empty members if
     *     the field was part of the edit mask but not filled.
     *
     * @param array $save_data The roundcube representation of the contact
     * @param VCard $vcard The VCard to set the ORG property for.
     */
    private function setMultiValueProperties(array $save_data, VCard $vcard): void
    {
        // delete and fully recreate all entries; there is no easy way of mapping an address in the existing card to an
        // address in the save data, as subtypes may have changed
        foreach (array_keys(self::VCF2RC['multi']) as $vkey) {
            unset($vcard->{$vkey});
        }

        // now clear out all orphan X-ABLabel properties
        $this->clearOrphanAttrLabels($vcard);

        // and finally recreate the attributes
        foreach (self::VCF2RC['multi'] as $vkey => $rckey) {
            /** @var array preg_filter always returns an array given an array subject to filter */
            $subtypes = preg_filter("/^$rckey:/", '', array_keys($save_data), 1);
            foreach ($subtypes as $subtype) {
                foreach ($save_data["$rckey:$subtype"] as $value) {
                    $prop = null;

                    if (method_exists($this, "fromRoundcube$vkey")) {
                        // special handler for structured property
                        $prop = call_user_func([$this, "fromRoundcube$vkey"], $value, $vcard);
                    } else {
                        if (!empty($value)) {
                            $prop = $vcard->createProperty($vkey, $value);
                            $vcard->add($prop);
                        }
                    }

                    if (isset($prop)) {
                        $this->setAttrLabel($vcard, $prop, $rckey, $subtype);
                    }
                }
            }
        }
    }

    /**
     * Creates an ADR property from roundcube address data and adds it to a VCard.
     *
     * This function is passed an address array as provided by roundcube and from it creates a property if at least one
     * of the address fields is set to a non empty value. Otherwise, null is returned.
     *
     * @param array $address The address array as provided by roundcube
     * @param VCard $vcard The VCard to add the property to.
     * @return ?VObject\Property The created property, null if no property was created.
     */
    private function fromRoundcubeADR(array $address, VCard $vcard): ?VObject\Property
    {
        $prop = null;

        if (
            !empty($address['street'])
            || !empty($address['locality'])
            || !empty($address['region'])
            || !empty($address['zipcode'])
            || !empty($address['country'])
        ) {
            $prop = $vcard->createProperty('ADR', [
                '', // post office box
                '', // extended address
                $address['street'] ?? "",
                $address['locality'] ?? "",
                $address['region'] ?? "",
                $address['zipcode'] ?? "",
                $address['country'] ?? "",
            ]);
            $vcard->add($prop);
        }

        return $prop;
    }

    /******************************************************************************************************************
     ************                                   +         +         +                                  ************
     ************                                    X-ABLabel Extension                                   ************
     ************                                   +         +         +                                  ************
     *****************************************************************************************************************/

    /**
     * Returns all the property groups used in a VCard.
     *
     * For example, [ "ITEM1", "ITEM2" ] would be returned if the vcard contained the following:
     * ITEM1.X-ABLABEL: FOO
     * ITEM2.X-ABLABEL: BAR
     *
     * @return string[] The list of used groups, in upper case.
     */
    private function getAllPropertyGroups(VCard $vcard): array
    {
        $groups = [];

        foreach ($vcard->children() as $p) {
            if (isset($p->group)) {
                $groups[strtoupper($p->group)] = true;
            }
        }

        return array_keys($groups);
    }

    /**
     * This function clears all orphan X-ABLabel properties from a VCard.
     *
     * An X-ABLabel is considered orphan if its property group is not used by any other properties.
     *
     * The special case that X-ABLabel property exists that is not part of any group is not considered an orphan, and it
     * should not occur because X-ABLabel only makes sense when assigned to another property via the shared group.
     */
    private function clearOrphanAttrLabels(VCard $vcard): void
    {
        // groups used by Properties OTHER than X-ABLabel
        $usedGroups = [];
        $labelProps = [];

        foreach ($vcard->children() as $p) {
            if (isset($p->group)) {
                if (strcasecmp($p->name, "X-ABLabel") === 0) {
                    $labelProps[] = $p;
                } else {
                    $usedGroups[strtoupper($p->group)] = true;
                }
            }
        }

        foreach ($labelProps as $p) {
            if (!isset($usedGroups[strtoupper($p->group)])) {
                $vcard->remove($p);
            }
        }
    }

    /**
     * This function assigned a label (subtype) to a VCard multi-value property.
     *
     * Typical multi-value properties are EMAIL, TEL and ADR.
     *
     * Note that roundcube/rcmcarddav only supports a single subtype per property, whereas VCard allows to have more
     * than one. As an effect, when a card is updated only the subtype selected in roundcube will be preserved, possible
     * extra subtypes will be lost.
     *
     * If the given label is one of the known standard labels, it will be assigned as a TYPE parameter of the property,
     * otherwise it will be assigned using the X-ABLabel extension.
     *
     * Note: vcard groups are case-insensitive per RFC6350.
     *
     * @param VCard $vcard The VCard that the property belongs to
     * @param VObject\Property $vprop The property to set the subtype for. A pristine property is assumed that has no
     *                                 TYPE parameter set and belong to no property group.
     * @param string $attrname The key used by roundcube for the attribute (e.g. address, email)
     * @param string $newlabel The label to assign to the given property.
     */
    private function setAttrLabel(VCard $vcard, VObject\Property $vprop, string $attrname, string $newlabel): void
    {
        // X-ABLabel?
        if (in_array($newlabel, $this->xlabels[$attrname])) {
            $usedGroups = $this->getAllPropertyGroups($vcard);
            $item = 0;

            do {
                ++$item;
                $group = "ITEM$item";
            } while (in_array(strtoupper($group), $usedGroups));
            $vprop->group = $group;

            $labelProp = $vcard->createProperty("$group.X-ABLabel", $newlabel);
            $vcard->add($labelProp);
        } else {
            // Standard Label
            $vprop['TYPE'] = $newlabel;
        }
    }

    /**
     * Provides the label (subtype) of a multi-value property.
     *
     * VCard allows a property to have several TYPE parameters. In addition, it is possible to specify user-defined
     * types using the X-ABLabel extension. However, in roundcube we can only show one label / subtype, so we need a way
     * to select which of the available labels to show.
     *
     * The following algorithm is used to select the label (first match is used):
     *  1. If there is a custom handler to extract a label for a property, it is called to provide the label.
     *  2. If the property is part of a group that also contains an X-ABLabel property, the X-ABLabel value is used.
     *  3. The TYPE parameter that, of all the specified TYPE parameters, is listed first in the
     *     coltypes[<attr>]["subtypes"] array. Note that TYPE parameter values not listed in the subtypes array will be
     *     ignored in the selection.
     *  4. If no known TYPE parameter value is specified, "other" is used, which is a valid subtype for all currently
     *     supported multi-value properties.
     */
    private function getAttrLabel(VCard $vcard, VObject\Property $vprop, string $attrname): string
    {
        // 1. Check if there is a property-specific handler to provide label
        $vkey = str_replace("-", "_", strtoupper($vprop->name));
        if (method_exists($this, "getAttrLabel$vkey")) {
            return call_user_func([$this, "getAttrLabel$vkey"], $vcard, $vprop);
        }

        // 2. check for a custom label using Apple's X-ABLabel extension
        $group = $vprop->group;
        if ($group) {
            $xlabel = $vcard->{"$group.X-ABLabel"};
            if (!empty($xlabel)) {
                // special labels from Apple namespace are stored in the form "_$!<Label>!$_" - extract label
                $xlabel = preg_replace(';_\$!<(.*)>!\$_;', '$1', $xlabel);

                // add to known types if new
                if (!in_array($xlabel, $this->coltypes[$attrname]['subtypes'])) {
                    $this->storeextrasubtype($attrname, $xlabel);
                }
                return $xlabel;
            }
        }


        // 3. select a known standard label if available
        $selection = null;
        if (isset($vprop["TYPE"]) && is_array($this->coltypes[$attrname]['subtypes'])) {
            foreach ($vprop["TYPE"] as $type) {
                $type = strtolower($type);
                $pref = array_search($type, $this->coltypes[$attrname]['subtypes'], true);

                if ($pref !== false) {
                    if (!isset($selection) || $pref < $selection[1]) {
                        $selection = [ $type, $pref ];
                    }
                }
            }
        }

        // 4. return default subtype
        return $selection[0] ?? 'other';
    }

    /**
     * Acquires label candidates for the IMPP property.
     *
     * Candidates are taken from the URI component and X-SERVICE-TYPE parameters of the property.
     *
     * @return string The determined label.
     */
    private function getAttrLabelIMPP(VCard $_vcard, VObject\Property $prop): string
    {
        $subtypesLower = array_map('strtolower', $this->coltypes["im"]['subtypes']);

        // check X-SERVICE-TYPE parameter (seen in entries created by Apple Addressbook)
        if (isset($prop["X-SERVICE-TYPE"])) {
            foreach ($prop["X-SERVICE-TYPE"] as $type) {
                $ltype = strtolower($type);
                $pos = array_search($ltype, $subtypesLower, true);

                if ($pos === false) {
                    // custom type
                    $this->storeextrasubtype('im', $type);
                    return $type;
                } else {
                    return $this->coltypes["im"]['subtypes'][$pos];
                }
            }
        }

        // check URI scheme
        $comp = explode(":", strtolower((string) $prop), 2);
        if (count($comp) == 2) {
            $ltype = $comp[0];
            $ltype = $this->coltypes["im"]["subtypealias"][$ltype] ?? $ltype;
            $pos = array_search($ltype, $subtypesLower, true);
            if ($pos !== false) {
                return $this->coltypes["im"]['subtypes'][$pos];
            }
        }

        // check TYPE parameter
        if (isset($prop["TYPE"])) {
            foreach ($prop["TYPE"] as $type) {
                $ltype = strtolower($type);
                $pos = array_search($ltype, $subtypesLower, true);
                if ($pos !== false) {
                    return $this->coltypes["im"]['subtypes'][$pos];
                }
            }
        }

        return 'other';
    }

    // phpcs:disable PSR1.Methods.CamelCapsMethodName -- method names computed from property names
    private function getAttrLabelX_AIM(VCard $_vcard, VObject\Property $_prop): string
    {
        return 'AIM';
    }

    private function getAttrLabelX_GADUGADU(VCard $_vcard, VObject\Property $_prop): string
    {
        return 'GaduGadu';
    }

    private function getAttrLabelX_GOOGLE_TALK(VCard $_vcard, VObject\Property $_prop): string
    {
        return 'GoogleTalk';
    }

    private function getAttrLabelX_GROUPWISE(VCard $_vcard, VObject\Property $_prop): string
    {
        return 'Groupwise';
    }

    private function getAttrLabelX_ICQ(VCard $_vcard, VObject\Property $_prop): string
    {
        return 'ICQ';
    }

    private function getAttrLabelX_JABBER(VCard $_vcard, VObject\Property $_prop): string
    {
        return 'Jabber';
    }

    private function getAttrLabelX_MSN(VCard $_vcard, VObject\Property $_prop): string
    {
        return 'MSN';
    }

    private function getAttrLabelX_SKYPE(VCard $_vcard, VObject\Property $_prop): string
    {
        return 'Skype';
    }

    private function getAttrLabelX_TWITTER(VCard $_vcard, VObject\Property $_prop): string
    {
        return 'Twitter';
    }

    private function getAttrLabelX_YAHOO(VCard $_vcard, VObject\Property $_prop): string
    {
        return 'Yahoo';
    }
    // phpcs:enable PSR1.Methods.CamelCapsMethodName

    /**
     * Stores a custom label in the database (X-ABLabel extension).
     *
     * @param string Name of the type/category (phone,address,email)
     * @param string Name of the custom label to store for the type
     */
    private function storeextrasubtype(string $typename, string $subtype): void
    {
        $this->db->insert("xsubtypes", ["typename", "subtype", "abook_id"], [$typename, $subtype, $this->abookId]);
        $this->coltypes[$typename]['subtypes'][] = $subtype;
        $this->xlabels[$typename][] = $subtype;
    }

    /**
     * Adds known custom labels to the roundcube subtype list (X-ABLabel extension).
     *
     * Reads the previously seen custom labels from the database and adds them to the
     * roundcube subtype list in #coltypes and additionally stores them in the #xlabels
     * list.
     */
    private function addextrasubtypes(): void
    {
        $this->xlabels = [];

        foreach ($this->coltypes as $attr => $v) {
            if (key_exists('subtypes', $v)) {
                $this->xlabels[$attr] = [];
            }
        }

        // read extra subtypes
        $xtypes = $this->db->get($this->abookId, 'typename,subtype', 'xsubtypes', false, 'abook_id');

        foreach ($xtypes as $row) {
            [ "typename" => $attr, "subtype" => $subtype ] = $row;
            $this->coltypes[$attr]['subtypes'][] = $subtype;
            $this->xlabels[$attr][] = $subtype;
        }
    }

    /******************************************************************************************************************
     ************                                   +         +         +                                  ************
     ************                                   X-ABShowAs Extension                                   ************
     ************                                   +         +         +                                  ************
     *****************************************************************************************************************/

    /**
     * Determines the showas setting (individual vs. company) by heuristic from the entered data.
     *
     * The showas setting allows addressbooks to display a contact as an organization rather than an individual.
     *
     * If no setting of showas is available (e.g. new contact created in roundcube):
     *   - the setting will be set to COMPANY if ONLY organization is given (but no firstname / surname)
     *   - otherwise it will be set to display as INDIVIDUAL
     *
     * If an existing ShowAs=COMPANY setting is given, but the organization field is empty, the setting will be reset to
     * INDIVIDUAL.
     *
     * @param array $save_data The address data as roundcube's internal format, as entered by the user. For update of an
     *                         existing contact, the showas key must be populated with the previous value.
     * @return string INDIVIDUAL or COMPANY
     */
    private function determineShowAs(array $save_data): string
    {
        $showAs = $save_data['showas'];

        if (empty($showAs)) { // new contact
            if (empty($save_data['surname']) && empty($save_data['firstname']) && !empty($save_data['organization'])) {
                $showAs = 'COMPANY';
            } else {
                $showAs = 'INDIVIDUAL';
            }
        } else { // update of contact
            // organization not set but showas==COMPANY => show as INDIVIDUAL
            if (empty($save_data['organization'])) {
                $showAs = 'INDIVIDUAL';
            }
        }

        return $showAs;
    }

    /**
     * Determines the name to be displayed for a contact. The routine
     * distinguishes contact cards for individuals from organizations.
     *
     * From roundcube: Roundcube sets the name attribute either to an explicitly set "Display Name" field by the user,
     * or computes a name from first name and last name attributes. If roundcube cannot compose a name from the entered
     * data, the display name is empty. We set the displayname in this case only, because whenever a name attribute is
     * provided by roundcube, it is possible that it was an explicitly entered value by the user which we must not
     * overturn.
     *
     * From a VCard, the FN is mandatory. However, we may be served non-compliant VCards, or VCards with an empty FN
     * value. In those cases, we will set the display name, otherwise we will take the value provided in the VCard.
     *
     * @param array $save_data The address data as roundcube's internal format. It may either have been provided by
     *                         roundcube or be the result of a conversion of a VCard to roundcube's representation.
     * @return string The composed displayname
     */
    private static function composeDisplayname(array $save_data): string
    {
        if (strcasecmp($save_data['showas'], 'COMPANY') == 0 && !empty($save_data['organization'])) {
            return $save_data['organization'];
        }

        // try from name
        $dname = [];
        foreach (["firstname", "surname"] as $attr) {
            if (!empty($save_data[$attr])) {
                $dname[] = $save_data[$attr];
            }
        }

        if (!empty($dname)) {
            return implode(' ', $dname);
        }

        // no name? try email and phone
        $epKeys = preg_grep(";^(email|phone):;", array_keys($save_data));
        sort($epKeys, SORT_STRING);
        foreach ($epKeys as $epKey) {
            foreach ($save_data[$epKey] as $epVal) {
                if (!empty($epVal)) {
                    return $epVal;
                }
            }
        }

        // still no name? set to unknown and hope the user will fix it
        return 'Unset Displayname';
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
