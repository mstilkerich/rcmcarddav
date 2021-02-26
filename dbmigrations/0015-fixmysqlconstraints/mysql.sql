-- enable NOT NULL constraint on sync_token column and migrate all NULL values to empty strings
UPDATE TABLE_PREFIXcarddav_addressbooks set `sync_token`='' WHERE `sync_token` IS NULL;
ALTER TABLE TABLE_PREFIXcarddav_addressbooks CHANGE `sync_token` `sync_token` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;
