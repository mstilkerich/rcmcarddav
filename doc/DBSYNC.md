# Synchronization of database access in RCMCardDAV

A core design idea of RCMCardDAV is that the local roundcube database functions as a cache for the data on the CardDAV
server only:
  - Synchronization between roundcube and the CardDAV server is one-way
  - Modification of address objects (contacts or groups) is always done at the CardDAV server directly. The database
    never holds data more current than that of the server. The changes are then synced back via regular sync to the
    local database.
  - Consequently, modifications to the address objects do not cause consistency issues with server synchronization, as
    they do not update anything in the database.

Nevertheless, there are several potentially racing operations that modify the database:
  - Synchronization of an addressbook with the server
  - Creation of a CATEGORY-type group in roundcube (see [GROUPS.md](GROUPS.md)).
  - Rename / delete of an _empty_ CATEGORY-type group in roundcube (see [GROUPS.md](GROUPS.md)).
  - Database schema migrations
  - Insert/change of an addressbook in roundcube
  - Deletion of an addressbook in roundcube

## Solution concept

At first, a simple solution is selected:
  - Use Serializable isolation level for read/write transactions.
  - Use Repeatable Read isolation level for read-only transactions.

Explicit transactions are only used for operations consisting of multiple queries. Otherwise, we assume they are
implicitly executed as a transaction because of autocommit mode. None of the databases defaults to READ UNCOMMITTED,
which is the only case where this would be an issue (of the supported DBs, only MySQL even supports READ UNCOMMITED
isolation level).

It ensures that reading operations see consistent data and write operations don't interfere.

It leaves some issues though:
  - Concurrent syncs will still be executed. They will likely fail and be rolled back because they cannot be serialized,
    but the expensive communication with the CardDAV server will already have happened by the time the conflicting DB
    queries are executed. It would be better to recognize conflicts early in the sync process and abort the sync
    immediately, without even talking to the CardDAV server.
  - Alternatively, the concurrent syncs may be blocked and have to wait until a transaction is finished. Again, if we
    have a sync in progress, we would rather not wait and simply continue skipping a second sync.
  - If a user edits a contact card in roundcube, and it is updated in the background during the edit, the changes will
    be overwritten as the user stores the card. It would not be detected by the ETag check, as the ETag in the database
    is up to date with the server, but the data shown in the edit form to the user was not. This is probably a rare
    issue, and arguably the card is stored on the server containing the data as submitted by the user.
