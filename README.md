# RCMCardDAV - CardDAV addressbook for Roundcube Webmail
![Unit tests](https://github.com/mstilkerich/rcmcarddav/workflows/CI%20Build/badge.svg)
[![codecov](https://codecov.io/gh/mstilkerich/rcmcarddav/branch/v4/graph/badge.svg)](https://codecov.io/gh/mstilkerich/rcmcarddav)
[![Type Coverage](https://shepherd.dev/github/mstilkerich/rcmcarddav/coverage.svg)](https://shepherd.dev/github/mstilkerich/rcmcarddav)

## Requirements

RCMCardDAV 4.x requires at least PHP 7.1. Dependencies are managed by composer, if you are interested in a list, see the
[composer.json](composer.json) file.

The supported versions of roundcube and supported databases can be found in [SUPPORTED_ENVIRONMENT.md](doc/SUPPORTED_ENVIRONMENT.md).

## Installation

See [INSTALL.md](doc/INSTALL.md) for installation instructions.

## Documentation

A (hopefully growing) documentation for various topics is found in the [doc](doc/) folder. Currently the following is available:

- [Contact groups](doc/GROUPS.md)
- [Plugin configuration by administrator](doc/ADMIN-SETTINGS.md)

## Upgrade Notes

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

There is no supported upgrade path from the 2.0.x version. You need to manually remove RCMCardDAV 2.0.x, drop its tables from your database and start with a fresh installation.

### Upgrading from 1.0

There is no upgrade path from the 1.0 version. You need to manually remove RCMCardDAV 1.0, drop its tables from your database and start with a fresh installation.

<!-- vim: set ts=4 sw=4 expandtab fenc=utf8 ff=unix tw=120: -->
