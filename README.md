# RCMCardDAV - CardDAV addressbook for Roundcube Webmail
![Unit tests](https://github.com/mstilkerich/rcmcarddav/workflows/CI%20Build/badge.svg)
[![codecov](https://codecov.io/gh/mstilkerich/rcmcarddav/graph/badge.svg)](https://codecov.io/gh/mstilkerich/rcmcarddav)
[![Type Coverage](https://shepherd.dev/github/mstilkerich/rcmcarddav/coverage.svg)](https://shepherd.dev/github/mstilkerich/rcmcarddav)

## Requirements

RCMCardDAV 5.x requires at least PHP 7.4. Dependencies are managed by composer, if you are interested in a list, see the
[composer.json](composer.json) file.

The supported versions of roundcube and supported databases can be found in
[SUPPORTED_ENVIRONMENT.md](doc/SUPPORTED_ENVIRONMENT.md).

## Installation / Uninstallation

See [INSTALL.md](doc/INSTALL.md) for (un)installation instructions.

## Documentation

A (hopefully growing) documentation for various topics is found in the [doc](doc/) folder. Currently the following is available:

- [Contact groups](doc/GROUPS.md)
- [Plugin configuration by administrator](doc/ADMIN-SETTINGS.md)

## Upgrade Notes

Generally (even for patch releases), when upgrading RCMCardDAV, log off from roundcube before performing the upgrade,
and login again after the upgrade has been performed. During login, a potentially necessary database schema upgrade is
performed, therefore the login step is important to finish the upgrade.

### Upgrading from 4.x

- Database migration happens automatically. However, the assignment of addressbooks to accounts uses a heuristic (see
  CHANGELOG.md for details) that can produce extra accounts for user-created accounts. In this case, the user will have
  to cleanup manually by deleting those accounts in the settings interface.

- The plugin configuration changed in a backwards incompatible way for some configurations. Please read
  [ADMIN-SETTINGS.md](doc/ADMIN-SETTINGS.md) for the full details on the new configuration options. Particularly mind:
  - The semantics of the URL of a preset changed for the special case where an addressbook outside the user's
    addressbook home was directly specified by URL (use case: shared addressbook that is not shared into the user's
    namespace). The new way to specify addressbooks that cannot be discovered is to use `extra_addressbooks`.
  - The discovery URL for a preset must now be given via the `discovery_url` preset attribute, `url` is not available in
    the preset anymore and used for the URLs of extra addressbooks.
  - The `carddav_name_only` option was removed.
  - The `rediscover_mode` option was removed. Now you can configure the new option `rediscover_time` to specify the
    time interval after that an addressbook re-discovery shall be performed.

### Upgrading from 3.0.x

- Database migration happens automatically.
- If you want more verbose than default logging, this must now be configured in `config.inc.php`. See the distributed
  file `config.inc.php.dist` for examples.
- For MySQL / Maria DB: If your database was created with MySQL 5.7.8 / MariaDB 10.2.1 or earlier, it likely uses the
  `COMPACT` row format. This makes a DB migration fail, because the index size is exceeded. Migration 12 since
  rcmcarddav 4.3.0 converts the row format to the current default `DYNAMIC`, but some additional settings are required
  in the MySQL / Maria DB configuration for increase the index key limit to 3072 bytes. See [INSTALL.md](doc/INSTALL.md)
  for these settings.

### Upgrading from 2.0.x

There is no supported upgrade path from the 2.0.x version. You need to manually remove RCMCardDAV 2.0.x, drop its tables
from your database and start with a fresh installation.

### Upgrading from 1.0

There is no upgrade path from the 1.0 version. You need to manually remove RCMCardDAV 1.0, drop its tables from your
database and start with a fresh installation.

<!-- vim: set ts=4 sw=4 expandtab fenc=utf8 ff=unix tw=120: -->
