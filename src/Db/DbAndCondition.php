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
 * Represents one AND-condition to be used for queries in AbstractDatabase operations.
 */
class DbAndCondition
{
    /**
     * @var DbOrCondition[] The list of OrConditions in this DbAndCondition
     * @readonly
     * @psalm-allow-private-mutation
     */
    public $orConditions = [];

    public function __construct(?DbOrCondition $cond = null)
    {
        if (isset($cond)) {
            $this->orConditions[] = $cond;
        }
    }

    /**
     * Adds a DbOrCondition to this DbAndCondition.
     *
     * @param ?string|string[] $valueSpec
     */
    public function add(string $fieldSpec, $valueSpec): DbAndCondition
    {
        $this->orConditions[] = new DbOrCondition($fieldSpec, $valueSpec);
        return $this;
    }

    /**
     * Append the DbOrConditions of another DbAndCondition to this one.
     *
     * Duplicate conditions are filtered out.
     */
    public function append(DbAndCondition $andCond): DbAndCondition
    {
        foreach ($andCond->orConditions as $c) {
            if (!in_array($c, $this->orConditions)) {
                $this->orConditions[] = $c;
            }
        }
        return $this;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
