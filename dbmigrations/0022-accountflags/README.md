This migration creates the flags column in the carddav_accounts table to store simple flag settings for accounts.

The current flags as of this migration for an account are:
 - `preemptive_basic_auth` (bit 0)
 - `ssl_noverify` (bit 1)

<!-- vim: set ts=4 sw=4 expandtab fenc=utf8 ff=unix tw=120: -->
