In migration 0005, the NOT NULL constraints are missing from most of the altered column
definitions. This migrations re-adds them. Furthermore, it unifies the indexes to that
the schema after migrations equals the INIT schema.
