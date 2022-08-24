-- disable foreign key constraint check
PRAGMA foreign_keys=off;

-- start a transaction
BEGIN TRANSACTION;

-- Here you can drop column or rename column
CREATE TABLE IF NOT EXISTS TABLE_PREFIXcarddav_addressbooks2 (
	id           integer NOT NULL PRIMARY KEY,
	name         TEXT NOT NULL,
	url          TEXT NOT NULL,
	active       TINYINT UNSIGNED NOT NULL DEFAULT 1,
	last_updated BIGINT NOT NULL DEFAULT 0,  -- time stamp (seconds since epoch) of the last update of the local database
	refresh_time INT NOT NULL DEFAULT 3600, -- time span (seconds) after that the local database will be refreshed, default 1h
	sync_token   TEXT NOT NULL DEFAULT '', -- sync-token the server sent us for the last sync

	use_categories TINYINT NOT NULL DEFAULT 0,
	discovered   TINYINT NOT NULL DEFAULT 1,

	account_id   integer NOT NULL,

	-- not enforced by sqlite < 3.6.19
	FOREIGN KEY(account_id) REFERENCES TABLE_PREFIXcarddav_accounts(id) ON DELETE CASCADE ON UPDATE CASCADE
);

-- copy data from the table to the new_table
INSERT INTO TABLE_PREFIXcarddav_addressbooks2
SELECT id,name,url,active,last_updated,refresh_time,sync_token,use_categories,discovered,account_id
FROM TABLE_PREFIXcarddav_addressbooks;

-- drop the table
DROP TABLE TABLE_PREFIXcarddav_addressbooks;

-- rename the new_table to the table
ALTER TABLE TABLE_PREFIXcarddav_addressbooks2 RENAME TO TABLE_PREFIXcarddav_addressbooks;
CREATE INDEX TABLE_PREFIXcarddav_addressbooks_account_id_idx ON TABLE_PREFIXcarddav_addressbooks(account_id);

-- commit the transaction
COMMIT;

-- enable foreign key constraint check
PRAGMA foreign_keys=on;

