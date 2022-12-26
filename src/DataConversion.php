<?php

/*
 * RCMCardDAV - CardDAV plugin for Roundcube webmail
 *
 * Copyright (C) 2011-2022 Benjamin Schieder <rcmcarddav@wegwerf.anderdonau.de>,
 *                         Michael Stilkerich <ms@mike2k.de>
 *
 * This file is part of RCMCardDAV.
 *
 * RCMCardDAV is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * RCMCardDAV is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with RCMCardDAV. If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavAddressbook4Roundcube;

use Psr\Log\LoggerInterface;
use Sabre\VObject;
use Sabre\VObject\Component\VCard;
use MStilkerich\CardDavClient\AddressbookCollection;
use MStilkerich\CardDavAddressbook4Roundcube\Db\AbstractDatabase;
use carddav;
use rcube_utils;

/**
 * @psalm-type SaveDataMultiField = list<string>
 * @psalm-type SaveDataAddressField = array<string,string>
 * @psalm-type SaveData = array{
 *     name?: string,
 *     firstname?: string,
 *     surname?: string,
 *     cuid?: string,
 *     kind?: string,
 *     ID?: string,
 *     birthday?: string,
 *     nickname?: string,
 *     notes?: string,
 *     photo?: string | DelayedPhotoLoader,
 *     jobtitle?: string,
 *     showas?: string,
 *     anniversary?: string,
 *     assistant?: string,
 *     gender?: string,
 *     manager?: string,
 *     spouse?: string,
 *     maidenname?: string,
 *     organization?: string,
 *     department?: string
 * } & array<string, SaveDataMultiField|list<SaveDataAddressField>>
 *
 * @psalm-type SaveDataFromDC = array{
 *     name: string,
 *     firstname?: string,
 *     surname?: string,
 *     kind: string,
 *     cuid?: string,
 *     ID?: string,
 *     birthday?: string,
 *     nickname?: string,
 *     notes?: string,
 *     photo?: string | DelayedPhotoLoader,
 *     jobtitle?: string,
 *     showas?: string,
 *     anniversary?: string,
 *     assistant?: string,
 *     gender?: string,
 *     manager?: string,
 *     spouse?: string,
 *     maidenname?: string,
 *     organization?: string,
 *     department?: string,
 *     vcard?: string,
 *     _carddav_vcard?: VCard,
 * } & array<string, SaveDataMultiField|list<SaveDataAddressField>>
 *
 * @psalm-type ColTypeDef = array{subtypes?: list<string>, subtypealias?: array<string,string>}
 * @psalm-type ColTypeDefs = array<string,ColTypeDef>
 */
class DataConversion
{
    /** @var string This is an empty VCard string to workaround an incompatibility of Roundcube's export code with the
     *              DelayedPhotoLoader object that we store in the photo attribute of save_data.
     */
    private const EMPTY_VCF =
        "BEGIN:VCARD\r\n" .
        "VERSION:3.0\r\n" .
        "FN:Dummy\r\n" .
        "N:;;;;;\r\n" .
        "END:VCARD\r\n";

