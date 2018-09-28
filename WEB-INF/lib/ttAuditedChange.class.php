<?php
// +----------------------------------------------------------------------+
// | Anuko Time Tracker
// +----------------------------------------------------------------------+
// | Copyright (c) Anuko International Ltd. (https://www.anuko.com)
// +----------------------------------------------------------------------+
// | LIBERAL FREEWARE LICENSE: This source code document may be used
// | by anyone for any purpose, and freely redistributed alone or in
// | combination with other software, provided that the license is obeyed.
// |
// | There are only two ways to violate the license:
// |
// | 1. To redistribute this code in source form, with the copyright
// |    notice or license removed or altered. (Distributing in compiled
// |    forms without embedded copyright notices is permitted).
// |
// | 2. To redistribute modified versions of this code in *any* form
// |    that bears insufficient indications that the modifications are
// |    not the work of the original author(s).
// |
// | This license applies to this document only, not any other software
// | that it may be combined with.
// |
// +----------------------------------------------------------------------+
// | Contributors:
// | https://www.anuko.com/time_tracker/credits.htm
// +----------------------------------------------------------------------+
require_once('MDB2.php');

class ttAuditedChange
{
    private $tableName = "";
    public $mdb2;
    private $supportsTransactions;
    private $user;
    private $DELETE = "DELETE";
    private $INSERT = "INSERT";
    private $UPDATE = "UPDATE";

    function __construct($tableName, $user)
    {
        if (!isset($GLOBALS["_MDB2_CONNECTION"])) {

            $mdb2 = MDB2::connect(DSN);

            if (is_a($mdb2, 'PEAR_Error')) {
                die($mdb2->getMessage());
            }

            $mdb2->setFetchMode(MDB2_FETCHMODE_ASSOC);
            $GLOBALS["_MDB2_CONNECTION"] = $mdb2;

        }
        $this->mdb2 = $GLOBALS["_MDB2_CONNECTION"];
        $this->supportsTransactions = $this->mdb2->supports('transactions');
        $this->tableName = $tableName;
        $this->user = $user;
    }

    function insert($values)
    {
        return $this->execute($this->INSERT, $values, null);
    }

    function delete($keys)
    {
        return $this->execute($this->DELETE, null, $keys);
    }

    function update($values, $keys)
    {
        return $this->execute($this->UPDATE, $values, $keys);
    }

    function quote($value)
    {
        return $this->mdb2->quote($value);
    }

    function lastInsertID($tableName, $fieldName)
    {
        return $this->mdb2->lastInsertID($tableName, $fieldName);
    }

    private function execute($type, $values, $keys)
    {
        // TODO: add transaction
        $sql = $this->buildSql($type, $values, $keys);
        $currentVersion = null;
        if ($type == "UPDATE" || $type == "DELETE") {
            $currentVersion = $this->getCurrent($keys);
        }

        $res = $this->mdb2 . exec($sql);
        if (is_a($res, 'PEAR_Error')) {
            return false;
        }
        if ($this->user->isPluginEnabled('al')) {
            $this->addAudit($type, $values, $currentVersion, $keys);
        }

        return $res;
    }

    private function getCurrent($keysArray)
    {
        $query = "SELECT * FROM " . $this->tableName . " " . $this->buildCondition($keysArray);

        $res = $this->mdb2 . query($query);
        if (!is_a($res, 'PEAR_Error')) {
            return $res;
        } else {
            return false;
        }
    }

    private function buildSql($type, $values, $keys)
    {
        $sql = "$type";

        if ($type == "INSERT") {
            $sql .= " INTO $this->tableName (";
            $values = "values (";
            foreach ($values as $key => $value) {
                $sql .= "$key, ";
                $values .= "$value, ";
            }
            $sql = rtrim($sql, ", ");
            $values = rtrim($values, ", ");
            $sql .= ")" . $values . ")";
            return $sql;
        }

        if ($type == "UPDATE") {
            $sql .= " $this->tablenName SET";
            foreach ($values as $key => $value) {
                $sql .= "$key = $value, ";
            }
            $sql = rtrim($sql, ", ");
            $sql .= $this->buildCondition($keys);

            return $sql;
        }

        if ($type == "DELETE") {
            $sql .= " FROM $this->tableName" . $this->buildCondition($keys);

            return $sql;
        }

        return false;
    }

    private function buildCondition($keys)
    {
        $returnValue = " WHERE ";

        if (empty($keys)) {
            return "";
        }
        // allow user of this class to specify some other complex condition
        if (is_string($keys)) {
            $returnValue .= $keys;
            return $returnValue;
        }

        foreach ($keys as $key => $value) {
            $returnValue = "$key = $value AND ";
        }
        $returnValue = trim($sql, " AND ");

        return $returnValue;
    }

    private function addAudit($type, $values, $current, $keys)
    {
        // TODO check for array
        $new = null;
        $currentAsJson = null;

        switch ($type) {
            case $this->INSERT:
                $new = (object)[];
                $currentAsJson = null;
                break;
            case $this->UPDATE:
                $currentAsJson = serialize($current);
                $new = unserialize($currentAsJson);
                break;
            case $this->DELETE:
                $currentAsJson = serialize($current);
                $new = null;
                break;
        }

        if (!empty($values)) {
            foreach ($values as $key => $value) {
                $new->$key = $value;
            }
        }

        $newValueAsJson = serialize($new);

        $identity = null;

        if (!empty($keys)) {
            if (is_string($keys)) {
                $identity = $keys;
            } else {
                $identityObject = (object)[];
                foreach ($keys as $key => $value) {
                    $identityObject->$key = $value;
                }
                $identity = serialize($identityObject);
            }
        }

        $sql = "INSERT INTO tt_audit_log (user_id, state, table_name, old_json, new_json, identity, timestamp) values (" . $this->user->id . ", $type, $currentAsJson, $newValueAsJson, $identity, now());";
    }
}