MySQL lacks the `NOT NULL DEFAULT ''` constraint on the addressbook sync\_token column. A `DEFAULT` value is not
possible on `TEXT` columns in MySQL, but we can add the `NOT NULL` constraint and make sure that a sync\_token value is
stored when inserting new addressbooks.