    /**
     * @var array{simple: array<string,string>, multi: array<string,string>} VCF2RC
     *      maps VCard property names to roundcube keys
     */
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
            'X-AIM' => 'im:AIM',
            'X-GADUGADU' => 'im:GaduGadu',
            'X-GOOGLE-TALK' => 'im:GoogleTalk',
            'X-GROUPWISE' => 'im:Groupwise',
            'X-ICQ' => 'im:ICQ',
            'X-JABBER' => 'im:Jabber',
            'X-MSN' => 'im:MSN',
            'X-SKYPE' => 'im:Skype',
            'X-TWITTER' => 'im:Twitter',
            'X-YAHOO' => 'im:Yahoo',
        ],
    ];

    /**
     * @var string[] IM_URISCHEME Maps IMPP roundcube subtype to the URI scheme to use in IMPP property. If not
     *                            explicitly listed, use the service name lowercase. For custom one use x-unknown.
     *                            See also: https://en.wikipedia.org/wiki/List_of_URI_schemes
     */
    private const IM_URISCHEME = [
        'GaduGadu' => "gg", // eMClient: gadu, Wikipedia/KAddressbook: gg
        'GoogleTalk' => "gtalk", // Apple: xmpp, eMClient: google, Wikipedia: gtalk, KAddressbook: googletalk
        'ICQ' => "icq", // Apple: aim
        'Jabber' => "xmpp",
        'MSN' => "msnim", // Apple/Wikipedia: msnim, KAddressbook/eMClient: msn
        'Yahoo' => "ymsgr",
        'Zoom' => "zoomus"
    ];

    /**
     * This is maps the vcard TYPE parameter for TEL to a roundcube subtype. For some types, a combination of type
     * parameters is required (workfax requires TYPE=work AND TYPE=fax), so the latter is an array. Only those subtypes
     * where a special mapping is needed are listed here.
     *
     * @var list<array{string, list<string>}>
     */
    private const TEL_TYPE_TORC = [
        // RCTYPE,  VCARD TYPES (all must be present)
        [ 'mobile',  ['cell'] ],
        [ 'workfax', ['work','fax'] ],
        [ 'homefax', ['fax'] ], // this is also the default when ONLY fax is set without additional home/work param
    ];

    /**
     * This is maps the roundcube subtype for TEL to a list of TYPE parameters to be set in the vcard. For some types, a
     * combination of type parameters is required (workfax requires TYPE=work AND TYPE=fax), so the latter is an array.
     * Only those subtypes where a special mapping is needed are listed here.
     *
     * @var array<string, list<string>>
     */
    private const TEL_TYPE_FROMRC = [
        'home'    => ['home', 'voice'],
        'work'    => ['work', 'voice'],
        'mobile'  => ['cell', 'voice'],
        'homefax' => ['home', 'fax'],
        'workfax' => ['work', 'fax'],
    ];

    /**
     * @var ColTypeDefs $coltypes
     *      Descriptions on the different attributes of address objects for roundcube
     */
    private $coltypes = [
        'name' => [],
        'firstname' => [],
        'surname' => [],
        'maidenname' => [],
        'email' => [
            'subtypes' => ['home','work','other'],
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
            'subtypes' => ['home','work','mobile','homefax','workfax','car','pager','video','other'],
        ],
        'address' => [
            'subtypes' => ['home','work','other'],
        ],
        'birthday' => [],
        'anniversary' => [],
        'website' => [
            'subtypes' => ['home','work','other'],
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
                'WeChat',
                'Yahoo',
                'Zoom',
                'other'
            ],
            'subtypealias' => [
                'gadu' => 'gadugadu',
                'gg' => 'gadugadu',
                'google' => 'googletalk',
                'xmpp' => 'jabber',
                'ymsgr' => 'yahoo',
            ]
        ],
    ];

    /** @var array<string,list<string>> $xlabels custom labels defined in the addressbook */
    private $xlabels = [];

    /** @var string $abookId Database ID of the Addressbook this converter is associated with */
    private $abookId;

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
     */
    public function __construct(string $abookId)
    {
        $this->abookId = $abookId;

        $this->addextrasubtypes();
    }

    /**
     * @return ColTypeDefs
     */
    public function getColtypes(): array
    {
        return $this->coltypes;
    }

    /**
     * Allows to query if a property is a multi-value property (e.g., phone, email).
     *
     * @return bool True if the given property is a multi-value property, false if it is a single-value property.
     */
    public function isMultivalueProperty(string $attrname): bool
    {
        if (isset($this->coltypes[$attrname])) {
            return isset($this->coltypes[$attrname]['subtypes']);
        } else {
            throw new \Exception("$attrname is not a known roundcube contact property");
        }
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
     * @return SaveDataFromDC Roundcube representation of the VCard
     */
    public function toRoundcube(VCard $vcard, AddressbookCollection $davAbook): array
    {
        // in case our input is not a v3 vcard, first convert it to one
        if ($vcard->getDocumentType() != VObject\Document::VCARD30) {
            $vcard = $vcard->convert(VObject\Document::VCARD30);
        }

        $save_data = [
            // DEFAULTS
            'kind'   => 'individual',
            // this causes roundcube's own vcard creation code be skipped in the VCard export
            'vcard'  => self::EMPTY_VCF,
        ];

        foreach (self::VCF2RC['simple'] as $vkey => $rckey) {
            /** @var ?VObject\Property */
            $property = $vcard->{$vkey};
            if (isset($property)) {
                $property = (string) $property;

                if (strlen($property) > 0) {
                    $save_data[$rckey] = $property;
                }
            }
        }

        // Set a proxy for photo computation / retrieval on demand
        if (key_exists('photo', $save_data) && isset($vcard->PHOTO)) {
            $save_data["photo"] = new DelayedPhotoLoader($vcard, $davAbook);
        }

        $property = $vcard->N;
        if (isset($property)) {
            $attrs = [ "surname", "firstname", "middlename", "prefix", "suffix" ];
            /** @var list<string> */
            $N = $property->getParts();
            for ($i = 0; $i < min(count($N), count($attrs)); $i++) {
                if (strlen($N[$i]) > 0) {
                    $save_data[$attrs[$i]] = $N[$i];
                }
            }
        }

        $property = $vcard->ORG;
        if (isset($property)) {
            /** @var list<string> */
            $ORG = $property->getParts();

            if (count($ORG) > 0) {
                $organization = $ORG[0];
                if (strlen($organization) > 0) {
                    $save_data['organization'] = $organization;
                }

                if (count($ORG) > 1) {
                    $department = implode("; ", array_slice($ORG, 1));
                    if (strlen($department) > 0) {
                        $save_data['department'] = $department;
                    }
                }
            }
        }

        foreach (self::VCF2RC['multi'] as $vkey => $rckey) {
            /** @var ?VObject\Property */
            $properties = $vcard->{$vkey};
            if (isset($properties)) {
                // if the attribute already maps to a specific subtype, it is contained in rckey
                [$rckey] = $rckeyComp = explode(':', $rckey, 2);

                /** @var VObject\Property $prop */
                foreach ($properties as $prop) {
                    $label = (count($rckeyComp) < 2) ? $this->getAttrLabel($vcard, $prop, $rckey) : $rckeyComp[1];

                    if (method_exists($this, "toRoundcube$vkey")) {
                        /** @var null|string|SaveDataAddressField special handler for structured property */
                        $propValue = call_user_func([$this, "toRoundcube$vkey"], $prop);
                        if (!isset($propValue)) {
                            continue;
                        }
                    } else {
                        $propValue = (string) $prop;
                        if (strlen($propValue) == 0) {
                            continue;
                        }
                    }

                    /** @var list<string> */
                    $existingValues = $save_data["$rckey:$label"] ?? [];
                    if (!in_array($propValue, $existingValues)) {
                        $save_data["$rckey:$label"][] = $propValue;
                    }
                }
            }
        }

        // set displayname if not set from VCard
        if (!isset($save_data["name"]) || strlen((string) $save_data["name"]) == 0) {
            $save_data["name"] = self::composeDisplayname($save_data);
        }

        $save_data['_carddav_vcard'] = $vcard;
        return $save_data;
    }

    /**
     * Creates roundcube address data from an ADR VCard property.
     *
     * @param VObject\Property The ADR property to use as input.
     * @return ?SaveDataAddressField The roundcube address data created from the property.
     */
    private function toRoundcubeADR(VObject\Property $prop): ?array
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
        /** @var list<string> */
        $p = $prop->getParts();
        $addr = [];
        for ($i = 0; $i < min(count($p), count($attrs)); $i++) {
            if (strlen($p[$i]) > 0) {
                $addr[$attrs[$i]] = $p[$i];
            }
        }

        return empty($addr) ? null : $addr;
    }

    /**
     * Creates roundcube instant messaging data
     *
     * @param VObject\Property The IMPP property to use as input.
     * @return ?string The roundcube data created from the property.
     */
    private function toRoundcubeIMPP(VObject\Property $prop): ?string
    {
        // Examples:
        // From iCloud Web Addressbook: IMPP;X-SERVICE-TYPE=aim;TYPE=HOME;TYPE=pref:aim:jdoe@example.com
        // From Nextcloud: IMPP;TYPE=SKYPE:jdoe@example.com
        // Note: the nextcloud example does not have an URI value, thus it's not compliant with RFC 4770
        $comp = explode(":", (string) $prop, 2);
        $ret = $comp[count($comp) == 2 ? 1 : 0];
        if (strlen($ret) == 0) {
            return null;
        }
        return $ret;
    }

    /**
     * Creates a new or updates an existing vcard from save data.
     *
     * @param SaveData $save_data The roundcube representation of the contact / group
     * @param ?VCard $vcard The original VCard from that the address data was originally passed to roundcube. If a new
     *                      VCard should be created, this parameter must be null.
     * @return VCard Returns the created / updated VCard. If a VCard was passed in the $vcard parameter, it is updated
     *               in place.
     */
    public function fromRoundcube(array $save_data, ?VCard $vcard = null): VCard
    {
        $isGroup = (($save_data['kind'] ?? "") === "group");

        if (!isset($save_data["name"]) || strlen($save_data["name"]) == 0) {
            if (!$isGroup) {
                $save_data["showas"] = $this->determineShowAs($save_data);
            }
            $save_data["name"] = $this->composeDisplayname($save_data);
        }

        if (isset($vcard)) {
            $vcardVersion = $vcard->getDocumentType();
            if ($vcardVersion != VObject\Document::VCARD30) {
                $vcard4 = $vcard;
                $vcard = $vcard->convert(VObject\Document::VCARD30);
            }
        } else {
            // create fresh minimal vcard
            $vcard = new VObject\Component\VCard(['VERSION' => '3.0']);
            $vcardVersion = VObject\Document::VCARD30;
        }

        // set product
        $vcard->PRODID = '-//MStilkerich//RCMCardDAV ' . carddav::PLUGIN_VERSION . '//EN';
        // update revision
        $vcard->REV = $this->dateTimeString();

        // N is mandatory
        if ($isGroup) {
            $vcard->N = [$save_data['name'],"","","",""];
        } else {
            $nAttr = array_fill(0, 5, "");
            foreach (['surname', 'firstname', 'middlename', 'prefix', 'suffix'] as $idx => $nameKey) {
                if (isset($save_data[$nameKey])) {
                    $nAttr[$idx] = $save_data[$nameKey];
                }
            }
            $vcard->N = $nAttr;
        }

        $this->setOrgProperty($save_data, $vcard);
        $this->setSingleValueProperties($save_data, $vcard);
        $this->setMultiValueProperties($save_data, $vcard);

        // if the original vcard was version 4, convert it back to that version
        if ($vcardVersion == VObject\Document::VCARD40) {
            // XXX Temporary workarounds for sabre-io/vobject#602 BEGIN
            // 1) If the photo was unchanged, preserve the original vcard's property to not lose the mimetype
            $vcard = $vcard->convert(VObject\Document::VCARD40);
            if (isset($vcard4->PHOTO) && !isset($save_data['photo'])) {
                $vcard->PHOTO = $vcard4->PHOTO;
            }

            // 2) Drop X-ADDRESSBOOKSERVER-KIND property; for KIND=group, it has been converted, for KIND=individual it
            //    it has been retained, but since it is the default we can simply drop it.
            unset($vcard->{'X-ADDRESSBOOKSERVER-KIND'});
            // XXX Temporary workarounds for sabre-io/vobject#602 END
        }

        return $vcard;
    }

    /**
     * Exports a VCard
     *
     * We provide the VCard as is on the server, with the following exceptions:
     *   - PHOTO referenced by URI is downloaded and included in the VCard
     *   - PHOTO with X-ABCROP-RECTANGLE parameter is stored cropped
     *
     * @param VCard $vcard The original VCard object from that the save_data was created
     * @param SaveDataFromDC $save_data The save data created from the VCard
     * @return string The exported and serialized VCard
     */
    public static function exportVCard(VCard $vcard, array $save_data): string
    {
        $photoData = (string) ($save_data["photo"] ?? "");
        if (strlen($photoData) > 0) {
            self::setPhotoProperty($vcard, $photoData);
        }
        // Note: if DelayedPhotoLoader fails for whatever reason, we keep the original PHOTO property untouched

        return $vcard->serialize();
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
     * @param SaveData $save_data The roundcube representation of the contact
     * @param VCard $vcard The VCard to set the ORG property for.
     */
    private function setOrgProperty(array $save_data, VCard $vcard): void
    {
        $orgParts = [];
        if (isset($save_data['organization']) && strlen($save_data['organization']) > 0) {
            $orgParts[] = $save_data['organization'];
        }

        if (isset($save_data['department']) && strlen($save_data['department']) > 0) {
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
     * Sets the PHOTO property in a VCard to an inlined photo, including the necessary parameters.
     */
    private static function setPhotoProperty(VCard $vcard, string $photoData): void
    {
        $vcard->PHOTO = $photoData;
        if (isset($vcard->PHOTO)) {
            $vcard->PHOTO['ENCODING'] = 'b';
            $vcard->PHOTO['VALUE'] = 'binary';

            if (function_exists('getimagesizefromstring')) {
                $typemap = [
                    IMAGETYPE_JPEG => 'JPEG',
                    IMAGETYPE_GIF  => 'GIF',
                    IMAGETYPE_PNG  => 'PNG',
                ];
                $imginfo = getimagesizefromstring($photoData);
                if ($imginfo !== false && isset($imginfo[2]) && is_int($imginfo[2])) {
                    if (key_exists($imginfo[2], $typemap)) {
                        $vcard->PHOTO['TYPE'] = $typemap[$imginfo[2]];
                    }
                }
            }
        }
    }

    /**
     * Sets properties with a single value in a VCard from roundcube contact data.
     *
     * About the contents of save_data:
     *   - Empty / deleted fields in roundcube either are missing from save_data or contain an empty string as value.
     *     It is not really clear under what circumstances a field is present empty and when it's missing entirely.
     *   - Fields that are not shown to the user (most importantly: UID) will never be provided in roundcube save_data
     *     -> Those are retained from the original vcard (we can check coltypes for the attributes roundcube knows of)
     *   - Special case photo: It is only set if it was edited. If it is deleted, it is set to an empty string. If it
     *                         was not changed, no photo key is present in save_data.
     *
     * @param SaveData $save_data The roundcube representation of the contact
     * @param VCard $vcard The VCard to set the ORG property for.
     */
    private function setSingleValueProperties(array $save_data, VCard $vcard): void
    {
        $logger = Config::inst()->logger();

        foreach (self::VCF2RC['simple'] as $vkey => $rckey) {
            if (isset($save_data[$rckey])) {
                $rcValue = $save_data[$rckey];
                if (!is_string($rcValue)) {
                    $logger->error("save data $rckey must be string" . print_r($rcValue, true));
                    continue;
                }

                if (strlen($rcValue) == 0) {
                    unset($vcard->{$vkey});
                } else {
                    $vcard->{$vkey} = $rcValue;

                    // Special handling for PHOTO
                    // If PHOTO is set from roundcube data, set the parameters properly
                    if ($rckey === "photo") {
                        self::setPhotoProperty($vcard, $rcValue);
                    }
                }
            } else {
                // If existing, preserve properties not known to roundcube (e.g. UID)
                // If photo is not set, it was not changed in roundcube -> preserve too if exists
                if (isset($this->coltypes[$rckey]) && $rckey != "photo") {
                    unset($vcard->{$vkey});
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
     * @param SaveData $save_data The roundcube representation of the contact
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
            // Determine the actually present subtypes in the save data; if the VCard property is mapped to a specific
            // subtype, restrict the selection to that subtype.
            [$rckey] = $rckeyComp = explode(':', $rckey, 2);
            if (count($rckeyComp) > 1) {
                [ $rckey, $rclabel ] = $rckeyComp;
                $subtypes = isset($save_data["$rckey:$rclabel"]) ? [ $rclabel ] : [];
            } else {
                $rclabel = null;
                $subtypes = preg_filter("/^$rckey:?/", '', array_keys($save_data), 1);
            }

            foreach ($subtypes as $subtype) {
                // In some cases, roundcube passes a multi-value attribute without subtype (e.g. "email"), e.g. "add
                // contact to addressbook" from mail view
                $sdkey = strlen($subtype) > 0 ? "$rckey:$subtype" : $rckey;

                // Cast to array - roundcube passes simple string in some cases, e.g. "add contact to addressbook" from
                // mail view
                /** @var SaveDataMultiField $values */
                $values = (array) $save_data[$sdkey];
                foreach ($values as $value) {
                    $prop = null;

                    $mkey = str_replace("-", "_", strtoupper($vkey));
                    if (method_exists($this, "fromRoundcube$mkey")) {
                        /** @var ?VObject\Property special handler for structured property */
                        $prop = call_user_func([$this, "fromRoundcube$mkey"], $value, $vcard, $subtype);
                    } else {
                        if (strlen($value) > 0) {
                            $prop = $vcard->createProperty($vkey, $value);
                            $vcard->add($prop);
                        }
                    }

                    // in case $rclabel is set, the property is implicitly assigned a subtype (e.g. X-SKYPE)
                    if (isset($prop) && !isset($rclabel)) {
                        if (method_exists($this, "setAttrLabel$mkey")) {
                            call_user_func([$this, "setAttrLabel$mkey"], $vcard, $prop, $rckey, $subtype);
                        } else {
                            $this->setAttrLabel($vcard, $prop, $rckey, $subtype);
                        }
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
     * @param SaveDataAddressField $address The address array as provided by roundcube
     * @param VCard $vcard The VCard to add the property to.
     * @param string $_subtype The subtype/label assigned by roundcube
     * @return ?VObject\Property The created property, null if no property was created.
     */
    private function fromRoundcubeADR(array $address, VCard $vcard, string $_subtype): ?VObject\Property
    {
        $prop = null;
        $adrValue = [ "" /* PO box */, "" /* extended address */ ];
        $haveNonEmptyField = false;

        foreach (['street', 'locality', 'region', 'zipcode', 'country'] as $adrAttr) {
            $val = $address[$adrAttr] ?? "";
            $haveNonEmptyField = $haveNonEmptyField || (strlen($val) > 0);
            $adrValue[] = $val;
        }

        if ($haveNonEmptyField) {
            $prop = $vcard->createProperty('ADR', $adrValue);
            $vcard->add($prop);
        }

        return $prop;
    }

    /**
     * Creates an URL property from roundcube address data and adds it to a VCard.
     *
     * The extra behavior of this function is to add a VALUE=URI parameter to the created VCard.
     *
     * @param string $url The URL value
     * @param VCard $vcard The VCard to add the property to.
     * @param string $_subtype The subtype/label assigned by roundcube
     * @return ?VObject\Property The created property, null if no property was created.
     */
    private function fromRoundcubeURL(string $url, VCard $vcard, string $_subtype): ?VObject\Property
    {
        $prop = null;

        if (strlen($url) > 0) {
            $prop = $vcard->createProperty('URL', $url, [ "VALUE" => "URI" ]);
            $vcard->add($prop);
        }

        return $prop;
    }

    /**
     * Creates an IMPP property from roundcube address data and adds it to a VCard.
     *
     * This function is passed a messenger handle.
     *
     * @param string $address The address array as provided by roundcube
     * @param VCard $vcard The VCard to add the property to.
     * @param string $subtype The subtype/label assigned by roundcube
     * @return ?VObject\Property The created property, null if no property was created.
     */
    private function fromRoundcubeIMPP(string $address, VCard $vcard, string $subtype): ?VObject\Property
    {
        $prop = null;

        if (strlen($address) > 0) {
            $scheme = $this->determineUriSchemeForIM($subtype);

            $prop = $vcard->createProperty(
                'IMPP',
                "$scheme:$address",
                [
                    'TYPE' => $subtype,
                    'X-SERVICE-TYPE' => $subtype,
                ]
            );

            $vcard->add($prop);
        }

        return $prop;
    }

    private function determineUriSchemeForIM(string $subtype): string
    {
        $scheme = 'x-unknown';

        if (isset(self::IM_URISCHEME[$subtype])) {
            $scheme = self::IM_URISCHEME[$subtype];
        } elseif (preg_match('/^[A-Za-z][A-Za-z0-9+.-]*$/', $subtype)) {
            $scheme = strtolower($subtype);
        }

        return $scheme;
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

        /** @var VObject\Property $p */
        foreach ($vcard->children() as $p) {
            if (!empty($p->group)) {
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

        /** @var VObject\Property $p */
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
            if (!isset($usedGroups[strtoupper($p->group ?? '')])) {
                $vcard->remove($p);
            }
        }
    }

    /**
     * This function assigns a label (subtype) to a VCard multi-value property.
     *
     * Typical multi-value properties are EMAIL, TEL and ADR.
     *
     * Note that roundcube/rcmcarddav only supports a single subtype per property, whereas VCard allows to have more
     * than one. As an effect, when a card is updated only the subtype selected in roundcube will be preserved, possible
     * extra subtypes will be lost.
     *
     * If the given label is empty, or "other", no TYPE parameter is assigned.
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
        // Don't set a type parameter if there is no label or if the label is "other"
        if (strlen($newlabel) == 0 || $newlabel == 'other') {
            return;
        }

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
     * Sets the TYPE parameters for TEL properties in case a mapping between roundcube and vcard is needed.
     * If not, calls the standard setAttrLabel() function.
     */
    private function setAttrLabelTEL(VCard $vcard, VObject\Property $vprop, string $attrname, string $newlabel): void
    {
        if (isset(self::TEL_TYPE_FROMRC[$newlabel])) {
            $vprop['TYPE'] = self::TEL_TYPE_FROMRC[$newlabel];
        } else {
            $this->setAttrLabel($vcard, $vprop, $attrname, $newlabel);
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
     *     supported multi-value properties. This value means no specific subtype is available for the property.
     */
    private function getAttrLabel(VCard $vcard, VObject\Property $vprop, string $attrname): string
    {
        // 1. Check if there is a property-specific handler to provide label
        $vkey = str_replace("-", "_", strtoupper($vprop->name));
        if (method_exists($this, "getAttrLabel$vkey")) {
            /** @var string */
            return call_user_func([$this, "getAttrLabel$vkey"], $vcard, $vprop);
        }

        // 2. check for a custom label using Apple's X-ABLabel extension
        $xAbLabel = $this->getAttrXAbLabel($vcard, $vprop, $attrname);
        if ($xAbLabel != null) {
            return $xAbLabel;
        }

        // 3. select a known standard label if available
        $selection = null;
        if (isset($vprop["TYPE"]) && !empty($this->coltypes[$attrname]['subtypes'])) {
            /** @var VObject\Parameter */
            foreach ($vprop["TYPE"] as $type) {
                $type = strtolower((string) $type);
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
     * Checks if there is an X-ABLabel for the given property in a vcard. If not, null is returned, otherwise the label.
     */
    private function getAttrXAbLabel(VCard $vcard, VObject\Property $vprop, string $attrname): ?string
    {
        $group = $vprop->group;
        if (isset($group)) {
            /** @var ?VObject\Property */
            $xlabel = $vcard->{"$group.X-ABLabel"};
            if (!empty($xlabel)) {
                // special labels from Apple namespace are stored in the form "_$!<Label>!$_" - extract label
                $xlabel = preg_replace(';_\$!<(.*)>!\$_;', '$1', (string) $xlabel);

                // add to known types if new
                if (!in_array($xlabel, $this->coltypes[$attrname]['subtypes'] ?? [])) {
                    $this->storeextrasubtype($attrname, $xlabel);
                }
                return $xlabel;
            }
        }

        return null;
    }

    /**
     * Acquires label for the TEL property.
     *
     * @return string The determined label.
     */
    private function getAttrLabelTEL(VCard $vcard, VObject\Property $prop): string
    {
        assert(!empty($this->coltypes["phone"]["subtypes"]), "phone attribute requires a list of subtypes");

        // 1) check for a custom label using Apple's X-ABLabel extension
        $xAbLabel = $this->getAttrXAbLabel($vcard, $prop, 'phone');
        if ($xAbLabel != null) {
            return $xAbLabel;
        }

        // 2) check TYPE parameters for types that require a special mapping
        $setTypes = [];

        if (isset($prop["TYPE"])) {
            /** @var VObject\Parameter */
            foreach ($prop["TYPE"] as $type) {
                $setTypes[] = strtolower((string) $type);
            }
        }

        foreach (self::TEL_TYPE_TORC as $tuple) {
            [$rctype, $vctypes] = $tuple;
            if (empty(array_diff($vctypes, $setTypes))) {
                return $rctype;
            }
        }

        // 3) check if there is a 1:1 mapped type available
        foreach ($this->coltypes['phone']['subtypes'] as $rctype) {
            if (in_array($rctype, $setTypes)) {
                return $rctype;
            }
        }

        // 4) nothing found...
        return 'other';
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
        assert(!empty($this->coltypes["im"]["subtypes"]), "im attribute requires a list of subtypes");
        $subtypesLower = array_map('strtolower', $this->coltypes["im"]["subtypes"]);

        // check X-SERVICE-TYPE parameter (seen in entries created by Apple Addressbook)
        if (isset($prop["X-SERVICE-TYPE"])) {
            /** @var VObject\Parameter */
            foreach ($prop["X-SERVICE-TYPE"] as $type) {
                $type = (string) $type;
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
            /** @var VObject\Parameter */
            foreach ($prop["TYPE"] as $type) {
                $ltype = strtolower((string) $type);
                $ltype = $this->coltypes["im"]["subtypealias"][$ltype] ?? $ltype;
                $pos = array_search($ltype, $subtypesLower, true);
                if ($pos !== false) {
                    return $this->coltypes["im"]['subtypes'][$pos];
                }
            }
        }

        return 'other';
    }

    /**
     * Stores a custom label in the database (X-ABLabel extension).
     *
     * @param string Name of the type/category (phone,address,email)
     * @param string Name of the custom label to store for the type
     */
    private function storeextrasubtype(string $typename, string $subtype): void
    {
        $db = Config::inst()->db();
        $db->insert("xsubtypes", ["typename", "subtype", "abook_id"], [[$typename, $subtype, $this->abookId]]);
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
        $db = Config::inst()->db();
        $this->xlabels = [];

        foreach ($this->coltypes as $attr => $v) {
            if (key_exists('subtypes', $v)) {
                $this->xlabels[$attr] = [];
            }
        }

        /** @var list<array{typename: string, subtype: string}> read extra subtypes */
        $xtypes = $db->get(['abook_id' => $this->abookId], ['typename', 'subtype'], 'xsubtypes');

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
     * @param SaveData $save_data The address data as roundcube's internal format, as entered by
     *                                                 the user. For update of an existing contact, the showas key must
     *                                                 be populated with the previous value.
     * @return string INDIVIDUAL or COMPANY
     */
    private function determineShowAs(array $save_data): string
    {
        $showAs = $save_data['showas'] ?? "";

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
     * @param SaveData $save_data The address data as roundcube's internal format.
     * @return string The composed displayname
     */
    private static function composeDisplayname(array $save_data): string
    {
        $showAs = $save_data['showas'] ?? "";

        if (strcasecmp($showAs, 'COMPANY') == 0 && !empty($save_data['organization'])) {
            return $save_data['organization'];
        }

        // try from name
        $dname = [];
        foreach (["firstname", "surname"] as $attr) {
            if (!empty($save_data[$attr])) {
                /** @psalm-var string */
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
            /** @var SaveDataMultiField */
            $epVals = $save_data[$epKey];
            foreach ($epVals as $epVal) {
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
