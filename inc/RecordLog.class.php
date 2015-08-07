<?php

class RecordComparator {

    public function has_changed(array $a, array $b, $append_zone = false) {
        if($append_zone) {
            $this->append_zone_name($b['zid'], $b, $a);
        }

        $this->unsetChangeDate($a);
        $this->unsetChangeDate($b);
        $this->trim_record_content($a);
        $this->trim_record_content($b);
        $this->makeCompatible($b);

        // Do the comparison
        return ($a != $b);
    }

    private function append_zone_name($zid, &$b, &$a) {
        $zone = get_zone_name_from_id($zid);

        if (!(preg_match("/$zone$/i", $b['name']))) {
            if (isset($b) && $b['name'] != "") {
                $b['name'] = $b["name"] . "." . $zone;
            } else {
                $b['name'] = $a["name"];
            }
        }
    }

    private function unsetChangeDate(&$record) {
        unset($record["change_date"]);
    }

    private function trim_record_content(&$record) {
        // Quotes are special for SPF and TXT
        if ($record['type'] == "SPF" || $record['type'] == "TXT") {
            $record['content'] = trim($record['content'], '"');
        }
    }

    private function makeCompatible(&$record) {
        // Make $a and $b compatible
        $record['id'] = $record['rid'];
        $record['domain_id'] = $record['zid'];
        unset($record['zid']);
        unset($record['rid']);
        unset($record['commit']);
    }
}

class RecordLog {

    private $db;
    private $record_prior;
    private $record_after;

    /**
     * @var RecordComparator
     */
    private $record_comparator;

    function __construct() {
        $this->record_comparator = new RecordComparator();
    }

    public static function with_db($db) {
        $instance = new RecordLog();
        $instance->set_database($db);
        return $instance;
    }

    public function log_prior($rid) {
        $this->record_prior = $this->getRecord($rid);
    }

    public function log_after($rid) {
        $this->record_after = $this->getRecord($rid);
    }

    private function getRecord($rid) {
        return get_record_from_id($rid);
    }

    private function getUser() {
        return $_SESSION['userlogin'];
    }

    private function getDate() {
        $localtime = new DateTime('now', new DateTimeZone('Europe/Berlin'));
        return $localtime->format('Y-m-d H:i:s');
    }

    private function getLogType($type_name) {
        global $db;
        return $db->queryOne("SELECT id FROM log_records_type WHERE name = '" . $type_name . "'");
    }

    private function log_records_data($record) {
        global $db;
        global $db_type;

        $query = "INSERT INTO log_records_data (domain_id, name, type, content, ttl, prio, change_date) VALUES ("
            . $db->quote($record['domain_id'], 'integer') . ","
            . $db->quote($record['name'], 'text') . ","
            . $db->quote($record['type'], 'text') . ","
            . $db->quote(trim($record['content'], '"'), 'text') . ","
            . $db->quote($record['ttl'], 'integer') . ","
            . $db->quote($record['prio'], 'integer') . ","
            . $db->quote($record['change_date'], 'integer') . ")";
        $result = $db->exec($query);

        if (PEAR::isError($result)) {
            error($result->getMessage());
            // Don't commit changes if we can't log them
            $db->rollback();
            return false;
        }

        return ($db_type == 'pgsql') ? $db->lastInsertId('log_records_data_id_seq') : $db->lastInsertId();
    }

    public function writeNew() {
        global $db;

        $after_id = $this->log_records_data($this->record_after);
        $record_type_id = $this->getLogType('record_create');
        $now = $this->getDate();
        $fullname = $this->getUser();

        // TODO: Log approving user (col                                                    v here)
        $log_insert_record = $db->prepare("INSERT INTO log_records (log_records_type_id, timestamp, username, after) VALUES (?, ?, ?, ?)");
        $result = $log_insert_record->execute(array($record_type_id, $now, $fullname, $after_id));

        if (PEAR::isError($log_insert_record)) {
            error($log_insert_record->getMessage());
            $db->rollback();
            return false;
        }
       
    }

    public function writeChange() {
        global $db;

        $prior_id = $this->log_records_data($this->record_prior);
        $after_id = $this->log_records_data($this->record_after);
        $record_type_id = $this->getLogType('record_edit');
        $now = $this->getDate();
        $user = $this->getUser();

        // TODO: Log approving user (col                                                    v here)
        $log_insert_record = "INSERT INTO log_records (log_records_type_id, timestamp, username, prior, after) VALUES ("
            . $db->quote($record_type_id, 'integer') . ","
            . $db->quote($now, 'text') . ","
            . $db->quote($user, 'text') . ","
            . $db->quote($prior_id, 'integer') . ","
            . $db->quote($after_id, 'integer') . ")";
        $result = $db->exec($log_insert_record);

        if (PEAR::isError($result)) {
             error($result->getMessage());
             // Don't commit changes if we can't log them
             $db->rollback();
             return false;
        }

    }

    public function writeDelete() {
        global $db;

        $prior_id = $this->log_records_data($this->record_prior);
        $record_type_id = $this->getLogType('record_delete');
        $now = $this->getDate();
        $user = $this->getUser();

        // TODO: Log approving user (col                                                    v here)
        $log_insert_record = "INSERT INTO log_records (log_records_type_id, timestamp, username, prior) VALUES ("
            . $db->quote($record_type_id, 'integer') . ","
            . $db->quote($now, 'text') . ","
            . $db->quote($user, 'text') . ","
            . $db->quote($prior_id, 'integer') . ")";
        $result = $db->exec($log_insert_record);

        if (PEAR::isError($result)) {
             error($result->getMessage());
             // Don't commit changes if we can't log them
             $db->rollback();
             return false;
        }
    }

    public function has_changed($record) {
        return $this->record_comparator->has_changed($this->record_prior, $record);
    }

    public function write_delete_all($domain_id) {
        $record_ids = $this->record_ids_for_domain($domain_id);
        foreach($record_ids as $record_id) {
            $this->record_prior = $this->getRecord($record_id);
            $this->writeDelete();
        }
    }

    private function set_database($db) {
        $this->db = $db;
    }

    private function record_ids_for_domain($domain_id) {
        $result = $this->db->query("SELECT id
                                     FROM records
                                     WHERE domain_id = " . $this->db->quote($domain_id, 'integer'));
        $record_ids = array();
        while($record = $result->fetchRow()) {
            $record_ids[] = $record['id'];
        }
        return $record_ids;
    }
}
