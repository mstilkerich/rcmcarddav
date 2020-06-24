# RCMCardDAV

CardDAV plugin for the RoundCube Webmailer

## Upgrade Notes

__Caution: v4 is currently in development and there are some known issues:__
- The database migration script for SQLite is not available yet - do not use with SQLite at this time

### Upgrading from 3.0.x

- Database migration happens automatically.
- If you want more verbose than default logging, this must now be configured in config.inc.php. See the distributed file config.inc.php.dist for examples.
- GSSAPI is currently not supported (not tested and thus will likely not work because of new HTTP client library in v4).

### Upgrading from 2.0.x

There is no supported upgrade path from the 2.0.x version. You need to manually remove RCMCardDAV 2.0.x, drop its tables from your database and start with a fresh installation.

### Upgrading from 1.0

There is no upgrade path from the 1.0 version. You need to manually remove RCMCardDAV 1.0, drop its tables from your database and start with a fresh installation.


## Requirements
RCMCardDAV 4.x requires at least PHP 7.1.

## Installation

The supported method of installation is by using composer.

Installation steps (all paths in the following instructions are relative to the _root directory_ of your roundcube installation):
- Log out of Roundcube!
  This is important because RCMCardDAV runs its database initialisation / update procedure only when a user logs in!
- Get [composer](https://getcomposer.org/download/)
- Install RCMCardDAV via composer.
  - Add `"roundcube/carddav": "v4.x-dev"` to the composer.json file of your roundcube installation
  - Install with `php composer.phar install --no-dev`. When updating, use `php composer.phar update --no-dev` instead.
  - You should now find the plugin installed under `plugins/carddav`
- Configure RCMCardDAV
  If you want to configure preset addressbooks for your users, edit `plugins/carddav/config.inc.php` as you need.
- Enable RCMCardDAV in Roundcube:
  Open the file `config/config.inc.php` and add `carddav` to the array `$config['plugins']`.
- Login to Roundcube and setup your addressbook by navigation to the Settings page and click on CardDAV.

In case of errors, check the files `logs/*`.
