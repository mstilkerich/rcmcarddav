<?php

namespace Sabre\VObject\Component;

use Sabre\VObject;

/**
 * The VTimeZone component
 *
 * This component adds functionality to a component, specific for VTIMEZONE
 * components.
 *
 * @copyright Copyright (C) 2007-2014 fruux GmbH (https://fruux.com/).
 * @author Evert Pot (http://evertpot.com/)
 * @license http://sabre.io/license/ Modified BSD License
 */
class VTimeZone extends VObject\Component {

    /**
     * Returns the PHP DateTimeZone for this VTIMEZONE component.
     *
     * If we can't accurately determine the timezone, this method will return
     * UTC.
     *
     * @return \DateTimeZone
     */
    function getTimeZone() {

        return VObject\TimeZoneUtil::getTimeZone((string)$this->TZID, $this->root);

    }

    /**
     * A simple list of validation rules.
     *
     * This is simply a list of properties, and how many times they either
     * must or must not appear.
     *
     * Possible values per property:
     *   * 0 - Must not appear.
     *   * 1 - Must appear exactly once.
     *   * + - Must appear at least once.
     *   * * - Can appear any number of times.
     *
     * @var array
     */
    function getValidationRules() {

        return array(
            'TZID' => 1,

            'LAST-MODIFICATION' => '?',
            'TZURL' => '?',

            // At least 1 STANDARD or DAYLIGHT must appear, or more. But both
            // cannot appear in the same VTIMEZONE.
            //
            // The validator is not specific yet to pick this up, so these
            // rules are too loose.
            'STANDARD' => '*',
            'DAYLIGHT' => '*',
        );

    }

}

