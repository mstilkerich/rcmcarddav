# Installation of RCMCardDAV plugin

There is two ways to install the plugin.

1. Using composer with libraries globally managed across the entire roundcube installation (__recommended__)
2. Installation from release tarball with the plugin's dependencies located in the plugin directory. There is the
   potential of conflicts with other versions of the same libraries being installed in roundcube's global vendor
   directory. This installation method may be simpler to use the roundcube packages shipped with some Linux
   distributions, but we won't provide support in case you run into troubles with dependencies.

After installation, you may optionally [configure](#configuration) the plugin.

## Installation using composer

The recommended and supported method of installation is by using composer.

Installation steps (all paths in the following instructions are relative to the _root directory_ of your roundcube
installation):

- Log out of Roundcube!
  This is important because RCMCardDAV runs its database initialisation / update procedure only when a user logs in!
- Get [composer](https://getcomposer.org/download/)
- Install RCMCardDAV via composer.
  - Add `"roundcube/carddav": "v4.x-dev"` to the `require` array in the `composer.json` file of your roundcube
    installation
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

- Log out of Roundcube!
  This is important because RCMCardDAV runs its database initialisation / update procedure only when a user logs in!
- Get [composer](https://getcomposer.org/download/)
- Download the release tarball from [here](releases/)
- Extract the tarball to the roundcube/plugins directory (assuming roundcube is installed at `/usr/share/roundcube`)
  `cd /usr/share/roundcube/plugins && tar xvzf /tmp/carddav-4.0.0.tgz`
- Install the plugin's dependencies with `php composer.phar install --no-dev`. To update to the latest versions of the
  depended-on libraries, use `php composer.phar update --no-dev` instead.
- [Configure](#configuration) the plugin if needed.
- Enable RCMCardDAV in Roundcube
  Open the file `config/config.inc.php` and add `carddav` to the array `$config['plugins']`.
- Login to Roundcube and setup your addressbook by navigation to the Settings page and click on CardDAV.


## Configuration

Configuration is optional. See [ADMIN-SETTINGS.MD](ADMIN-SETTINGS.MD) for a description of administrative settings.

- Copy the template `config.inc.php.dist` to `config.inc.php` (composer may already have done this for you)
- Edit `plugins/carddav/config.inc.php` as you need.
