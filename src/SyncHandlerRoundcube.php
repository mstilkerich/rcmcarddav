<?php

/**
 * Synchronization handler that stores changes to roundcube database.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavAddressbook4Roundcube;

use Sabre\VObject\Component\VCard;
use MStilkerich\CardDavClient\AddressbookCollection;
use MStilkerich\CardDavClient\Services\SyncHandler;
use carddav_backend;
use rcmail;

class SyncHandlerRoundcube implements SyncHandler
{
    /** @var carddav_backend */
    private $rcAbook;

    /** @var array Maps URIs to an associative array containing etag and (database) id */
    private $existing_card_cache = [];
    /** @var array Maps URIs of KIND=group cards to an associative array containing etag and (database) id */
    private $existing_grpcard_cache = [];
    /** @var array used to record which users need to be added to which groups */
    private $users_to_add = [];

    public function __construct(carddav_backend $rcAbook)
    {
        $this->$rcAbook = $rcAbook;

        // determine existing local contact URIs and ETAGs
        $contacts = carddav_backend::get_dbrecord($this->id,'id,uri,etag','contacts',false,'abook_id');
        foreach($contacts as $contact) {
            $this->existing_card_cache[$contact['uri']] = $contact;
        }

        // determine existing local group URIs and ETAGs
        $groups = carddav_backend::get_dbrecord($this->id,'id,uri,etag','groups',false,'abook_id');
        foreach($groups as $group) {
            $this->existing_grpcard_cache[$group['uri']] = $group;
        }
    }

    public function addressObjectChanged(string $uri, string $etag, VCard $card): void
    {
        $dbh = rcmail::get_instance()->db;
        $save_data_arr = $this->rcAbook->create_save_data_from_vcard($card);
        $vcfobj = $save_data_arr['vcf'];
        $vcf = $vcfobj->serialize();
        $save_data = $save_data_arr['save_data'];

        if($save_data['kind'] === 'group') {
            $dbid = $this->existing_grpcard_cache[$uri]["id"] ?? null;
            carddav::$logger->debug('Processing Group ' . $save_data['name']);
            // delete current group members (will be reinserted if needed below)
            if ($dbid) {
                carddav_backend::delete_dbrecord($dbid,'group_user','group_id');
            }

            // store group card
            $dbid = $this->rcAbook->dbstore_group("$etag","$href","$vcf",$save_data,$dbid);
            if($dbid !== false) {
                // record group members for deferred store
                $this->users_to_add[$dbid] = [];

                // X-ADDRESSBOOKSERVER-MEMBER:urn:uuid:51A7211B-358B-4996-90AD-016D25E77A6E
                $members = $vcfobj->{'X-ADDRESSBOOKSERVER-MEMBER'} ?? [];

                carddav::$logger->debug("Group $dbid has " . count($members) . " members");
                foreach($members as $mbr) {
                    $mbrc = explode(':', $mbr);
                    if(count($mbrc)!=3 || $mbrc[0] !== 'urn' || $mbrc[1] !== 'uuid') {
                        carddav::$logger->warning("don't know how to interpret group membership: $mbr");
                        continue;
                    }
                    $this->users_to_add[$dbid][] = $dbh->quote($mbrc[2]);
                }
            }
        } else { // individual/other
            if (trim($save_data['name']) == '') { // roundcube display fix for contacts that don't have first/last names
                if (trim($save_data['nickname'] ?? "") !== '') {
                    $save_data['name'] = $save_data['nickname'];
                } else {
                    foreach ($save_data as $key => $val) {
                        if (strpos($key,'email') !== false) {
                            $save_data['name'] = $val[0];
                            break;
                        }
                    }
                }
            }

            $this->rcAbook->dbstore_contact("$etag","$href","$vcf",$save_data,$dbid);
        }
    }

    public function addressObjectDeleted(string $uri): void
    {
        if (isset($this->existing_card_cache[$uri]["id"])) {
            carddav_backend::delete_dbrecord($this->existing_card_cache[$uri]["id"]);
        } elseif (isset($this->existing_grpcard_cache[$uri]["id"])) {
            carddav_backend::delete_dbrecord($this->existing_grpcard_cache[$uri]["id"], 'groups');
        } else {
            carddav::$logger->warning("Server reported deleted card $uri for that no DB entry exists");
        }
    }

    public function getExistingVCardETags(): array
    {
        $cache = [];

        foreach ($this->existing_card_cache as $uri => $cacheEntry) {
            $cache[$uri] = $cacheEntry["etag"];
        }

        foreach ($this->existing_grpcard_cache as $uri => $cacheEntry) {
            $cache[$uri] = $cacheEntry["etag"];
        }

        return $cache;
    }

    public function finalizeSync(): void
    {
        $dbh = rcmail::get_instance()->db;
        $abookId = $this->rcAbook->getId();
        foreach($this->users_to_add as $dbid => $cuids) {
            if(count($cuids) > 0) {
                $sql_result = $dbh->query('INSERT INTO '.
                    $dbh->table_name('carddav_group_user') .
                    ' (group_id,contact_id) SELECT ?,id from ' .
                    $dbh->table_name('carddav_contacts') .
                    ' WHERE abook_id=? AND cuid IN (' . implode(',', $cuids) . ')', $dbid, $abookId);
                carddav::$logger->debug("Added " . $dbh->affected_rows($sql_result) . " contacts to group $dbid");
            }
        }
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
