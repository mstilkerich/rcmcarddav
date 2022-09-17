ALTER TABLE TABLE_PREFIXcarddav_accounts CHANGE `name` `accountname` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;
ALTER TABLE TABLE_PREFIXcarddav_accounts CHANGE `url` `discovery_url` VARCHAR(4095) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin;
