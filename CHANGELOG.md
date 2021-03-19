# Changelog for RCMCardDAV

- Fix: A fatal error would be raised when a password could not be decrypted, only on photo download. This would not be
  notable to the user (except for the photo not being displayed), but show up in the logs.
- Fix #339: Allow adding public/shared addressbooks by giving full URL. Discovery is still used if the given URL does
  not point to an addressbook directly, or points to an addressbook inside the user's addressbook home.

## Version 4.1.0 (to 4.0.4)

- Fix: Prefer labels from X-ABLabel extension if available over standard labels
- Fix #317: Support specification of department with empty organization
- Support several levels of departments separated by semicolon that end up as structured value in the VCard
- Fix #318: Some attributes (e.g. gender) could not be deleted when updating a contact
- Fix #53: Only create displayname when not present in VCard / not provided by roundcube
- Fix #325: Roundcube setting for contact sorting field was not used
- Fix #279: More specific error message when syntactically wrong URL is entered for new addressbook
- Fix #328: Contact search with MySQL might not have returned all results
- Fix #332: When adding a new contact via "add to addressbook" from mail view, the email address was missing in the new
  card
- New: Download externally referenced photos on demand, drastically speeding up sync with when photos are stored
  separately from the VCard (e.g. iCloud). For details see #247.
- New: Support for instant messaging data fields and maiden name (resolves #46). Interoperability with other
  CardDAV clients suffers some caveats, but I tried my best to achieve maximum possible interoperability. See
  [IMPP.md](doc/devdoc/IMPP.md) for the gory details.
- Removed a workaround that appears to be needed in the part to provide address data to the calendar plugin. It seems
  this is no longer the case for current versions of calendar.

## Version 4.0.4 (to 4.0.3)

- Fix #321: Boolean settings in presets caused errors when trying to store the preset's addressbooks to the database
- Fix #322: The refresh time string from admin presets was not converted to seconds, causing errors or wrong values when
  storing the preset's addressbooks to the database
- Fix #324: Changes not immediately visible with postgresql (delete contact, add/remove contact to/from group)
- Fix: spurious error returned when creating VCard on Google

## Version 4.0.3 (to 4.0.2)

- Allow release 1.0 of carddavclient in composer dependencies
- No changes to the plugin itself

## Version 4.0.2 (to 4.0.1)

- Fix #316: Incompatibility with Sabre/VObject version 4 preventing saving contacts using custom labels
- Fix: Default refresh time set to 1 sec in settings

## Version 4.0.1 (to 4.0.0)

- Fix: Plugin version was not shown in about window for tarball installations
- Fix: Collation behavior was case-insensitive for MySQL (only). Now unified across the different supported DBMS.
- Fix #306: With MySQL, sync failure could occur when several custom labels where used that only differed in case
  (effect of previous issue).
- Fix #308: With SQLite, the initial sync after adding a new addressbook was not automatically triggered.

## Version 4.0.0 (to 3.0.3)

This release contains changes to DB schema. The database will be migrated automatically upon login to roundcube.

- All changes from 4.0.0-alpha1
- Fix: Deletion of empty CATEGORIES-type groups
- Fix: Delete CATEGORIES-type groups from DB that become empty during a sync
- Fix: Renaming of empty CATEGORIES-type groups
- Fix: During deletion, do not rely on the DB's ON CASCADE DELETE because this is disabled by default for SQLite
- Fix: It was not possible to discover multiple addressbooks for an admin preset because of a wrong UNIQUE constraint in
  MySQL
- Fix: Catch exceptions thrown inside the plugin (avoid "white page" on error)
- Increase the maximum lengths of password, email and url fields
- Use transactions to synchronize concurrent operations on the same addressbook
  (data consistency issues may still occur with MySQL because of roundcube DB
  layer bug). For details, see [DBSYNC.md](doc/DBSYNC.md).
- Unified database indexes across the different database backends: Create indexes for foreign key columns (PostgreSQL,
  SQLite)
- Fixed issues in the migration scripts and added SQL scripts showing the current DB schema
- Update hungarian translation (thanks to @tsabi)

## Version 4.0.0-alpha1 (to 3.0.3)

Note: The Changelog for this version is not complete

This is an alpha release because I did not perform any tests on it. Nevertheless, it has many bugs fixed and I encourage
you to upgrade and report issues as you find them. The last release 3.0.3 has many issues that have been fixed with in
v4. I push this release early mainly because of the security issue reported. I'll continue working on remaining issues I
want to fix (note: all of them are also present in 3.0.3) for v4 and I intend release a more tested version and a more
detailed changelog within the next weeks.

- __Security issue__: It was possible to read data from other user's addressbooks. Depending on the configuration, it
  might also have been possible to change data in their addressbooks. Thanks to @cnmicha for reporting this issue. This
  issue affects all previously released versions of RCMCardDAV using a database cache.
- Many bugs you reported and several more I discovered during refactoring have been fixed.
- The password scheme now defaults to `encrypted` (if you have not configured a password scheme, this will take effect
  automatically for newly stored password. If you don't want this, configure a password scheme in settings.php).
- The URL is not changeable after creation of an addressbook anymore. It used to work in specific, but not all cases. As
  the behavior is potentially broken and not easy to fix, it is removed for now.
- The two kinds of contact groups (VCard-based vs. CATEGORIES-based) are not transparently supported to the possible
  extent. The configuration switch is only meaningful concerning the type of group used when a __new__ group is created
  from RCMCardDAV. See details [here](doc/GROUPS.md).
- The CardDAV interaction is moved to a [library](https://github.com/mstilkerich/carddavclient). It is essentially a
  complete rewrite of the code communicating with the CardDAV servers and includes interoperability tests with many
  common servers, see [here](https://github.com/mstilkerich/carddavclient).

<!-- vim: set ts=4 sw=4 expandtab fenc=utf8 ff=unix tw=120: -->
