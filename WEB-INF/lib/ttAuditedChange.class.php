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

    /**
     * Inserts the object into database. Check parameter descriptions for usage     * 
     * @param array a pair of named keys and values which should corespond to database columns
     * @param string/array if supplied as a string, it should be the name of the autoincrement primary key. otherwise a named array of keys and values that corespond to primry key
     */
    function insert($values, $primaryKeys)
    {
        // if primary key is supplied as a string it is assumed it is
        // autoincrement. this should maybe be improved
        return $this->execute($this->INSERT, $values, $primaryKeys);
    }

    /**
     * Delets a row from database.
     * @param array an array of named keys and values, with which we can uniquely identify the row marked for deletion
     * 
     */
    function delete($keys)
    {
        return $this->execute($this->DELETE, null, $keys);
    }

    /**
     * Updates a row in the database
     * @param array an array of named keys and values, which should corespond to table columns
     * @param array an array of named keys and values, with which we can uniquely identify the row that is going to be updated
     */
    function update($values, $keys)
    {
        return $this->execute($this->UPDATE, $values, $keys);
    }

    /**
     * A shortcut for MDB2->quote
     * @param string value needed to be quoted
     */
    function quote($value)
    {
        return $this->mdb2->quote($value);
    }

    /**
     * A shortcut for MDB2->lastInsertID
     * @param string name of autoincremented field
     */
    function lastInsertID($fieldName)
    {
        return $this->mdb2->lastInsertID($this->tableName, $fieldName);
    }

    private function execute($type, $values, $keys)
    {
        // TODO: add transaction
        $sql = $this->buildSql($type, $values, $keys);
        
        $currentVersion = null;
        if ($type == "UPDATE" || $type == "DELETE") {
            $currentVersion = $this->getCurrent($keys);
        }

        $res = $this->mdb2->exec($sql);
        if (is_a($res, 'PEAR_Error')) {
            return false;
        }

        if ($this->user->isPluginEnabled('al')) {
            // if $keys is string, it means it is autoincrement primary key
            // so get it from DB
            // TODO: possible race condition problem. check. 
            if ($type == $this->INSERT && isset($keys) && is_string($keys)) {
                $recordId = $this->lastInsertID($this->tableName, $keys);
                $keys = array("$keys" => $recordId);
                $res = $recordId;
            }
            $this->addAudit($type, $values, $currentVersion, $keys);
        }

        return $res;
    }

    private function getCurrent($keysArray)
    {
        // get current recrod from DB.
        $query = "SELECT * FROM " . $this->tableName . " " . $this->buildCondition($keysArray);

        $res = $this->mdb2->query($query);
        if (!is_a($res, 'PEAR_Error')) {
            $val = $res->fetchRow();
            return (object)$val;
        } else {
            return false;
        }
    }

    private function buildSql($type, $values, $keys)
    {
        $sql = "$type";

        if ($type == "INSERT") {
            $sql .= " INTO $this->tableName (";
            $fieldValues = "values (";
            foreach ($values as $key => $value) {
                $sql .= "$key, ";

                $value = $this->getValue($value);
                $fieldValues .= "$value, ";
            }
            $sql = rtrim($sql, ", ");
            $fieldValues = rtrim($fieldValues, ", ");
            $sql .= ") $fieldValues)";
            return $sql;
        }

        if ($type == "UPDATE") {
            $sql .= " $this->tableName SET ";
            foreach ($values as $key => $value) {
                $val = $this->getValue($value);
                $sql .= "$key = $val, ";
            }
            $sql = rtrim($sql, ", ");
            $sql .= " " . $this->buildCondition($keys);

            return $sql;
        }

        if ($type == "DELETE") {
            $sql .= " FROM $this->tableName " . $this->buildCondition($keys);

            return $sql;
        }

        return false;
    }

    private function getValue($value)
    {
        if (!isset($value) || $value === "") {
            $value = "NULL";
        } else if (is_string($value)) {
            $value = $this->quote($value);
        }
        return $value;
    }

    private function buildCondition($keys)
    {
        $returnValue = " WHERE ";

        if (empty($keys)) {
            return "";
        }

        foreach ($keys as $key => $value) {
            $val = $this->getValue($value);
            $returnValue .= "$key = $val AND ";
        }
        $returnValue = trim($returnValue, " AND ");

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
                $currentAsJson = "NULL";
                break;
            case $this->UPDATE:
                $currentAsJson = serialize($current);
                $new = unserialize($currentAsJson);
                $currentAsJson = $this->quote(json_encode($current));
                break;
            case $this->DELETE:
                $currentAsJson = $this->quote(json_encode($current));
                $new = null;
                break;
        }

        if (!empty($values)) {
            foreach ($values as $key => $value) {
                $new->$key = $value;
            }
        }

        $newValueAsJson = $this->quote(json_encode($new));

        $identity = null;

        if (!empty($keys)) {

            $identityObject = (object)[];
            foreach ($keys as $key => $value) {
                $identityObject->$key = $this->getValue($value);
            }
            $identity = json_encode($identityObject);

            $identity = $this->quote($identity);
        } else {
            $identity = "NULL";
        }

        $type = $this->quote($type);
        $tableName = $this->quote($this->tableName);

        $sql = "INSERT INTO tt_audit_log (user_id, state, table_name, old_json, new_json, identity, timestamp) values (" . $this->user->id . ", $type, $tableName, $currentAsJson, $newValueAsJson, $identity, now());";

        // TODO: atm this is secondary, inserting records is more important. so just swallow any sql exceptions.
        // when transactions are implemented, return false
        $res = $this->mdb2->exec($sql);
        // if (is_a($res, 'PEAR_Error')) {
        //     return false;
        // }
    }
}