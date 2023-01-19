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
- Install RCMCardDAV via composer: `php composer.phar require --update-no-dev -o "roundcube/carddav:*"`
  - If you want to use released versions only, use `"roundcube/carddav:*"` as given in the command before. For more
    specific version constraints, see the [composer documentation](https://getcomposer.org/doc/articles/versions.md).
  - If you want to use the current development version from git, use `"roundcube/carddav:dev-master"`
  - If composer asks you whether you want to enable the plugin in the roundcube configuration, say __y__.
  - You should now find the plugin installed under `plugins/carddav`
- [Configure](#configuration) the plugin if needed.
- Enable RCMCardDAV in Roundcube (possibly composer already did this for you, see above):
  Open the file `config/config.inc.php` and add `carddav` to the array `$config['plugins']`.
- Login to Roundcube and setup your addressbook by navigation to the Settings page and click on CardDAV.

In case of errors, check the files `logs/*`.

### Update using composer

To update RCMCardDAV using composer, simply run `php composer.phar update --no-dev -o roundcube/carddav`.
- Note that this will only upgrade RCMCardDAV within the version constraints listed in `composer.json`. You made this
  choice when you ran `composer require`. If you want to change the version constraint, simply run `composer require`
  again with the updated version constraint, e.g. the same command you would use for initial installation.

__Note__: Because of a [bug in roundcube/plugin-installer#38](https://github.com/roundcube/plugin-installer/issues/38),
the composer call might fail with a message claiming that `autoload_real.php` cannot open some file in the `vendor`
directory. To work around this bug, run the following commands __after__ the failed composer command:
- `php composer.phar dump-autoload`
- `php composer.phar update --no-dev -o roundcube/carddav`
- This time, the update command should succeed without an error (note that the first failed composer command is needed).
See details [here](https://github.com/mstilkerich/rcmcarddav/issues/388).

## Installation from release tarball

Releases of RCMCardDAV are also provided as a tarball than can be extracted to roundcube's plugin directory.

__Note:__ Release tarballs prior to v4.1.0 lack the dependencies and require to run composer. See the INSTALL.md file
inside that tarball for the appropriate instructions.

- Log out of Roundcube!
  This is important because RCMCardDAV runs its database initialisation / update procedure only when a user logs in!
- Download the release tarball from [here](https://github.com/mstilkerich/rcmcarddav/releases)
  - Note: The correct tarball is named `carddav-vX.Y.Z.tar.gz`. Do not use the "Source code" tar.gz or zip
    files, these are only exports of the repository. Unfortunately, github creates these automatically for each release.
- Extract the tarball to the roundcube/plugins directory (assuming roundcube is installed at `/var/lib/roundcube`)
  `cd /var/lib/roundcube/plugins && tar xvzf /tmp/carddav-v4.4.2.tar.gz`
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

# Uninstallation of RCMCardDAV plugin

If you want to uninstall the plugin and clear the roundcube database of all RCMCardDAV data, follow the following steps.
If you just want to clear RCMCardDAV's database data to start with a clean state, only the `dropdb.php` step needs to be
executed.

Steps for uninstallation:
- Disable the plugin in the roundcube configuration (remove `carddav` from `$config['plugins']` in
  `config/config.inc.php`)
- Remove all of RCMCardDAV's data from the database by running (within the roundcube installation directory):
  `plugins/carddav/scripts/dropdb.php`
- Deleting the plugin files depends on the installation method

## Delete plugin files when installed using composer

If the plugin was installed using composer, uninstall it using composer to also get rid of the dependencies. Because of
a [bug in roundcube/plugin-installer#38](https://github.com/roundcube/plugin-installer/issues/38), it is currently
necessary to use composer to get rid of the dependencies, while RCMCardDAV itself needs to be deleted manually. The
steps to be done inside the roundcube installation directory are:
- Run composer to get rid of the dependencies. The `--no-plugins` switch bypasses the aforementioned bug but also
  prevents composer from finding the correct installation location of RCMCardDAV, therefore it must be manually deleted.
  `php composer.phar remove --update-no-dev -o --no-plugins roundcube/carddav`
- Delete the plugin: `rm -r plugins/carddav`

## Delete plugin files when installed from release tarball

Simply delete the `plugins/carddav` directory inside your roundcube installation: `rm -r plugins/carddav`

<!-- vim: set ts=4 sw=4 expandtab fenc=utf8 ff=unix tw=120: -->
