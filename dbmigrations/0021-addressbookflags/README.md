This migration moves the current flags stored in individual columns to a single 16-bit integer flags column that can be
easily extended in the future without creating extra columns.

The current flags as of this migration for an addressbook are:
 - `active` (flags bit 0)
 - `use_categories` (flags bit 1)
 - `discovered` (flags bit 2)

<!-- vim: set ts=4 sw=4 expandtab fenc=utf8 ff=unix tw=120: -->
