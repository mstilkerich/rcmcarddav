# RCMCardDAV

CardDAV plugin for the RoundCube Webmailer

## Requirements

RCMCardDAV 4.x requires at least PHP 7.1. Dependencies are managed by composer, if you are interested in a list, see the
[composer.json](composer.json) file.

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

### Upgrading from 2.0.x

There is no supported upgrade path from the 2.0.x version. You need to manually remove RCMCardDAV 2.0.x, drop its tables from your database and start with a fresh installation.

### Upgrading from 1.0

There is no upgrade path from the 1.0 version. You need to manually remove RCMCardDAV 1.0, drop its tables from your database and start with a fresh installation.

