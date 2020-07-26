-- Drop the UNIQUE constraint on (user_id,presetname) in addressbooks table
ALTER TABLE TABLE_PREFIXcarddav_addressbooks DROP INDEX `user_id`, ADD INDEX `user_id` (`user_id`) USING BTREE;
