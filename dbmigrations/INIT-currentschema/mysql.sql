-- table to store the configured accounts
CREATE TABLE IF NOT EXISTS TABLE_PREFIXcarddav_accounts (
	id INT UNSIGNED NOT NULL AUTO_INCREMENT,
	accountname TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
	username VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
	password TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
	discovery_url VARCHAR(4095) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
	user_id INT(10) UNSIGNED NOT NULL,
	last_discovered BIGINT NOT NULL DEFAULT 0, -- time stamp (seconds since epoch) of the addressbooks were last discovered
	rediscover_time INT NOT NULL DEFAULT 86400, -- time span (seconds) after that the addressbooks will be rediscovered, default 1d

	presetname VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin, -- presetname

	PRIMARY KEY(id),
	UNIQUE INDEX(user_id,presetname),
	FOREIGN KEY (user_id) REFERENCES TABLE_PREFIXusers(user_id) ON DELETE CASCADE ON UPDATE CASCADE
) ROW_FORMAT=DYNAMIC ENGINE = InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_bin;

-- table to store the configured address books
CREATE TABLE IF NOT EXISTS TABLE_PREFIXcarddav_addressbooks (
	id INT UNSIGNED NOT NULL AUTO_INCREMENT,
	name TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
	url VARCHAR(4095) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
	flags SMALLINT UNSIGNED NOT NULL DEFAULT 5, -- default: discovered:2 | active:0
	last_updated BIGINT NOT NULL DEFAULT 0, -- time stamp (seconds since epoch) of the last update of the local database
	refresh_time INT NOT NULL DEFAULT 3600, -- time span (seconds) after that the local database will be refreshed, default 1h
	sync_token TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, -- sync-token the server sent us for the last sync

	account_id INT(10) UNSIGNED NOT NULL,

	PRIMARY KEY(id),
	-- we need to provide an explicit name for the constraint, so the migrated schema diffs equally to the initial one
	-- otherwise, the migrated schema uses carddav_addressbooks_ibfk_2 (because carddav_addressbooks_ibfk_1 is still
	-- used for the user_id foreign key in migration 0016), whereas the initial schema uses carddav_addressbooks_ibfk_1
	CONSTRAINT carddav_addressbooks_ibfk_account_id FOREIGN KEY (account_id) REFERENCES TABLE_PREFIXcarddav_accounts(id) ON DELETE CASCADE ON UPDATE CASCADE
) ROW_FORMAT=DYNAMIC ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_bin;

CREATE TABLE IF NOT EXISTS TABLE_PREFIXcarddav_contacts (
	id INT UNSIGNED NOT NULL AUTO_INCREMENT,
	abook_id INT UNSIGNED NOT NULL,
	name VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, -- display name
	email VARCHAR(4095) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin, -- ", " separated list of mail addresses
	firstname VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
	surname VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
	organization VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
	showas VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '', -- special display type (e.g., as a company)
	vcard LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,    -- complete vcard
	etag VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, -- entity tag, can be used to check if card changed on server
	uri  VARCHAR(700) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, -- path of the card on the server
	cuid VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, -- unique identifier of the card within the collection

	PRIMARY KEY(id),
	INDEX (abook_id),
	UNIQUE INDEX(uri,abook_id),
	UNIQUE INDEX(cuid,abook_id),
	FOREIGN KEY (abook_id) REFERENCES TABLE_PREFIXcarddav_addressbooks(id) ON DELETE CASCADE ON UPDATE CASCADE
) ROW_FORMAT=DYNAMIC ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_bin;

CREATE TABLE IF NOT EXISTS TABLE_PREFIXcarddav_xsubtypes (
	id INT UNSIGNED NOT NULL AUTO_INCREMENT,
	typename VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,  -- name of the type
	subtype  VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,  -- name of the subtype
	abook_id INT UNSIGNED NOT NULL,
	PRIMARY KEY(id),
	UNIQUE INDEX(typename,subtype,abook_id),
	FOREIGN KEY (abook_id) REFERENCES TABLE_PREFIXcarddav_addressbooks(id) ON DELETE CASCADE ON UPDATE CASCADE
) ROW_FORMAT=DYNAMIC ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_bin;

CREATE TABLE IF NOT EXISTS TABLE_PREFIXcarddav_groups (
	id INT UNSIGNED NOT NULL AUTO_INCREMENT,
	abook_id INT UNSIGNED NOT NULL,
	name VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,     -- display name
	vcard LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,    -- complete vcard
	etag VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL, -- entity tag, can be used to check if card changed on server
	uri  VARCHAR(700) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL, -- path of the card on the server
	cuid VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL, -- unique identifier of the card within the collection

	PRIMARY KEY(id),
	UNIQUE INDEX(uri,abook_id),
	UNIQUE INDEX(cuid,abook_id),

	FOREIGN KEY (abook_id) REFERENCES TABLE_PREFIXcarddav_addressbooks(id) ON DELETE CASCADE ON UPDATE CASCADE
) ROW_FORMAT=DYNAMIC ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_bin;

CREATE TABLE IF NOT EXISTS TABLE_PREFIXcarddav_group_user (
	group_id   INT UNSIGNED NOT NULL,
	contact_id INT UNSIGNED NOT NULL,

	PRIMARY KEY(group_id,contact_id),
	FOREIGN KEY(group_id) REFERENCES TABLE_PREFIXcarddav_groups(id) ON DELETE CASCADE ON UPDATE CASCADE,
	FOREIGN KEY(contact_id) REFERENCES TABLE_PREFIXcarddav_contacts(id) ON DELETE CASCADE ON UPDATE CASCADE
) ROW_FORMAT=DYNAMIC ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_bin;

CREATE TABLE IF NOT EXISTS TABLE_PREFIXcarddav_migrations (
	`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
	`filename` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
	`processed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	UNIQUE INDEX(`filename`)
) ROW_FORMAT=DYNAMIC ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_bin;
