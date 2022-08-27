-- Create accounts table
CREATE TABLE IF NOT EXISTS TABLE_PREFIXcarddav_accounts (
	id           integer NOT NULL PRIMARY KEY,
	name         TEXT NOT NULL,
	username     TEXT NOT NULL,
	password     TEXT NOT NULL,
	url          TEXT,
	user_id      integer NOT NULL,
	last_discovered BIGINT NOT NULL DEFAULT 0,  -- time stamp (seconds since epoch) of the addressbooks were last discovered
	rediscover_time INT NOT NULL DEFAULT 86400, -- time span (seconds) after that the addressbooks will be rediscovered, default 1d

	presetname   TEXT,                  -- presetname

	UNIQUE(user_id,presetname),

	-- not enforced by sqlite < 3.6.19
	FOREIGN KEY(user_id) REFERENCES TABLE_PREFIXusers(user_id) ON DELETE CASCADE ON UPDATE CASCADE
);

-- Add discovered column
ALTER TABLE TABLE_PREFIXcarddav_addressbooks ADD discovered TINYINT NOT NULL DEFAULT 1;

-- Add account_id column and index
ALTER TABLE TABLE_PREFIXcarddav_addressbooks ADD account_id integer REFERENCES TABLE_PREFIXcarddav_accounts(id) ON DELETE CASCADE ON UPDATE CASCADE;
