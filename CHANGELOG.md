# Changelog for RCMCardDAV

## Changes since 5.0.0-beta1

- Fix: Internal server error with PHP8 when searching address fields of contacts (Fixes: #410)
- Fix: Assertion failure in DelayedPhotoLoader (Fixes: #404)
- Fixes for better handling of incoming vCard4 (Fixes: #411)
  - Handle data-URI-style inline PHOTO as used in vCard4
  - Use VCard conversion to handle v4 properties such as KIND=group for which extensions are used in v3 vCards
- Fix: Do not attempt to download photos from URIs with unsupported URI scheme (supported are http and https) (#411)
- When an invalid value is submitted in one of the settings forms, RCMCardDAV will now abort with an error. Previous
  versions of RCMCardDAV would ignore the value when possible an instead keep the existing value or use a default value.
- Backend will refuse Frontend request to remove user account created from preset

## Version 5.0.0-beta1 (to 4.4.4)

__NOTE: Before upgrading to v5, please read the upgrade notes in README.md.__

### Changes to the data model

RCMCardDAV never had the notion of an account in its data model. In its early releases, it was necessary for the user to
individually specify each addressbook by its URL. Later on, auto-discovery of all addressbooks on the server was added,
but after the discovery these addressbooks existed as independent objects in the database and RCMCardDAV did not keep
any track that they belong to one account. The addressbooks created from admin presets are an exception to this, as they
are linked by virtue of belonging to the same admin preset. This is why it was possible to add re-discovery (only) for
admin presets in v4.3.

The old data model has a number of issues: Smaller annoyances, such as the user having to edit a changed password in
each addressbook of the account upon password change. But also larger issues, such as the inability to perform a
rediscovery on a user-added account, as for being able to determine new and removed addressbooks, we need to have an
idea of which addressbooks belong to one account. With v5, RCMCardDAV gets accounts in its data model, which maintain
the data common to all addressbooks of an account (particularly the credentials), with each addressbook belonging to an
account.

The database migration scripts try to identify the addressbooks belonging to the same account. For addressbooks created
from an admin preset, this is easy and one account will be created. For user-created addressbooks, an account will be
created for the addressbooks that have a common parent URL (i.e. URL with the last path component removed is the same)
and use the same username. If this heuristic fails, additional accounts will result and the user will have to delete
the extra accounts and perform a rediscovery on the remaining account to have the addressbooks wrongly assigned to other
accounts added to the retained account.

This change also means that it is now possible for RCMCardDAV to store accounts with no addressbooks at all, which is a
state that may exist on the server and lays the basics for possible future features such as adding new addressbooks to
the server from within RCMCardDAV.

### Changes in admin settings
- Presets: Replaced option `carddav_name_only` with the `name` option, which can define a template for naming
  addressbooks. The old naming with `carddav_name_only=false` is equivalent to `name='%a (%N)', whereas with
  `carddav_name_only=true` would correspond to `name='%N'` (#368)
- Presets: Removed feature and option `require_always`. It is now replaced by a simpler `require_always_email` feature
  that does the same but only for the email field (#399)
- Presets: Addressbooks of a removed preset are provided as readonly until their deletion upon next login. Previously,
  these would be provided as writeable addressbooks, as the admin `readonly` setting is no longer available. Note that
  the readonly setting is not considered a security option but an interface tweak. To prevent users from modifying an
  addressbook, the proper way is to configure this by the permissions on the CardDAV server side.
- Presets: Removed option `rediscover_mode`. Rediscovery is now performed based on the new preset option
  `rediscover_time`, which allows to define a time interval for rediscovery. The same option is also available for
  user-created accounts. Rediscovery upon expiry of this interval is performed during login.
- It is now possible to have auto-discovered addressbooks and extra addressbooks directly specified by their URL in each
  preset. The latter are not subject to the discovery and kept in sync with the preset configuration. Discovery can also
  be disabled for a preset by leaving the discovery url of the preset unset. This removes the special semantic of the
  URL previously available to specify an addressbook outside the user's addressbook home, like a shared company
  addressbook.
- It is also possible to specify deviating settings for the extra addressbooks than those used for auto-discovered
  addressbooks. This can, for example, be used to add a read-only shared addressbook whereas the discovered addressbooks
  are writeable.

### Changes to the user interface

The new UI is located directly in roundcube settings as a main section, not a subsection of `Preferences`. It is heavily
inspired by roundcube's folder management. The old UI was cumbersome, particularly with multiple addressbooks and
actions that were carried out by setting a checkbox and saving the preferences. The new UI currently has only few extra
features over the old one, but it provides the basis for extensions by new features in the future.

- The localization is only updated for English and German, all other languages just have localized labels for the parts
  that still exist in the new interface. If you want to support for you language, please edit the appropriate file in
  the localization subdirectory and submit a pull request. The English localization should be taken as master.
- Trigger manual addressbook rediscovery from the user interface
- All standard skins (classic, larry, elastic) are supported, but I spent only limited effort for classic and larry,
  since these have been removed from the roundcube 1.6 core distribution and it is unclear to what extent they will be
  maintained in the future.
- It is now possible to configure the initial settings for new addressbooks added to an account (#398). These are used
  when a rediscovery is performed and new addressbooks are found. These settings are available in the configuration of
  the respective account.

### Other changes
- Fix: When setting CardDAV addressbooks for collected recipients/senders from the admin configuration, the setting
  might get overridden by user preferences in conjunction with the use of other plugins (that are completely unrelated
  to these addressbooks). (Fixes #391)
- Enable foreign key constraints for SQLite3 database; note that this affects not only this plugin, but roundcube itself
  and other plugins as well. I suppose it should not be a problem and is the standard behavior with other DB backends,
  too.
- The script `scripts/dropdb.php` can be used to clear all of RCMCardDAV's data from the database. See the uninstall
  instructions in [INSTALL.md](doc/INSTALL.md). (Fixes #69)


## Version 4.4.4 (to 4.4.3)
- Fix PHP 8.1 warning on loss of precision by using integer division
- Fix: When setting CardDAV addressbooks for collected recipients/senders from the admin configuration, setting them as
  `dont_override` is a mandatory action for the admin, otherwise the setting might get overridden by user preferences in
  conjunction with the use of other plugins (that are completely unrelated to these addressbooks). (Fixes #391)

## Version 4.4.3 (to 4.4.2)

- Properly set and interpret TYPE parameters, especially for TEL (Fixes #390)
- RCMCardDAV now sets the PRODID property when it creates or modifies a vcard

## Version 4.4.2 (to 4.4.1)

- Revert to a single release tarball. The new approach (compared to 4.4.1) to avoid the issue with conflicting
  dependencies between those coming with roundcube and those coming with the RCMCardDAV release tarball is to append the
  RCMCardDAV to the end of the autoloader list, so the roundcube dependencies are always tried first. This means if a
  library used by RCMCardDAV already comes with roundcube, RCMCardDAV will also use that version of the library. There
  is still possible problems left (i.e. package that only comes with roundcube might have a dependency for that an
  incompatible version is already included with roundcube). In the end, I don't think there is a clean solution to this
  issue. If you want to avoid this mess, don't use the release tarball but install using composer.
- No changes to RCMCardDAV itself

## Version 4.4.1 (to 4.4.0)

- Create release tarballs with a PHP version emulated to 7.1.0 (minimum needed by RCMCardDAV)
- No changes to RCMCardDAV itself

## Version 4.4.0 (to 4.3.0)

- MySQL/PostgreSQL: Increase maximum length limit for addressbook name (Fixes #382)
- Fix: log messages could go to the wrong logger (carddav\_http.log) for a small part of the init code
- Support setting roundcube's collected senders/recipients to addressbooks from preset (Fixes #383)
- Provide tarball releases in two variants: Guzzle v6 (roundcube 1.5) and v7 (roundcube 1.6) (Fixes #385)

## Version 4.3.0 (to 4.2.2)

- New: For preset addressbooks, a re-discovery is performed upon every login by default. This means that newly added
  addressbooks on the server are discovered and added, whereas addressbooks that have been removed from the server are
  also removed from roundcube.
  - __NOTE TO ADMINS__: If you are using addressbook presets, please read the documentation on the new preset setting
    `rediscover_mode` to decide if re-discovery is desired or not. The new default is functionally safe, but performance
    can be improved if the new behavior is not needed.
  - For manually-added addressbooks, this will require changes to the rcmcarddav data model, which is planned for
    version 5.
  - Version 5 will also have a more elaborate version of re-discovery that will allow to configure it such that it does
    not happen on every login.
- MySQL: Convert potentially used row format COMPACT (was default up to MySQL 5.7.8, Maria DB 10.2.1) to DYNAMIC in
  migration 12, which would otherwise fail (Fixes #362). It requires some other settings that have to be configured in
  the MySQL server configuration additionally, all of which are also defaults since MySQL 5.7.7 / Maria DB 10.2.2.

## Version 4.2.2 (to 4.2.1)

- Fix: Detect login via OAuth and prevent usage of `encrypted` password scheme in this case. The passwords encrypted
  cannot be decrypted anymore when the access token changes. In case such passwords have already been stored to the DB,
  the user must enter and save the password via the preferences again. In case of an admin preset where the password is
  marked as a `fixed` field, the password should be updated on next login of the user.

## Version 4.2.1 (to 4.2.0)

- Updated French translation (#355)
- Updated German translation
- Fix: Display error message when the sync fails (instead of showing a success message with a duration of -1 seconds)
- Fix: Display error message when a card cannot be updated because it changed on the server since the last sync.
- Fix #356: Don't create group vcards with duplicate members, don't fail to process them if we receive one from the
  server
- New: Action in the preferences to clear the local cache of an addressbook, to allow a full sync from scratch. This is
  meant to fix errors in the local state that cannot be repaired from incremental syncs, as might be the result of issue
  #356.

## Version 4.2.0 (to 4.1.2)

- New: Support OAUTH2 authentication in a single-sign-on setup in conjunction with roundcube 1.5 OAUTH2 authentication.
  This enables to use the bearer token acquired during login by roundcube to also be used to authenticate with the
  CardDAV server. It is currently not possible to add addressbooks using a custom OAUTH2 provider, i.e. a different one
  than that used to log into roundcube.
- Fix #348: Load the carddav plugin in the calendar task. This enables usage of carddav addressbooks within the calendar
  plugin, for example for the purpose of the birthday calendar.

## Version 4.1.2 (to 4.1.1)
- Fix #345: Crash during cropping of photos with `X-ABCROP-RECTANGLE` parameter when error occurred during crop, e.g.
  picture format not supported by php-gd.
- New: When exporting a VCard from a carddav addressbook, the export is now handled by rcmcarddav. The exported
  card is exactly the card on the server, including any properties not supported by roundcube / rcmcarddav. The only
  exception is the photo property: photos referenced by URI will be downloaded and stored inside the card; photos with
  `X-ABCROP-RECTANGLE` will be stored cropped. This is to improve interoperability with the importing application.
- Fix #345: When exporting a VCard from a carddav addressbook, the PHOTO property in the exported card contained an
  invalid value.

## Version 4.1.1 (to 4.1.0)
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
