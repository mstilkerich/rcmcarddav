The id column in the carddav\_migrations table was named uppercase, inconsistently with all other tables' id fields
which are lowercase. However, on postgres it was actually already lowercase, as it is not quoted in the create table
statement and postgres lowercases all unquoted identifiers. This leaves us with inconsistent naming across different
databases.

Since we use lowercase in all other places, this migration changes MySQL and SQLite to lowercase as well.
