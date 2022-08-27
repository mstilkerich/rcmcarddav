-- Set NOT NULL constraint on account_id column
ALTER TABLE TABLE_PREFIXcarddav_addressbooks ALTER COLUMN account_id SET NOT NULL;

-- Drop not needed columns and indexes from addressbooks table
DROP INDEX IF EXISTS TABLE_PREFIXcarddav_addressbooks_user_id_idx;
ALTER TABLE TABLE_PREFIXcarddav_addressbooks DROP COLUMN IF EXISTS user_id;
ALTER TABLE TABLE_PREFIXcarddav_addressbooks DROP COLUMN IF EXISTS username;
ALTER TABLE TABLE_PREFIXcarddav_addressbooks DROP COLUMN IF EXISTS password;
ALTER TABLE TABLE_PREFIXcarddav_addressbooks DROP COLUMN IF EXISTS presetname;

