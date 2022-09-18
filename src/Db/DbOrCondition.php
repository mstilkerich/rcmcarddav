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

namespace MStilkerich\CardDavAddressbook4Roundcube\Db;

/**
 * Represents one OR-condition to be used for queries in AbstractDatabase operations as part of an AND-condition.
 */
class DbOrCondition
{
    /**
     * The field specifier is the name of a database column.
     *
     * Prefix with ! to invert the condition.
     * Prefix with % to indicate that the value is a pattern to be matched with ILIKE.
     * Prefixes can be combined but must be given in the order listed here.
     *
     * @var string Field specifier of the condition
     * @readonly
     * @psalm-allow-private-mutation
     */
    public $fieldSpec;

    /**
     * Specifies value to check field for. Can be one of the following:
     *   - null: Assert that the field is NULL (or not NULL if inverted)
     *   - string: Assert that the field value matches $value (or does not match value, if inverted)
     *   - string[]: Assert that the field matches one of the strings in the array (or none, if inverted)
     *
     *  The text comparisons are case sensitive. If case insensitive behavior is required, ILIKE match must be used.
     *
     * @var ?string|string[] Value specifier of the condition
     * @readonly
     * @psalm-allow-private-mutation
     */
    public $valueSpec;

    /**
     * @param ?string|string[] $valueSpec
     */
    public function __construct(string $fieldSpec, $valueSpec)
    {
        $this->fieldSpec = $fieldSpec;
        $this->valueSpec = $valueSpec;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
