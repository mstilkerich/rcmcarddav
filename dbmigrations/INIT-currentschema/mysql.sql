-- table to store the configured address books
CREATE TABLE IF NOT EXISTS TABLE_PREFIXcarddav_addressbooks (
	id INT UNSIGNED NOT NULL AUTO_INCREMENT,
	name VARCHAR(64) NOT NULL,
	username VARCHAR(255) NOT NULL,
	password TEXT NOT NULL,
	url VARCHAR(4095) NOT NULL,
	active TINYINT UNSIGNED NOT NULL DEFAULT 1,
	user_id INT(10) UNSIGNED NOT NULL,
	last_updated TIMESTAMP NOT NULL DEFAULT '1970-01-02 00:00:00', -- time stamp of the last update of the local database (this is in the local timezone, we cannot specify UTC -> therefore, we add one day to the epoch)
	refresh_time TIME NOT NULL DEFAULT '01:00:00', -- time span after that the local database will be refreshed, default 1h
	sync_token TEXT, -- sync-token the server sent us for the last sync

	presetname   VARCHAR(255), -- presetname
	use_categories INT NOT NULL DEFAULT '0',

	PRIMARY KEY(id),
	KEY `user_id` (`user_id`) USING BTREE,
	FOREIGN KEY (user_id) REFERENCES TABLE_PREFIXusers(user_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_bin;

CREATE TABLE IF NOT EXISTS TABLE_PREFIXcarddav_contacts (
	id INT UNSIGNED NOT NULL AUTO_INCREMENT,
	abook_id INT UNSIGNED NOT NULL,
	name VARCHAR(255)     NOT NULL, -- display name
	email VARCHAR(4095), -- ", " separated list of mail addresses
	firstname VARCHAR(255),
	surname VARCHAR(255),
	organization VARCHAR(255),
	showas VARCHAR(32) NOT NULL DEFAULT '', -- special display type (e.g., as a company)
	vcard LONGTEXT NOT NULL,    -- complete vcard
	etag VARCHAR(255) NOT NULL, -- entity tag, can be used to check if card changed on server
	uri  VARCHAR(700) NOT NULL, -- path of the card on the server
	cuid VARCHAR(255) NOT NULL, -- unique identifier of the card within the collection

	PRIMARY KEY(id),
	INDEX (abook_id),
	UNIQUE INDEX(uri,abook_id),
	UNIQUE INDEX(cuid,abook_id),
	FOREIGN KEY (abook_id) REFERENCES TABLE_PREFIXcarddav_addressbooks(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_bin;

CREATE TABLE IF NOT EXISTS TABLE_PREFIXcarddav_xsubtypes (
	id INT UNSIGNED NOT NULL AUTO_INCREMENT,
	typename VARCHAR(128) NOT NULL,  -- name of the type
	subtype  VARCHAR(128) NOT NULL,  -- name of the subtype
	abook_id INT UNSIGNED NOT NULL,
	PRIMARY KEY(id),
	UNIQUE INDEX(typename,subtype,abook_id),
	FOREIGN KEY (abook_id) REFERENCES TABLE_PREFIXcarddav_addressbooks(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_bin;

CREATE TABLE IF NOT EXISTS TABLE_PREFIXcarddav_groups (
	id INT UNSIGNED NOT NULL AUTO_INCREMENT,
	abook_id INT UNSIGNED NOT NULL,
	name VARCHAR(255) NOT NULL,     -- display name
	vcard LONGTEXT DEFAULT NULL,    -- complete vcard
	etag VARCHAR(255) DEFAULT NULL, -- entity tag, can be used to check if card changed on server
	uri  VARCHAR(700) DEFAULT NULL, -- path of the card on the server
	cuid VARCHAR(255) DEFAULT NULL, -- unique identifier of the card within the collection

	PRIMARY KEY(id),
	UNIQUE INDEX(uri,abook_id),
	UNIQUE INDEX(cuid,abook_id),

	FOREIGN KEY (abook_id) REFERENCES TABLE_PREFIXcarddav_addressbooks(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_bin;

CREATE TABLE IF NOT EXISTS TABLE_PREFIXcarddav_group_user (
	group_id   INT UNSIGNED NOT NULL,
	contact_id INT UNSIGNED NOT NULL,

	PRIMARY KEY(group_id,contact_id),
	FOREIGN KEY(group_id) REFERENCES TABLE_PREFIXcarddav_groups(id) ON DELETE CASCADE ON UPDATE CASCADE,
	FOREIGN KEY(contact_id) REFERENCES TABLE_PREFIXcarddav_contacts(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_bin;

CREATE TABLE IF NOT EXISTS TABLE_PREFIXcarddav_migrations (
	`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
	`filename` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
	`processed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	UNIQUE INDEX(`filename`)
) ENGINE = InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_bin;
