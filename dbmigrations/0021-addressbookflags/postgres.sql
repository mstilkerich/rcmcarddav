-- fill the flags attributes from the current individual columns
-- We rename the existing active column to flags
UPDATE TABLE_PREFIXcarddav_addressbooks
	SET active=(
		(CAST((active         != 0) as INT) << 0) |
		(CAST((use_categories != 0) as INT) << 1) |
		(CAST((discovered     != 0) as INT) << 2)
	);

-- Rename active column and adjust default value
ALTER TABLE TABLE_PREFIXcarddav_addressbooks RENAME COLUMN active TO flags;
ALTER TABLE TABLE_PREFIXcarddav_addressbooks ALTER COLUMN flags SET DEFAULT 5;

-- drop the old flag columns
ALTER TABLE TABLE_PREFIXcarddav_addressbooks DROP COLUMN IF EXISTS use_categories;
ALTER TABLE TABLE_PREFIXcarddav_addressbooks DROP COLUMN IF EXISTS discovered;
