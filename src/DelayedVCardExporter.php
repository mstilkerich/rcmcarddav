<?php

/*
 * RCMCardDAV - CardDAV plugin for Roundcube webmail
 *
 * Copyright (C) 2011-2021 Benjamin Schieder <rcmcarddav@wegwerf.anderdonau.de>,
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

use Sabre\VObject\Component\VCard;

/**
 * This class is intended to delay the conversion of a VCard for export until the export.
 *
 * Background: By providing a serialized VCard in the save_data provided to Roundcube, we can skip Roundcube's own
 * creation of a VCard from the save_data. This allows us to implement some extra features, most notably the inlining of
 * externally referenced photos.
 *
 * Because the conversion requires some computational effort that is only needed in the seldom case of a VCard export,
 * we wrap the data in this object for delay the conversion until the result is needed. If the result is not needed, the
 * conversion will not take place.
 *
 * @psalm-import-type SaveDataFromDC from DataConversion
 */
class DelayedVCardExporter
{
    /** @var bool $pristine Indicates whether this object has not been processed (toString) yet (for testing) */
    private $pristine = true;

    /** @var SaveDataFromDC $save_data The card in roundcube format, including a DelayedPhotoLoader if applicable */
    private $save_data;

    /** @var DataConversion The data converter for the addressbook the card belongs to */
    private $dataConverter;

    /**
     * @psalm-param SaveDataFromDC $save_data
     */
    public function __construct(array $save_data, DataConversion $dataConverter)
    {
        $this->save_data = $save_data;
        $this->dataConverter = $dataConverter;
    }

    /**
     * Serializes the VCard, fetching the PHOTO in the process if needed.
     */
    public function __toString(): string
    {
        $this->pristine = false;

        if (isset($this->save_data['photo'])) {
            // Trigger creation of photo by DelayedPhotoLoader if applicable
            $this->save_data['photo'] = (string) $this->save_data['photo'];
        }

        $vcard = $this->dataConverter->fromRoundcube($this->save_data);
        return $vcard->serialize();
    }

    /**
     * Tells whether the vcard export has been performed already by this exporter.
     * @return bool True if toString() was _not_ previously executed.
     */
    public function pristine(): bool
    {
        return $this->pristine;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
