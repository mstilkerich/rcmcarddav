ROUNDCUBEDIR=../roundcubemail
SQLITE_TESTDB=testreports/test.db
CD_TABLES=$(foreach tbl,addressbooks contacts groups group_user xsubtypes migrations,carddav_$(tbl))

.PHONY: all stylecheck phpcompatcheck staticanalyses psalmanalysis tests verification

all: staticanalyses

verification: staticanalyses tests

staticanalyses: stylecheck phpcompatcheck psalmanalysis

stylecheck:
	vendor/bin/phpcs --colors --standard=PSR12 *.php src/ dbmigrations/ tests/

phpcompatcheck:
	vendor/bin/phpcs --colors --standard=PHPCompatibility --runtime-set testVersion 7.1 *.php src/ dbmigrations/ tests/

psalmanalysis:
	vendor/bin/psalm

tarball:
	VERS=$$(git tag --points-at HEAD); \
		if [ -z "$$VERS" ]; then echo "Error: HEAD has no version tag"; exit 1; else \
			git archive --format tgz --prefix carddav/ -o carddav-$$VERS.tgz --worktree-attributes HEAD; \
		fi

tests: createtestdb
	@[ -f tests/dbinterop/DatabaseAccounts.php ] || (echo "Create tests/dbinterop/DatabaseAccounts.php from template tests/dbinterop/DatabaseAccounts.php.dist to execute tests"; exit 1)
	sed -e 's/TABLE_PREFIX//g' <dbmigrations/INIT-currentschema/sqlite3.sql | sqlite3 $(SQLITE_TESTDB)
	sed -e 's/TABLE_PREFIX//g' <dbmigrations/INIT-currentschema/postgres.sql | psql -U rcmcarddavtest rcmcarddavtest
	sed -e 's/TABLE_PREFIX//g' <dbmigrations/INIT-currentschema/mysql.sql | sudo mysql --show-warnings rcmcarddavtest
	vendor/bin/phpunit -c tests/dbinterop/phpunit.xml

# Checks that the schema after playing all migrations matches the one in INIT
schematest: schematest-postgres schematest-mysql schematest-sqlite3

createtestdb: createtestdb-postgres createtestdb-mysql createtestdb-sqlite3

createtestdb-postgres: cleantestdb-postgres
	sudo -u postgres createdb -O rcmcarddavtest -E UNICODE rcmcarddavtest
	psql -U rcmcarddavtest rcmcarddavtest < $(ROUNDCUBEDIR)/SQL/postgres.initial.sql

createtestdb-mysql: cleantestdb-mysql
	sudo mysql --show-warnings -e 'CREATE DATABASE rcmcarddavtest /*!40101 CHARACTER SET utf8 COLLATE utf8_general_ci */;' -e 'GRANT ALL PRIVILEGES ON rcmcarddavtest.* TO rcmcarddavtest@localhost;' -e 'use rcmcarddavtest;' -e 'source ../roundcubemail/SQL/mysql.initial.sql;'

schematest-sqlite3: playmigrations-sqlite3
	/bin/echo -e '$(foreach tbl,$(CD_TABLES),.dump $(tbl)\n)' | sed -e 's/^\s*//' | sqlite3 $(SQLITE_TESTDB) | sed -e 's/IF NOT EXISTS "carddav_\([^"]\+\)"/carddav_\1/' -e 's/^\s\+$$//' > testreports/sqlite-mig.sql
	rm -f $(SQLITE_TESTDB)
	sqlite3 $(SQLITE_TESTDB) < $(ROUNDCUBEDIR)/SQL/sqlite.initial.sql
	sed -e 's/TABLE_PREFIX//g' <dbmigrations/INIT-currentschema/sqlite3.sql | sqlite3 $(SQLITE_TESTDB)
	/bin/echo -e '$(foreach tbl,$(CD_TABLES),.dump $(tbl)\n)' | sed -e 's/^\s*//' | sqlite3 $(SQLITE_TESTDB) | sed -e 's/IF NOT EXISTS "carddav_\([^"]\+\)"/carddav_\1/' -e 's/^\s\+$$//' > testreports/sqlite-init.sql
	diff testreports/sqlite-mig.sql testreports/sqlite-init.sql

schematest-postgres: playmigrations-postgres
	pg_dump -U rcmcarddavtest --no-owner -s -t 'carddav_*' rcmcarddavtest >testreports/postgres-mig.sql
	sudo -u postgres dropdb rcmcarddavtest
	sudo -u postgres createdb -O rcmcarddavtest -E UNICODE rcmcarddavtest
	psql -U rcmcarddavtest rcmcarddavtest < $(ROUNDCUBEDIR)/SQL/postgres.initial.sql
	sed -e 's/TABLE_PREFIX//g' <dbmigrations/INIT-currentschema/postgres.sql | psql -U rcmcarddavtest rcmcarddavtest
	pg_dump -U rcmcarddavtest --no-owner -s -t 'carddav_*' rcmcarddavtest >testreports/postgres-init.sql
	diff testreports/postgres-mig.sql testreports/postgres-init.sql

schematest-mysql: playmigrations-mysql
	sudo mysqldump --skip-dump-date --no-data rcmcarddavtest $(CD_TABLES) >testreports/mysql-mig.sql
	sudo mysql --show-warnings -e 'DROP DATABASE IF EXISTS rcmcarddavtest;'
	sudo mysql --show-warnings -e 'CREATE DATABASE rcmcarddavtest /*!40101 CHARACTER SET utf8 COLLATE utf8_general_ci */;' -e 'GRANT ALL PRIVILEGES ON rcmcarddavtest.* TO rcmcarddavtest@localhost;' -e 'use rcmcarddavtest;' -e 'source ../roundcubemail/SQL/mysql.initial.sql;'
	sed -e 's/TABLE_PREFIX//g' <dbmigrations/INIT-currentschema/mysql.sql | sudo mysql --show-warnings rcmcarddavtest
	sudo mysqldump --skip-dump-date --no-data rcmcarddavtest $(CD_TABLES) >testreports/mysql-init.sql
	diff testreports/mysql-mig.sql testreports/mysql-init.sql

createtestdb-sqlite3: cleantestdb-sqlite3
	sqlite3 $(SQLITE_TESTDB) < $(ROUNDCUBEDIR)/SQL/sqlite.initial.sql

playmigrations-sqlite3: createtestdb-sqlite3
	for mig in dbmigrations/0*/sqlite3.sql ; do echo SQLITE: $$mig; sed -e 's/TABLE_PREFIX//g' <$$mig | sqlite3 $(SQLITE_TESTDB); done

playmigrations: playmigrations-postgres playmigrations-mysql playmigrations-sqlite3

playmigrations-postgres: createtestdb-postgres
	for mig in dbmigrations/0*/postgres.sql ; do echo POSTGRES: $$mig; sed -e 's/TABLE_PREFIX//g' <$$mig | psql -U rcmcarddavtest rcmcarddavtest  ; done

playmigrations-mysql: createtestdb-mysql
	for mig in dbmigrations/0*/mysql.sql ; do echo MYSQL: $$mig; sed -e 's/TABLE_PREFIX//g' <$$mig | sudo mysql --show-warnings rcmcarddavtest ; done

cleantestdb: cleantestdb-postgres cleantestdb-mysql cleantestdb-sqlite3

cleantestdb-postgres:
	sudo -u postgres dropdb rcmcarddavtest

cleantestdb-mysql:
	sudo mysql --show-warnings -e 'DROP DATABASE IF EXISTS rcmcarddavtest;'

cleantestdb-sqlite3:
	rm -f $(SQLITE_TESTDB)
