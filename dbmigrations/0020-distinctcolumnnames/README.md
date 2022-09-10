This migration renames some columns in the accounts table to avoid name clashes with columns in the addressbooks
table. This facilitates the usage of presets, where we specify both account and addressbook fields and ideally are
able to directly map those to database fields.

The affected fields in the accounts table are:
- `name` is renamed to `accountname`
- `url` is renamed to `discovery_url`

<!-- vim: set ts=4 sw=4 expandtab fenc=utf8 ff=unix tw=120: -->
