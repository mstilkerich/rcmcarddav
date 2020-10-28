ALTER TABLE TABLE_PREFIXcarddav_addressbooks MODIFY COLUMN `last_updated` TIMESTAMP NOT NULL DEFAULT '1970-01-02 00:00:00';
-- no update of old values needed, they continue to work to trigger the initial sync
