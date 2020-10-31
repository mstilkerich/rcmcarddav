-- disable foreign key constraint check
PRAGMA foreign_keys=off;

-- start a transaction
BEGIN TRANSACTION;

-- Here you can drop column or rename column
CREATE TABLE IF NOT EXISTS TABLE_PREFIXcarddav_addressbooks2 (
	id           integer NOT NULL PRIMARY KEY,
	name         TEXT NOT NULL,
	username     TEXT NOT NULL,
	password     TEXT NOT NULL,
	url          TEXT NOT NULL,
	active       TINYINT UNSIGNED NOT NULL DEFAULT 1,
	user_id      integer NOT NULL,
	last_updated BIGINT NOT NULL DEFAULT 0,  -- time stamp (seconds since epoch) of the last update of the local database
	refresh_time INT NOT NULL DEFAULT 3600, -- time span (seconds) after that the local database will be refreshed, default 1h
	sync_token   TEXT NOT NULL DEFAULT '', -- sync-token the server sent us for the last sync

	presetname   TEXT,                  -- presetname

	use_categories TINYINT NOT NULL DEFAULT 0,

	-- not enforced by sqlite < 3.6.19
	FOREIGN KEY(user_id) REFERENCES TABLE_PREFIXusers(user_id) ON DELETE CASCADE ON UPDATE CASCADE
);

-- copy data from the table to the new_table
INSERT INTO TABLE_PREFIXcarddav_addressbooks2
SELECT id,name,username,password,url,active,user_id,last_updated,refresh_time,sync_token,presetname,use_categories
FROM TABLE_PREFIXcarddav_addressbooks;

UPDATE TABLE_PREFIXcarddav_addressbooks2 SET last_updated='1970-01-01 00:00:00' WHERE last_updated=0;
UPDATE TABLE_PREFIXcarddav_addressbooks2 SET last_updated=strftime('%s', last_updated);
UPDATE TABLE_PREFIXcarddav_addressbooks2 SET refresh_time=strftime('%s', '1970-01-01 ' || refresh_time);

-- drop the table
DROP TABLE TABLE_PREFIXcarddav_addressbooks;

-- rename the new_table to the table
ALTER TABLE TABLE_PREFIXcarddav_addressbooks2 RENAME TO TABLE_PREFIXcarddav_addressbooks; 

-- commit the transaction
COMMIT;

-- enable foreign key constraint check
PRAGMA foreign_keys=on;

