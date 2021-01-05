<?php

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
