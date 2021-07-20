-- Create accounts table
CREATE SEQUENCE IF NOT EXISTS TABLE_PREFIXcarddav_accounts_seq
	INCREMENT BY 1
	NO MAXVALUE
	MINVALUE 1
	CACHE 1;

CREATE TABLE IF NOT EXISTS TABLE_PREFIXcarddav_accounts (
	id integer DEFAULT nextval('TABLE_PREFIXcarddav_accounts_seq'::text) PRIMARY KEY,
	name VARCHAR(64) NOT NULL,
	username VARCHAR(255) NOT NULL,
	password TEXT NOT NULL,
	url TEXT, -- discovery URI, NULL means no discovery of addressbooks
	user_id integer NOT NULL REFERENCES TABLE_PREFIXusers (user_id) ON DELETE CASCADE ON UPDATE CASCADE,
	last_discovered BIGINT NOT NULL DEFAULT 0, -- time stamp (seconds since epoch) of the addressbooks were last discovered
	rediscover_time INT NOT NULL DEFAULT 86400, -- time span (seconds) after that the addressbooks will be rediscovered, default 1d

	presetname VARCHAR(255), -- presetname

	UNIQUE(user_id,presetname)
);

-- Add discovered column
ALTER TABLE TABLE_PREFIXcarddav_addressbooks ADD COLUMN IF NOT EXISTS discovered SMALLINT NOT NULL DEFAULT 1;

-- Add account_id column and index
ALTER TABLE TABLE_PREFIXcarddav_addressbooks ADD COLUMN IF NOT EXISTS account_id integer REFERENCES TABLE_PREFIXcarddav_accounts (id) ON DELETE CASCADE ON UPDATE CASCADE;
CREATE INDEX IF NOT EXISTS TABLE_PREFIXcarddav_addressbooks_account_id_idx ON TABLE_PREFIXcarddav_addressbooks(account_id);
