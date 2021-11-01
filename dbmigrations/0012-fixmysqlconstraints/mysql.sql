/*
 * Convert the ROW_FORMAT to DYNAMIC. This, in combination with some other settings, which
 * are nowadays default values (from MySQL 5.7.9, Maria DB 10.2.2), allows for index prefixes
 * up to 3072 bytes. The compact format only allows up to 767 bytes.
 *
 * If the 767 limit applies, the UNIQUE indexes re-created below containing URI fields
 * in their key could theoretically exceed the limit, and thus MySQL will fail with an
 * error.
 *
 * See https://github.com/mstilkerich/rcmcarddav/issues/362
 */
ALTER TABLE TABLE_PREFIXcarddav_addressbooks ROW_FORMAT=DYNAMIC;
ALTER TABLE TABLE_PREFIXcarddav_contacts ROW_FORMAT=DYNAMIC;
ALTER TABLE TABLE_PREFIXcarddav_xsubtypes ROW_FORMAT=DYNAMIC;
ALTER TABLE TABLE_PREFIXcarddav_groups ROW_FORMAT=DYNAMIC;
ALTER TABLE TABLE_PREFIXcarddav_group_user ROW_FORMAT=DYNAMIC;
ALTER TABLE TABLE_PREFIXcarddav_migrations ROW_FORMAT=DYNAMIC;


ALTER TABLE TABLE_PREFIXcarddav_addressbooks MODIFY `name` VARCHAR(64) NOT NULL;
ALTER TABLE TABLE_PREFIXcarddav_addressbooks MODIFY `username` VARCHAR(255) NOT NULL;
ALTER TABLE TABLE_PREFIXcarddav_addressbooks MODIFY `password` TEXT NOT NULL;
ALTER TABLE TABLE_PREFIXcarddav_addressbooks MODIFY `url` VARCHAR(4095) NOT NULL;

ALTER TABLE TABLE_PREFIXcarddav_contacts MODIFY `name` VARCHAR(255) NOT NULL;
ALTER TABLE TABLE_PREFIXcarddav_contacts MODIFY `showas` VARCHAR(32) NOT NULL DEFAULT '';
ALTER TABLE TABLE_PREFIXcarddav_contacts MODIFY `vcard` LONGTEXT NOT NULL;
ALTER TABLE TABLE_PREFIXcarddav_contacts MODIFY `etag` VARCHAR(255) NOT NULL;
ALTER TABLE TABLE_PREFIXcarddav_contacts MODIFY `uri` VARCHAR(700) NOT NULL;
ALTER TABLE TABLE_PREFIXcarddav_contacts MODIFY `cuid` VARCHAR(255) NOT NULL;
ALTER TABLE TABLE_PREFIXcarddav_contacts DROP INDEX `uri`, ADD UNIQUE INDEX(uri, abook_id);
ALTER TABLE TABLE_PREFIXcarddav_contacts DROP INDEX `cuid`, ADD UNIQUE INDEX(cuid, abook_id);

ALTER TABLE TABLE_PREFIXcarddav_groups MODIFY `name` VARCHAR(255) NOT NULL;
ALTER TABLE TABLE_PREFIXcarddav_groups MODIFY COLUMN `uri` VARCHAR(700);
ALTER TABLE TABLE_PREFIXcarddav_groups DROP INDEX `uri`, ADD UNIQUE INDEX(uri, abook_id);
ALTER TABLE TABLE_PREFIXcarddav_groups DROP INDEX `cuid`, ADD UNIQUE INDEX(cuid, abook_id);

ALTER TABLE TABLE_PREFIXcarddav_xsubtypes MODIFY `typename` VARCHAR(128) NOT NULL;
ALTER TABLE TABLE_PREFIXcarddav_xsubtypes MODIFY `subtype` VARCHAR(128) NOT NULL;

ALTER TABLE TABLE_PREFIXcarddav_migrations MODIFY `filename` VARCHAR(64) NOT NULL;

OPTIMIZE TABLE TABLE_PREFIXcarddav_contacts;
OPTIMIZE TABLE TABLE_PREFIXcarddav_groups;
