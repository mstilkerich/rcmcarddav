# Supported environment

This file lists the supported versions of roundcube and databases by the plugin. Other versions than those listed may
work, but have not been tested.

## Roundcube

The latest patchlevel of roundcube is supported for all branches of roundcube still supported by the roundcube project.

These currently are:
  - Version 1.6
  - Version 1.7

I recommend using the latest stable roundcube version, which the plugin is most tested with.

RCMCardDAV may work with older versions of roundcube, but is not supported.

## Databases

RCMCardDAV supports the following databases in the listed minimum versions:
  - PostgreSQL 15
  - MySQL 8.4
  - MariaDB 10.11
  - SQLite 3.45

Generally, I aim to support the database versions available in the latest two Ubuntu LTS releases. Older database
versions may work, but are not supported.

Roundcube supports additional commercial databases, which are not supported because there is no way to test them.
