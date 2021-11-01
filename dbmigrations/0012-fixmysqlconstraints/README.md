In migration 0005, the NOT NULL constraints are missing from most of the altered column
definitions. This migrations re-adds them. Furthermore, it unifies the indexes to that
the schema after migrations equals the INIT schema.

2021-11-01: Because of issue 362, I added statements to the MySQL variant of this migration that convert the row format
to dynamic. This is a prerequisite for index keys up to 3072 bytes.
