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
    /**
     * @var int MAX_PHOTO_SIZE Maximum size of a photo dimension in pixels.
     *   Used when a photo is cropped for the X-ABCROP-RECTANGLE extension.
     */
    private const MAX_PHOTO_SIZE = 256;

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
            // the two kind attributes should not occur both in the same vcard
            //'KIND' => 'kind',   // VCard v4
            'X-ADDRESSBOOKSERVER-KIND' => 'kind', // Apple Addressbook extension
        ],
        'multi' => [
            'EMAIL' => 'email',
            'TEL' => 'phone',
            'URL' => 'website',
        ],
    ];

    /** @var string[] DATEFIELDS list of potential date fields for formatting */
    private const DATEFIELDS = ['birthday', 'anniversary'];

    /** @var array $coltypes Descriptions on the different attributes of address objects for roundcube
     *
     *  TODO roundcube has further default types: maidenname, im
     */
    private $coltypes = [
        'name' => [],
        'firstname' => [],
        'surname' => [],
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
    ];

    /** @var array $xlabels custom labels defined in the addressbook */
    private $xlabels = [];

    /** @var string $abookId Database ID of the Addressbook this converter is associated with */
    private $abookId;

    /** @var LoggerInterface $logger */
    private $logger;

    /** @var Database The database object to use for DB access */
    private $db;

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
     * @param LoggerInterface $logger The logger object.
     */
    public function __construct(string $abookId, Database $db, LoggerInterface $logger)
    {
        $this->abookId = $abookId;
        $this->db = $db;
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
     * @return array associative array with keys:
     *           - save_data:    Roundcube representation of the VCard
     *           - vcf:          VCard object created from the given VCard
     *           - needs_update: boolean that indicates whether the card was modified
     */
    public function toRoundcube(VCard $vcard, AddressbookCollection $davAbook): array
    {
        $needs_update = false;
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

        // inline photo if external reference
        // note: isset($vcard->PHOTO) is true if $save_data['photo'] exists, the check
        // is for the static analyzer
        if (key_exists('photo', $save_data) && isset($vcard->PHOTO)) {
            $kind = $vcard->PHOTO['VALUE'];
            if (($kind instanceof VObject\Parameter) && strcasecmp('uri', (string) $kind) == 0) {
                if ($this->downloadPhoto($save_data, $davAbook)) {
                    $props = [];
                    foreach ($vcard->PHOTO->parameters() as $property => $value) {
                        if (strcasecmp($property, 'VALUE') != 0) {
                            $props[$property] = $value;
                        }
                    }
                    $props['ENCODING'] = 'b';
                    unset($vcard->PHOTO);
                    $vcard->add('PHOTO', $save_data['photo'], $props);
                    $needs_update = true;
                }
            }
            $save_data["photo"] = self::xabcropphoto($vcard->PHOTO) ?? $save_data["photo"];
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
        if ($property) {
            $ORG = $property->getParts();
            $save_data['organization'] = $ORG[0];
            for ($i = 1; $i < count($ORG); $i++) {
                $save_data['department'][] = $ORG[$i];
            }
        }

        foreach (self::VCF2RC['multi'] as $key => $value) {
            $property = $vcard->{$key};
            if (isset($property)) {
                foreach ($property as $propertyInstance) {
                    $label = $this->getAttrLabel($vcard, $propertyInstance, $value);
                    $save_data[$value . ':' . $label][] = (string) $propertyInstance;
                }
            }
        }

        $property = ($vcard->ADR) ?: [];
        foreach ($property as $propertyInstance) {
            $label = $this->getAttrLabel($vcard, $propertyInstance, 'address');

            $attrs = [
                'pobox',    // post office box
                'extended', // extended address
                'street',   // street address
                'locality', // locality (e.g., city)
                'region',   // region (e.g., state or province)
                'zipcode',  // postal code
                'country'   // country name
            ];
            $p = $propertyInstance->getParts();
            $addr = [];
            for ($i = 0; $i < count($p); $i++) {
                if (!empty($p[$i])) {
                    $addr[$attrs[$i]] = $p[$i];
                }
            }
            $save_data['address:' . $label][] = $addr;
        }

        // set displayname according to settings
        $save_data = self::setDisplayname($save_data);

        return [
            'save_data'    => $save_data,
            'vcf'          => $vcard,
            'needs_update' => $needs_update,
        ];
    }

    /**
     * Creates a new or updates an existing vcard from save data.
     *
     * About the contents of save_data:
     *   - Empty / deleted fields in roundcube either are missing from save_data or contain an empty string as value.
     *     It is not really clear under what circumstances a field is present empty and when it's missing entirely.
     *   - Special case photo: It is only set if it was edited. If it is deleted, it is set to an empty string. If it
     *                         was not changed, no photo key is present in save_data.
     *   - Multi-value fields (email, address, phone, website) have a key that includes the subtype setting delimited by
     *     a colon (e.g. "email:home"). The value of each setting is an array. These arrays may again include empty
     *     members if the field was part of the edit mask but not filled.
     *
     * @param array $save_data The roundcube representation of the contact / group
     * @param ?VCard $vcard The original VCard from that the address data was originally passed to roundcube. If a new
     *                      VCard should be created, this parameter must be null.
     * @param bool $isGroup Set to true if this VCard is for a VCard-style group, otherwise to false for contact card.
     * @return VCard Returns the created / updated VCard. If a VCard was passed in the $vcard parameter, it is updated
     *               in place.
     */
    public function fromRoundcube(array $save_data, ?VCard $vcard = null, bool $isGroup = false): VCard
    {
        unset($save_data['vcard']);

        if (!$isGroup) {
            // for contacts, determine whether to display as company or individual
            $save_data = $this->setShowAs($save_data);
        }

        if (!isset($vcard)) {
            // create fresh minimal vcard
            $vcard = new VObject\Component\VCard(['VERSION' => '3.0']);
        }

        // update revision
        $vcard->REV = $this->dateTimeString();

        // N is mandatory
        if (key_exists('kind', $save_data) && $save_data['kind'] === 'group') {
            $vcard->N = [$save_data['name'],"","","",""];
        } else {
            $vcard->N = [
                $save_data['surname'],
                $save_data['firstname'],
                $save_data['middlename'],
                $save_data['prefix'],
                $save_data['suffix'],
            ];
        }

        $new_org_value = [];
        if (
            key_exists("organization", $save_data)
            && strlen($save_data['organization']) > 0
        ) {
            $new_org_value[] = $save_data['organization'];
        }

        if (key_exists("department", $save_data)) {
            if (is_array($save_data['department'])) {
                foreach ($save_data['department'] as $value) {
                    $new_org_value[] = $value;
                }
            } elseif (strlen($save_data['department']) > 0) {
                $new_org_value[] = $save_data['department'];
            }
        }

        if (count($new_org_value) > 0) {
            $vcard->ORG = $new_org_value;
        } else {
            unset($vcard->ORG);
        }

        // normalize date fields to RFC2425 YYYY-MM-DD date values
        foreach (self::DATEFIELDS as $key) {
            if (isset($save_data[$key])) {
                $data = is_array($save_data[$key]) ? $save_data[$key][0] : $save_data[$key];
                if (strlen($data) > 0) {
                    $val = rcube_utils::strtotime($data);
                    $save_data[$key] = date('Y-m-d', $val);
                }
            }
        }

        // process all simple attributes
        foreach (self::VCF2RC['simple'] as $vkey => $rckey) {
            if (key_exists($rckey, $save_data)) {
                $data = (is_array($save_data[$rckey])) ? $save_data[$rckey][0] : $save_data[$rckey];
                if (strlen($data) > 0) {
                    $vcard->{$vkey} = $data;
                } else { // delete the field
                    unset($vcard->{$vkey});
                }
            }
        }

        // Special handling for PHOTO
        if ($property = $vcard->PHOTO) {
            $property['ENCODING'] = 'b';
            $property['VALUE'] = 'binary';
        }

        // process all multi-value attributes

        // delete and fully recreate all entries; there is no easy way of mapping an address in the existing card to an
        // address in the save data, as subtypes may have changed
        foreach (array_keys(self::VCF2RC['multi']) as $vkey) {
            unset($vcard->{$vkey});
        }
        unset($vcard->ADR);

        // now clear out all orphan X-ABLabel properties
        $this->clearOrphanAttrLabels($vcard);

        // and finally recreate the attributes
        foreach (self::VCF2RC['multi'] as $vkey => $rckey) {
            $stmap = [ $rckey => 'other' ];
            foreach ($this->coltypes[$rckey]['subtypes'] as $subtype) {
                $stmap[ $rckey . ':' . $subtype ] = $subtype;
            }

            foreach ($stmap as $rcqkey => $subtype) {
                if (key_exists($rcqkey, $save_data)) {
                    $avalues = is_array($save_data[$rcqkey]) ? $save_data[$rcqkey] : [$save_data[$rcqkey]];
                    foreach ($avalues as $evalue) {
                        if (strlen($evalue) > 0) {
                            $prop = $vcard->createProperty($vkey, $evalue);
                            $vcard->add($prop);
                            $this->setAttrLabel($vcard, $prop, $rckey, $subtype); // set label
                        }
                    }
                }
            }
        }

        // process address entries
        foreach ($this->coltypes['address']['subtypes'] as $subtype) {
            $rcqkey = 'address:' . $subtype;

            if (is_array($save_data[$rcqkey])) {
                foreach ($save_data[$rcqkey] as $avalue) {
                    if (
                        strlen($avalue['street'])
                        || strlen($avalue['locality'])
                        || strlen($avalue['region'])
                        || strlen($avalue['zipcode'])
                        || strlen($avalue['country'])
                    ) {
                        $prop = $vcard->createProperty('ADR', [
                            '',
                            '',
                            $avalue['street'],
                            $avalue['locality'],
                            $avalue['region'],
                            $avalue['zipcode'],
                            $avalue['country'],
                        ]);
                        $vcard->add($prop);
                        $this->setAttrLabel($vcard, $prop, 'address', $subtype); // set label
                    }
                }
            }
        }

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
     *  1. If the property is part of a group that also contains an X-ABLabel property, the X-ABLabel value is used.
     *  2. The TYPE parameter that, of all the specified TYPE parameters, is listed first in the
     *     coltypes[<attr>]["subtypes"] array. Note that TYPE parameter values not listed in the subtypes array will be
     *     ignored in the selection.
     *  3. If no known TYPE parameter value is specified, "other" is used, which is a valid subtype for all currently
     *     supported multi-value properties.
     */
    private function getAttrLabel(VCard $vcard, VObject\Property $vprop, string $attrname): string
    {
        // 1. check for a custom label using Apple's X-ABLabel extension
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

        // 2. select a known standard label if available
        if (isset($vprop['TYPE']) && is_array($this->coltypes[$attrname]['subtypes'])) {
            $selection = null;

            foreach ($vprop['TYPE'] as $type) {
                $type = strtolower($type);
                $pref = array_search($type, $this->coltypes[$attrname]['subtypes'], true);

                if ($pref !== false) {
                    if (!isset($selection) || $pref < $selection[1]) {
                        $selection = [ $type, $pref ];
                    }
                }
            }
        }

        // 3. return default subtype
        return $selection[0] ?? 'other';
    }

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

    private function downloadPhoto(array &$save_data, AddressbookCollection $davAbook): bool
    {
        $uri = $save_data['photo'];
        try {
            $this->logger->info("downloadPhoto: Attempt to download photo from $uri");
            $response = $davAbook->downloadResource($uri);
            $save_data['photo'] = $response['body'];
        } catch (\Exception $e) {
            $this->logger->warning("downloadPhoto: Attempt to download photo from $uri failed: $e");
            return false;
        }

        return true;
    }

    /******************************************************************************************************************
     ************                                   +         +         +                                  ************
     ************                                   X-ABShowAs Extension                                   ************
     ************                                   +         +         +                                  ************
     *****************************************************************************************************************/

    /**
     * Sets the showas setting (individual vs. company) by heuristic from the entered data.
     *
     * The showas setting allows addressbooks to display a contact as an organization rather than an individual.
     *
     * If no setting of showas is available (e.g. new contact created in roundcube):
     *   - the setting will be set to COMPANY if ONLY organization is given (but no firstname / surname)
     *   - otherwise it will be set to display as INDIVIDUAL
     *
     * If an existing ShowAs=COMPANY setting is given, but the organization field is empty, the setting will be reset to
     * INDIVIDUAL.
     */
    private function setShowAs(array $save_data): array
    {
        if (empty($save_data['showas'])) {
            if (empty($save_data['surname']) && empty($save_data['firstname']) && !empty($save_data['organization'])) {
                $save_data['showas'] = 'COMPANY';
            } else {
                $save_data['showas'] = 'INDIVIDUAL';
            }
        } else {
            // organization not set but showas==COMPANY => show as INDIVIDUAL
            if (empty($save_data['organization']) && $save_data['showas'] === 'COMPANY') {
                $save_data['showas'] = 'INDIVIDUAL';
            }
        }

        // generate display name according to display order setting
        $save_data = self::setDisplayname($save_data);

        return $save_data;
    }

    /**
     * Determines the name to be displayed for a contact. The routine
     * distinguishes contact cards for individuals from organizations.
     */
    private static function setDisplayname(array $save_data): array
    {
        if (strcasecmp($save_data['showas'], 'COMPANY') == 0 && strlen($save_data['organization']) > 0) {
            $save_data['name']     = $save_data['organization'];
        }

        // we need a displayname; if we do not have one, try to make one up
        if (strlen($save_data['name']) == 0) {
            $dname = [];
            if (strlen($save_data['firstname']) > 0) {
                $dname[] = $save_data['firstname'];
            }
            if (strlen($save_data['surname']) > 0) {
                $dname[] = $save_data['surname'];
            }

            if (count($dname) > 0) {
                $save_data['name'] = implode(' ', $dname);
            } else { // no name? try email and phone
                $ep_keys = array_keys($save_data);
                $ep_keys = preg_grep(";^(email|phone):;", $ep_keys);
                sort($ep_keys, SORT_STRING);
                foreach ($ep_keys as $ep_key) {
                    $ep_vals = $save_data[$ep_key];
                    if (!is_array($ep_vals)) {
                        $ep_vals = [$ep_vals];
                    }

                    foreach ($ep_vals as $ep_val) {
                        if (strlen($ep_val) > 0) {
                            $save_data['name'] = $ep_val;
                            break 2;
                        }
                    }
                }
            }

            // still no name? set to unknown and hope the user will fix it
            if (strlen($save_data['name']) == 0) {
                $save_data['name'] = 'Unset Displayname';
            }
        }

        return $save_data;
    }

    /******************************************************************************************************************
     ************                                   +         +         +                                  ************
     ************                               X-ABCROP-RECTANGLE Extension                               ************
     ************                                   +         +         +                                  ************
     *****************************************************************************************************************/

    /**
     * Crops the given PHOTO property if it contains an X-ABCROP-RECTANGLE parameter.
     *
     * The parameter looks like this:
     * X-ABCROP-RECTANGLE=ABClipRect_1&60&179&181&181&qZ54yqewvBZj2mycxrnqsA==
     *
     *  - The 1st number is the horizontal offset (X) from the left
     *  - The 2nd number is the vertical offset (Y) from the bottom
     *  - The 3rd number is the crop width
     *  - The 4th number is the crop height
     *
     * The meaning of the base64 encoded last part of the parameter is unknown and ignored.
     *
     * The resulting cropped photo is returned as binary string. In case the given photo lacks the X-ABCROP-RECTANGLE
     * parameter or the GD library is not available, null is returned instead.
     */
    private static function xabcropphoto(VObject\Property $photo): ?string
    {
        if (!function_exists('gd_info')) {
            return null;
        }

        $abcrop = $photo['X-ABCROP-RECTANGLE'];
        if (!($abcrop instanceof VObject\Parameter)) {
            return null;
        }

        $parts = explode('&', (string) $abcrop);
        $x = intval($parts[1]);
        $y = intval($parts[2]);
        $w = intval($parts[3]);
        $h = intval($parts[4]);
        $dw = min($w, self::MAX_PHOTO_SIZE);
        $dh = min($h, self::MAX_PHOTO_SIZE);

        $src = imagecreatefromstring((string) $photo);
        $dst = imagecreatetruecolor($dw, $dh);
        imagecopyresampled($dst, $src, 0, 0, $x, imagesy($src) - $y - $h, $dw, $dh, $w, $h);

        ob_start();
        imagepng($dst);
        $data = ob_get_contents();
        ob_end_clean();

        return $data;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
