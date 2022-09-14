-- start a transaction
BEGIN TRANSACTION;

-- add the flags column
ALTER TABLE TABLE_PREFIXcarddav_addressbooks ADD COLUMN flags integer NOT NULL DEFAULT 5;

-- fill the flags attributes from the current individual columns
UPDATE TABLE_PREFIXcarddav_addressbooks
	SET flags=(
		((active         != 0) << 0) |
		((use_categories != 0) << 1) |
		((discovered     != 0) << 2)
	);

-- drop the old flag columns
ALTER TABLE TABLE_PREFIXcarddav_addressbooks DROP COLUMN active;
ALTER TABLE TABLE_PREFIXcarddav_addressbooks DROP COLUMN use_categories;
ALTER TABLE TABLE_PREFIXcarddav_addressbooks DROP COLUMN discovered;

-- commit the transaction
COMMIT;
