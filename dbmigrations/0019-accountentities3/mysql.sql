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

-- We need to drop the foreign key constraint for user_id first before we can drop the column.
-- As we do not know the name, we need to figure it out first.
SET @fk_name = NULL;

SELECT CONSTRAINT_NAME INTO @fk_name FROM information_schema.KEY_COLUMN_USAGE WHERE table_name='TABLE_PREFIXcarddav_addressbooks' AND column_name='user_id' AND referenced_table_name IS NOT NULL LIMIT 1;

SET @exec_query = IF(@fk_name IS NOT NULL,
    CONCAT('ALTER TABLE TABLE_PREFIXcarddav_addressbooks DROP FOREIGN KEY `', @fk_name, '`'),
    'SELECT "No FK found, skipping"');

PREPARE stmt FROM @exec_query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ALTER TABLE TABLE_PREFIXcarddav_addressbooks DROP FOREIGN KEY carddav_addressbooks_ibfk_1;
ALTER TABLE TABLE_PREFIXcarddav_addressbooks DROP user_id;
