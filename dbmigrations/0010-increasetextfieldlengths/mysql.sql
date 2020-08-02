ALTER TABLE TABLE_PREFIXcarddav_addressbooks MODIFY COLUMN `password` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE TABLE_PREFIXcarddav_addressbooks MODIFY COLUMN `url` VARCHAR(4095) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE TABLE_PREFIXcarddav_addressbooks MODIFY COLUMN `sync_token` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE TABLE_PREFIXcarddav_contacts MODIFY COLUMN `email` VARCHAR(4095) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- uri is part of UNIQUE index, it can have a max key length of 767 bytes
ALTER TABLE TABLE_PREFIXcarddav_contacts MODIFY COLUMN `uri` VARCHAR(700) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE TABLE_PREFIXcarddav_groups MODIFY COLUMN `vcard` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- uri is part of UNIQUE index, it can have a max key length of 767 bytes
ALTER TABLE TABLE_PREFIXcarddav_groups MODIFY COLUMN `uri` VARCHAR(700) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
