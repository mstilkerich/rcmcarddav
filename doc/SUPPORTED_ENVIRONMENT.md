# Supported environment

This file lists the supported versions of roundcube and databases by the plugin. Other versions than those listed may
work, but have not been tested.

## Roundcube

The latest patchlevel of roundcube is supported for all branches of roundcube still supported by the roundcube project.

These currently are:
  - Version 1.2 LTS
  - Version 1.3 (old stable)
  - Version 1.4 (stable)

Note that version 1.2 of roundcube will produce many PHP warnings in the log files (independent of this plugin). I
recommend using the latest stable roundcube version, which the plugin is most tested with.

## Databases

RCMCardDAV supports the following databases in the listed minimum versions:
  - PostgreSQL 10.12
  - MySQL 5.7.30
  - MariaDB 10.1
  - SQLite 3.22

Generally, I aim to support the database versions available in the latest two Ubuntu LTS releases. Currently, these are
Ubuntu 18.04 and 20.04.

Roundcube supports additional commercial databases, which are not supported because there is no way to test them.
