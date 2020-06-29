-- disable foreign key constraint check
PRAGMA foreign_keys=off;

-- start a transaction
BEGIN TRANSACTION;

-- Here you can drop column or rename column
CREATE TABLE IF NOT EXISTS TABLE_PREFIXcarddav_groups2 (
	id       integer NOT NULL PRIMARY KEY,
	abook_id integer NOT NULL,
	name VARCHAR(255) NOT NULL, -- display name
	vcard TEXT,        -- complete vcard
	etag VARCHAR(255), -- entity tag, can be used to check if card changed on server
	uri  VARCHAR(255), -- path of the card on the server
	cuid VARCHAR(255), -- unique identifier of the card within the collection
	
	UNIQUE(uri,abook_id),
	UNIQUE(cuid,abook_id),

	-- not enforced by sqlite < 3.6.19
	FOREIGN KEY(abook_id) REFERENCES TABLE_PREFIXcarddav_addressbooks(id) ON DELETE CASCADE ON UPDATE CASCADE
);

-- copy data from the table to the new_table
INSERT INTO TABLE_PREFIXcarddav_groups2 (id,abook_id,name,vcard,etag,uri,cuid)
SELECT id,abook_id,name,vcard,etag,uri,cuid
FROM TABLE_PREFIXcarddav_groups;

UPDATE TABLE_PREFIXcarddav_groups2 SET vcard=NULL,etag=NULL,uri=NULL,cuid=NULL WHERE etag LIKE 'dummy%';

-- drop the table
DROP TABLE TABLE_PREFIXcarddav_groups;

-- rename the new_table to the table
ALTER TABLE TABLE_PREFIXcarddav_groups2 RENAME TO TABLE_PREFIXcarddav_groups; 

-- commit the transaction
COMMIT;

-- enable foreign key constraint check
PRAGMA foreign_keys=on;
