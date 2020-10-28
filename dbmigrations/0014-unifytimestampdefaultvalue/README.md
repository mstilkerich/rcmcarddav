This migration converts the last\_updated and refresh\_time columns of the carddav\_addressbooks table to use simple
integer data types. The last updated time is stored as seconds from the epoch (UNIX timestamp), the refresh time is
stored in seconds.

This simplifies things a bit as the data types, literals etc. of the different DBMS vary.
