-- disable foreign key constraint check
PRAGMA foreign_keys=off;

-- start a transaction
BEGIN TRANSACTION;

-- Here you can drop column or rename column
CREATE TABLE IF NOT EXISTS TABLE_PREFIXcarddav_migrations2 (
	id integer NOT NULL PRIMARY KEY,
	filename TEXT NOT NULL,
	processed_at TIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

	UNIQUE(filename)
);

-- copy data from the table to the new_table
INSERT INTO TABLE_PREFIXcarddav_migrations2
SELECT ID,filename,processed_at
FROM TABLE_PREFIXcarddav_migrations;

-- drop the table
DROP TABLE TABLE_PREFIXcarddav_migrations;

-- rename the new_table to the table
ALTER TABLE TABLE_PREFIXcarddav_migrations2 RENAME TO TABLE_PREFIXcarddav_migrations; 

-- commit the transaction
COMMIT;

-- enable foreign key constraint check
PRAGMA foreign_keys=on;

