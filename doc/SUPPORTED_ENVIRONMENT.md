# Supported environment

This file lists the supported versions of roundcube and databases by the plugin. Other versions than those listed may
work, but have not been tested.

## Roundcube

The latest patchlevel of roundcube is supported for all branches of roundcube still supported by the roundcube project.

These currently are:
  - Version 1.3
  - Version 1.4
  - Version 1.5

I recommend using the latest stable roundcube version, which the plugin is most tested with.

RCMCardDAV may work with older versions of roundcube, but is not supported.

## Databases

RCMCardDAV supports the following databases in the listed minimum versions:
  - PostgreSQL 10.12
  - MySQL 5.7.30
  - MariaDB 10.1
  - SQLite 3.22

Generally, I aim to support the database versions available in the latest two Ubuntu LTS releases. Currently, these are
Ubuntu 18.04 and 20.04.

Roundcube supports additional commercial databases, which are not supported because there is no way to test them.
