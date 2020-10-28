ALTER TABLE TABLE_PREFIXcarddav_addressbooks ALTER COLUMN last_updated SET DEFAULT TIMESTAMP WITH TIME ZONE '1970-01-01 00:00:00+00';
UPDATE TABLE_PREFIXcarddav_addressbooks SET last_updated='1970-01-01 00:00:00+00' WHERE last_updated='-infinity';


-- select name,extract(epoch from refresh_time) as ts,refresh_time,extract(epoch from last_updated) as ts_lu,last_updated from carddav_addressbooks;

-- ALTER TABLE TABLE_PREFIXcarddav_addressbooks ALTER COLUMN last_updated TYPE BIGINT USING extract(epoch from last_updated);
