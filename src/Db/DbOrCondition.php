<?php

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
