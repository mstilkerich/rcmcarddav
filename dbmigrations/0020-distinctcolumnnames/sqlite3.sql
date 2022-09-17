-- Note: SQLite since version 3.25 supports RENAME COLUMN
-- We support versions starting with version 3.22, so we do not use this mechanism and instead create a copy of the
-- table with the modifications

-- disable foreign key constraint check
PRAGMA foreign_keys=off;

-- start a transaction
BEGIN TRANSACTION;

-- Here you can drop column or rename column
CREATE TABLE IF NOT EXISTS TABLE_PREFIXcarddav_accounts0020 (
	id              integer NOT NULL PRIMARY KEY,
	accountname     TEXT NOT NULL,
	username        TEXT NOT NULL,
	password        TEXT NOT NULL,
	discovery_url   TEXT,
	user_id         integer NOT NULL,
	last_discovered BIGINT NOT NULL DEFAULT 0,  -- time stamp (seconds since epoch) of the addressbooks were last discovered
	rediscover_time INT NOT NULL DEFAULT 86400, -- time span (seconds) after that the addressbooks will be rediscovered, default 1d

	presetname      TEXT,                       -- presetname

	UNIQUE(user_id,presetname),

	-- not enforced by sqlite < 3.6.19
	FOREIGN KEY(user_id) REFERENCES TABLE_PREFIXusers(user_id) ON DELETE CASCADE ON UPDATE CASCADE
);

-- copy data from the table to the new_table
INSERT INTO TABLE_PREFIXcarddav_accounts0020
SELECT id,name,username,password,url,user_id,last_discovered,rediscover_time,presetname
FROM TABLE_PREFIXcarddav_accounts;

-- drop the table
DROP TABLE TABLE_PREFIXcarddav_accounts;

-- rename the new_table to the table
ALTER TABLE TABLE_PREFIXcarddav_accounts0020 RENAME TO TABLE_PREFIXcarddav_accounts;

-- commit the transaction
COMMIT;

-- enable foreign key constraint check
PRAGMA foreign_keys=on;
