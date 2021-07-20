-- Create accounts table
CREATE TABLE IF NOT EXISTS TABLE_PREFIXcarddav_accounts (
	id INT UNSIGNED NOT NULL AUTO_INCREMENT,
	name VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
	username VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
	password TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
	url VARCHAR(4095) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
	user_id INT(10) UNSIGNED NOT NULL,
	last_discovered BIGINT NOT NULL DEFAULT 0, -- time stamp (seconds since epoch) of the addressbooks were last discovered
	rediscover_time INT NOT NULL DEFAULT 86400, -- time span (seconds) after that the addressbooks will be rediscovered, default 1d

	presetname VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin, -- presetname

	PRIMARY KEY(id),
	FOREIGN KEY (user_id) REFERENCES TABLE_PREFIXusers(user_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_bin;

-- Add discovered column
ALTER TABLE TABLE_PREFIXcarddav_addressbooks ADD `discovered` INT NOT NULL DEFAULT '1' AFTER `use_categories`;

-- Add account_id column and index
ALTER TABLE TABLE_PREFIXcarddav_addressbooks ADD `account_id` INT(10) UNSIGNED AFTER `discovered`;
ALTER TABLE TABLE_PREFIXcarddav_addressbooks ADD CONSTRAINT carddav_addressbooks_ibfk_account_id FOREIGN KEY (account_id) REFERENCES TABLE_PREFIXcarddav_accounts(id) ON DELETE CASCADE ON UPDATE CASCADE;

