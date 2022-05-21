-- VARCHAR makes no difference on SQLite, it does not honor the constraints anyway.
-- Not changing it, as it would require recreating and copying all the databases to essentially change nothing
SELECT ID FROM TABLE_PREFIXcarddav_migrations; -- No migration
