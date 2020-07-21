This migration replaces all URL placeholders of addressbook URLs in the database.
With v4 of the plugin, placeholders are replaced during discovery and only the
finally discovered URLs of addressbooks are stored in the database. From before,
there may be URLs still containing placeholders, that need to be migrated.
