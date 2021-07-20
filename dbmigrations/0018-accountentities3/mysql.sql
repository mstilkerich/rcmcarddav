-- Set NOT NULL constraint on account_id column
ALTER TABLE TABLE_PREFIXcarddav_addressbooks MODIFY account_id INT(10) UNSIGNED NOT NULL;

-- Drop not needed columns from addressbooks table
ALTER TABLE TABLE_PREFIXcarddav_addressbooks DROP username;
ALTER TABLE TABLE_PREFIXcarddav_addressbooks DROP password;
ALTER TABLE TABLE_PREFIXcarddav_addressbooks DROP presetname;

-- Drop the user_id column including its foreign key constraint; this requires fiddling out
-- the constraint name in MySQL. The following is thankfully taken from
-- https://stackoverflow.com/a/46541719

SET @constraint_name = (
    SELECT constraint_name
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE TABLE_NAME = 'TABLE_PREFIXcarddav_addressbooks'
        AND COLUMN_NAME = 'user_id'
        AND CONSTRAINT_SCHEMA = DATABASE()
        AND referenced_table_name = 'TABLE_PREFIXusers'
        AND referenced_column_name = 'user_id');

SET @s = concat('alter table TABLE_PREFIXcarddav_addressbooks drop foreign key ', @constraint_name);
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE TABLE_PREFIXcarddav_addressbooks DROP user_id;
