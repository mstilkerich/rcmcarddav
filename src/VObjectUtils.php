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

namespace MStilkerich\RCMCardDAV;

use Sabre\VObject\Component\VCard;
use Sabre\VObject\{Parameter,Property};

/**
 * Typed accessors for properties and parameters of sabre/vobject objects.
 *
 * The magic accessors of sabre/vobject (e.g. $vcard->UID, $prop['TYPE']) are declared to return a generic node type
 * that may also be null. These helpers return the specific type expected in the context of a VCard.
 */
final class VObjectUtils
{
    /**
     * Returns the property with the given name of a VCard.
     *
     * @param string $name Name of the property, optionally prefixed by a group (e.g. "item1.X-ABLabel").
     * @return ?Property The (first) property with the given name, null if the VCard has no such property.
     */
    public static function getProperty(VCard $vcard, string $name): ?Property
    {
        // explicit call of the magic getter as psalm cannot resolve dynamic property names
        $prop = $vcard->__get($name);
        return ($prop instanceof Property) ? $prop : null;
    }

    /**
     * Returns the name of a property or parameter.
     *
     * sabre/vobject 5 declares the name as nullable, but it is always set for the nodes of a parsed VCard.
     */
    public static function getName(Property|Parameter $node): string
    {
        return strval($node->name);
    }

    /**
     * Returns the parameter with the given name of a property.
     *
     * @return ?Parameter The parameter, null if the property has no such parameter.
     */
    public static function getParameter(Property $prop, string $name): ?Parameter
    {
        $param = $prop[$name];
        return ($param instanceof Parameter) ? $param : null;
    }

    /**
     * Returns the values of the parameter with the given name of a property.
     *
     * @return list<string> The parameter values, empty if the property has no such parameter.
     */
    public static function getParameterValues(Property $prop, string $name): array
    {
        $param = self::getParameter($prop, $name);
        if (isset($param)) {
            return array_values(array_map(fn ($value): string => (string) $value, $param->getParts()));
        }

        return [];
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
