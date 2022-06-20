# Installation of RCMCardDAV plugin

There are two ways to install the plugin.

1. Using composer with libraries globally managed across the entire roundcube installation (__recommended__)
2. Installation from release tarball with the plugin's dependencies located in the plugin directory. There is the
   potential of conflicts with other versions of the same libraries being installed in roundcube's global vendor
   directory. This installation method may be simpler to use the roundcube packages shipped with some Linux
   distributions or in case you can/do not want to run composer yourself.

After installation, you may optionally [configure](#configuration) the plugin.

## Prerequisites

- When using MySQL 5.7.7 / Maria DB 10.2.1 or older, the following configuration settings are needed in the database
  server. Note that these are also required by roundcube, and are the default settings if you use newer versions than
  those listed above.
  - `innodb_large_prefix=1`
  - `innodb_file_per_table=1`
  - `innodb_file_format=Barracuda`
  - `innodb_default_row_format=dynamic`

## Installation using composer

The recommended and supported method of installation is by using composer.

Installation steps (all paths in the following instructions are relative to the _root directory_ of your roundcube
installation):

- Log out of Roundcube!
  This is important because RCMCardDAV runs its database initialisation / update procedure only when a user logs in!
- Get [composer](https://getcomposer.org/download/)
- Install RCMCardDAV via composer.
  - Add dependency to the `require` array in the `composer.json` file of your roundcube
    - If you want to use released versions only, add: `"roundcube/carddav": "*"`. For more specific version constraints,
      see the [composer documentation](https://getcomposer.org/doc/articles/versions.md).
    - If you want to use the current development version from git, add: `"roundcube/carddav": "dev-master"`
  - Install with `php composer.phar install --no-dev`. When updating, use `php composer.phar update --no-dev` instead.
  - If composer asks you whether you want to enable the plugin in the roundcube configuration, say __y__.
  - You should now find the plugin installed under `plugins/carddav`
- [Configure](#configuration) the plugin if needed.
- Enable RCMCardDAV in Roundcube (possibly composer already did this for you, see above):
  Open the file `config/config.inc.php` and add `carddav` to the array `$config['plugins']`.
- Login to Roundcube and setup your addressbook by navigation to the Settings page and click on CardDAV.

In case of errors, check the files `logs/*`.

## Installation from release tarball

Releases of RCMCardDAV are also provided as a tarball than can be extracted to roundcube's plugin directory.

__Note:__ Release tarballs prior to v4.1.0 lack the dependencies and require to run composer. See the INSTALL.md file
inside that tarball for the appropriate instructions.

- Log out of Roundcube!
  This is important because RCMCardDAV runs its database initialisation / update procedure only when a user logs in!
- Choose the correct release tarball
  - For roundcube version 1.5, or if your PHP version is older than PHP 7.2.5, use `carddav-vX.Y.Z-roundcube15.tar.gz`
  - Otherwise, use `carddav-vX.Y.Z-roundcube16.tar.gz`
  - The difference between the two is only in the included dependencies. The 1.5 version includes an older version of
    the Guzzle HTTP client library as used by roundcube 1.5. Roundcube versions older than 1.5 do not use Guzzle, so you
    can use the 1.6 tarball with the current Guzzle if your PHP version is recent enough for Guzzle v7 (PHP 7.2.5).
  - Note: Release tarballs prior to v4.4.0 are only provided in a single version including Guzzle v6.
- Download the release tarball from [here](https://github.com/mstilkerich/rcmcarddav/releases)
  - Note: The correct tarball is named `carddav-vX.Y.Z-roundcube1V.tar.gz`. Do not use the "Source code" tar.gz or zip
    files, these are only exports of the repository. Unfortunately, github creates these automatically for each release.
- Extract the tarball to the roundcube/plugins directory (assuming roundcube is installed at `/var/lib/roundcube`)
  `cd /var/lib/roundcube/plugins && tar xvzf /tmp/carddav-v4.1.0-roundcube16.tar.gz`
- [Configure](#configuration) the plugin if needed.
- Enable RCMCardDAV in Roundcube
  Open the file `config/config.inc.php` and add `carddav` to the array `$config['plugins']`.
- Login to Roundcube and setup your addressbook by navigation to the Settings page and click on CardDAV.

## Installation with roundcube installed from Debian/Ubuntu repositories

The version of roundcube packaged by Debian and distributed through the Debian and Ubuntu repositories has a split
installation scheme that is probably needed to comply with the Debian packaging guidelines.
  - The static part of roundcube is installed to `/usr/share/roundcube`
  - The files that may need to be modified are placed in `/var/lib/roundcube`
  - The plugins are searched for in `/var/lib/roundcube/plugins`, some pre-installed plugins are actually stored with
    the static part and symlinked from the `plugins` directory.

The easiest way to install the RCMCardDAV plugin in this situation is to install from tarball using the corresponding
[instructions](#Installation-from-release-tarball) above. The example code already contains the correct paths for
Debian/Ubuntu.

## Configuration

Configuration is optional. See [ADMIN-SETTINGS.md](ADMIN-SETTINGS.md) for a description of administrative settings.

- Copy the template `config.inc.php.dist` to `config.inc.php` (composer may already have done this for you)
- Edit `plugins/carddav/config.inc.php` as you need.

<!-- vim: set ts=4 sw=4 expandtab fenc=utf8 ff=unix tw=120: -->
