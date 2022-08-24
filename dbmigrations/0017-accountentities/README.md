This migration creates accounts as first-class entities in the database and RCMCardDAV. Having accounts as entities is
the basis for a periodic rediscovery of addressbook and includes the ability to support accounts with no addressbooks at
all.

The migration requires several steps, which are implemented as subsequent migration scripts starting with this one:

- 0016:
    - Creates the `carddav_accounts` table.
    - Adds a column `account_id` to `carddav_addressbooks` with foreign key constraint. For the duration of the
    migration, the value may be null.
    - Adds the new `discovered` column to the `carddav_addressbooks` table.

- 0017: Initializes the `carddav_accounts` table from accounts extracted from the `carddav_addressbooks` table and sets
  the `account_id` column to refer to the linked account.

- 0018:
    - Sets a `NOT NULL` constraint on `account_id` column
    - Drops no longer needed columns and indexes from the `carddav_addressbooks` table

<!-- vim: set ts=4 sw=4 expandtab fenc=utf8 ff=unix tw=120: -->
