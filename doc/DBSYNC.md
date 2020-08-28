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
  - Creation of a CATEGORIES-type group in roundcube (see [GROUPS.md](GROUPS.md)).
  - Rename / delete of an _empty_ CATEGORIES-type group in roundcube (see [GROUPS.md](GROUPS.md)).
  - Database schema migrations
  - Insert/change of an addressbook in roundcube
  - Deletion of an addressbook in roundcube

## Solution concept

As a first attempt, I opted for a simple solution that is based on the standard SQL transaction mechanism, using
_Serializable_ transaction isolation for read/write transactions and _Repeatable Read_ isolation level for read-only
transactions that require several queries.

I also considered using specialized queries such as `SELECT FOR UPDATE`, which is particularly interesing in combination
with the `NOWAIT` flags that allow to skip racing operations instead of blocking - ideal for racing sync operations.
However, these statements are specific to different DBs, and more importantly, the `NOWAIT` option is not available in
all supported database versions (particularly MySQL 5.7). So for now, I decided to go with the portable solution,
although it may involve blocking operations, and potential rollbacks after having done expensive work involving
communication with a CardDAV server. If this turns out to be a problem, I will reconsider.

Explicit transactions are only used for operations consisting of multiple queries. Otherwise, we assume they are
implicitly executed as a transaction because of autocommit mode. None of the databases defaults to _Read Uncommitted_,
which is the only case where this would be an issue (of the supported DBs, only MySQL even supports _Read Uncommitted_
isolation level). SQLite only supports _Serializable_ isolation level, which is fine as it is the one with the strongest
guarantees.

I tried to choose transactions to correspond to small semantic changes to the addressbook state as possible. For
example, when a contact is updated, the update of the contact data is a separate transaction from the update of the
contact's group memberships in CATEGORIES-type groups. This is to reduce the risk of deadlock situations, and the
duration of blocking times (as opposed for considering the entire sync operation a transaction, which I went for first).
In case of errors, the operation is not tried again, but a sync continues and may perform other such changes. It is
theoretically possible that some changes would not make their way to the database because of this, but in the common
case of racing resyncs, it is likely that the concurrent sync would get through with performing the operation. I chose
this for the lesser evil for now. Given that our DB is just a cache, missed changes could repaired any time by resyncing
the addressbook, and the cases would even self-heal by the next time the contact is updated.

To reduce the risk of racing re-sync processes (and consequently likely blocking requests), a time-triggered sync will
now set the time of last update such that the next sync would be due in 5 minutes _before_ starting the sync. Upon
completion of the sync, it is set to the current time. This avoids that for these 5 minutes, parallel requests start a
concurrent sync process. It is the most common situation of concurrency, as it happens when the user notices that the
page loading is blocked (lengthy sync is ongoing), and clicks again, triggering another resync (which would normally
block again, but now instead the sync is skipped).

Another potential issue: If a user edits a contact card in roundcube, and it is updated in the background during the
edit, the changes will be overwritten as the user stores the card. It would not be detected by the ETag check, as the
ETag in the database is up to date with the server, but the data shown in the edit form to the user was not. This is
probably a rare issue, and arguably the card is stored on the server containing the data as submitted by the user.

## Known open issues

Transactions don't work with MySQL and the `rcube\_db` abstraction layer. If multiple transactions run into a deadlock
situation, MySQL will kill transactions to resolve the deadlock. In such situations, the transaction has been rolled
back. The roundcube code catches the exception and repeats the failed query, without notifying the caller. So firstly,
this will result in inconsistent state of the data (part of the transaction was rolled back, the remaining queries of
the transaction are executed outside the context of a transaction). This is reported as issue
[roundcube/roundcubemail#7528](https://github.com/roundcube/roundcubemail/issues/7528), and I also provided a pull request
[roundcube/roundcubemail#7529](https://github.com/roundcube/roundcubemail/pull/7529). It have the feeling that this won't be fixed anytime soon though, so
essentially concurrency remains broken for MySQL.
