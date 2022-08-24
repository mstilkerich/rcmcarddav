-- For MySQL 5.6, we need to put the server in strict mode to add the NOT NULL constraint to a foreign key column
SET @SAVE_sql_mode = @@sql_mode;
SET @@sql_mode = 'STRICT_ALL_TABLES';

-- Set NOT NULL constraint on account_id column
ALTER TABLE TABLE_PREFIXcarddav_addressbooks MODIFY account_id INT(10) UNSIGNED NOT NULL;

-- Restore original sql mode
SET @@sql_mode = @SAVE_sql_mode;

-- Drop not needed columns from addressbooks table
ALTER TABLE TABLE_PREFIXcarddav_addressbooks DROP username;
ALTER TABLE TABLE_PREFIXcarddav_addressbooks DROP password;
ALTER TABLE TABLE_PREFIXcarddav_addressbooks DROP presetname;

-- The below constraint name is hard-coded for simplicity; it is auto-selected by MySQL
-- so in theory it might not have that name and the query would fail. However, I cannot
-- think of why the name would be different.
-- In case it is not, the actual name can be found via the following query for manual cleanup:
-- SELECT constraint_name from information_schema.key_column_usage where table_name='carddav_addressbooks' and column_name='user_id' and constraint_schema=DATABASE() and referenced_table_name='users' and referenced_column_name = 'user_id';
ALTER TABLE TABLE_PREFIXcarddav_addressbooks DROP FOREIGN KEY carddav_addressbooks_ibfk_1;
ALTER TABLE TABLE_PREFIXcarddav_addressbooks DROP user_id;
