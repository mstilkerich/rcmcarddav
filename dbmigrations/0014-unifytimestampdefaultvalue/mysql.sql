ALTER TABLE TABLE_PREFIXcarddav_addressbooks ADD `last_updated_ts` BIGINT NOT NULL DEFAULT 0 AFTER `last_updated`;
ALTER TABLE TABLE_PREFIXcarddav_addressbooks ADD `refresh_time_ts` INT NOT NULL DEFAULT 3600 AFTER `refresh_time`;

UPDATE TABLE_PREFIXcarddav_addressbooks SET `refresh_time_ts`=TIME_TO_SEC(`refresh_time`), `last_updated_ts`=UNIX_TIMESTAMP(`last_updated`);

ALTER TABLE TABLE_PREFIXcarddav_addressbooks DROP COLUMN `last_updated`;
ALTER TABLE TABLE_PREFIXcarddav_addressbooks DROP COLUMN `refresh_time`;

ALTER TABLE TABLE_PREFIXcarddav_addressbooks CHANGE COLUMN `last_updated_ts` `last_updated` BIGINT NOT NULL DEFAULT 0;
ALTER TABLE TABLE_PREFIXcarddav_addressbooks CHANGE COLUMN `refresh_time_ts` `refresh_time` INT NOT NULL DEFAULT 3600;
