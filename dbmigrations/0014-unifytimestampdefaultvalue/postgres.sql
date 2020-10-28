UPDATE TABLE_PREFIXcarddav_addressbooks SET last_updated='1970-01-01 00:00:00+00' WHERE last_updated='-infinity';
ALTER TABLE TABLE_PREFIXcarddav_addressbooks ALTER COLUMN last_updated DROP DEFAULT;
ALTER TABLE TABLE_PREFIXcarddav_addressbooks ALTER COLUMN refresh_time DROP DEFAULT;
ALTER TABLE TABLE_PREFIXcarddav_addressbooks ALTER COLUMN last_updated TYPE BIGINT USING extract(epoch from last_updated);
ALTER TABLE TABLE_PREFIXcarddav_addressbooks ALTER COLUMN refresh_time TYPE INT USING extract(epoch from refresh_time);
ALTER TABLE TABLE_PREFIXcarddav_addressbooks ALTER COLUMN last_updated SET DEFAULT 0;
ALTER TABLE TABLE_PREFIXcarddav_addressbooks ALTER COLUMN refresh_time SET DEFAULT 3600;
