This migration unifies the default value of the carddav\_addressbooks last\_updated column to
  - be close to the epoch (1970-01-01 00:00:00)
  - avoid the use of DBMS specific literals (-infinity on postgres)

Unfortunately, I found no way to specify a the epoch timestamp as a literal in MySQL, where the timestamp literal is
interpreted in the DBMS timezone and thus a 1970-01-01 00:00:00 cannot be used as in timezones that are ahead of UTC
they would yield negative (non-representable) timestamp values. Thus for MySQL we use 1970-01-02 00:00:00 instead, which
allows the related test case to limit the allowed tolerance to a day from 0.
