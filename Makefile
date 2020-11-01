ROUNDCUBEDIR=../roundcubemail
DBTYPES=postgres sqlite3 mysql
SQLITE_TESTDB=testreports/test.db
CD_TABLES=$(foreach tbl,addressbooks contacts groups group_user xsubtypes migrations,carddav_$(tbl))

.PHONY: all stylecheck phpcompatcheck staticanalyses psalmanalysis tests verification

all: staticanalyses

verification: staticanalyses schematest tests

staticanalyses: stylecheck phpcompatcheck psalmanalysis

stylecheck:
	vendor/bin/phpcs --colors --standard=PSR12 *.php src/ dbmigrations/ tests/

phpcompatcheck:
	vendor/bin/phpcs --colors --standard=PHPCompatibility --runtime-set testVersion 7.1 *.php src/ dbmigrations/ tests/

psalmanalysis:
	vendor/bin/psalm

.PHONY: tarball
tarball:
	VERS=$$(git tag --points-at HEAD); \
		if [ -z "$$VERS" ]; then echo "Error: HEAD has no version tag"; exit 1; else \
			grep  -q "const PLUGIN_VERSION = '$$VERS'" carddav.php || (echo "carddav::PLUGIN_VERSION does not match release" ; exit 1) ; \
			git archive --format tgz --prefix carddav/ -o carddav-$$VERS.tgz --worktree-attributes HEAD; \
		fi

define EXECDBSCRIPT_postgres
sed -e 's/TABLE_PREFIX//g' <$(1) | psql -U rcmcarddavtest rcmcarddavtest
endef
define EXECDBSCRIPT_mysql
sed -e 's/TABLE_PREFIX//g' <$(1) | sudo mysql --show-warnings rcmcarddavtest
endef
define EXECDBSCRIPT_sqlite3
sed -e 's/TABLE_PREFIX//g' <$(1) | sqlite3 $(SQLITE_TESTDB)
endef

define CREATEDB_postgres
sudo -u postgres dropdb rcmcarddavtest
sudo -u postgres createdb -O rcmcarddavtest -E UNICODE rcmcarddavtest
$(call EXECDBSCRIPT_postgres,$(ROUNDCUBEDIR)/SQL/postgres.initial.sql)
endef
define CREATEDB_mysql
sudo mysql --show-warnings -e 'DROP DATABASE IF EXISTS rcmcarddavtest;'
sudo mysql --show-warnings -e 'CREATE DATABASE rcmcarddavtest /*!40101 CHARACTER SET utf8 COLLATE utf8_general_ci */;' -e 'GRANT ALL PRIVILEGES ON rcmcarddavtest.* TO rcmcarddavtest@localhost;'
$(call EXECDBSCRIPT_mysql,$(ROUNDCUBEDIR)/SQL/mysql.initial.sql)
endef
define CREATEDB_sqlite3
rm -f $(SQLITE_TESTDB)
$(call EXECDBSCRIPT_sqlite3,$(ROUNDCUBEDIR)/SQL/sqlite.initial.sql)
endef

define DUMPTBL_postgres
pg_dump -U rcmcarddavtest --no-owner -s $(foreach tbl,$(CD_TABLES),-t $(tbl)) rcmcarddavtest >$(1)
endef
define DUMPTBL_mysql
sudo mysqldump --skip-dump-date --no-data rcmcarddavtest $(CD_TABLES) >$(1)
endef
define DUMPTBL_sqlite3
/bin/echo -e '$(foreach tbl,$(CD_TABLES),.dump $(tbl)\n)' | sed -e 's/^\s*//' | sqlite3 $(SQLITE_TESTDB) | sed -e 's/IF NOT EXISTS "carddav_\([^"]\+\)"/carddav_\1/' -e 's/^\s\+$$//' >$(1)
endef

define EXEC_DBTESTS
.INTERMEDIATE: tests/dbinterop/phpunit-$(1).xml
tests/dbinterop/phpunit-$(1).xml: tests/dbinterop/phpunit.tmpl.xml
	sed -e 's/%TEST_DBTYPE%/$(1)/g' tests/dbinterop/phpunit.tmpl.xml >tests/dbinterop/phpunit-$(1).xml

.PHONY: tests-$(1)
tests-$(1): tests/dbinterop/phpunit-$(1).xml
	@echo
	@echo  ==========================================================
	@echo "      EXECUTING DBINTEROP TESTS FOR DB $(1)"
	@echo  ==========================================================
	@echo
	@[ -f tests/dbinterop/DatabaseAccounts.php ] || (echo "Create tests/dbinterop/DatabaseAccounts.php from template tests/dbinterop/DatabaseAccounts.php.dist to execute tests"; exit 1)
	$$(call CREATEDB_$(1))
	$$(call EXECDBSCRIPT_$(1),dbmigrations/INIT-currentschema/$(1).sql)
	vendor/bin/phpunit -c tests/dbinterop/phpunit-$(1).xml

.PHONY: testreports/$(1)-mig.sql testreports/$(1)-init.sql
.INTERMEDIATE: testreports/$(1)-mig.sql testreports/$(1)-init.sql
testreports/$(1)-mig.sql:
	$$(call CREATEDB_$(1))
	for mig in dbmigrations/0*/$(1).sql ; do echo $(1): $$$$mig; $$(call EXECDBSCRIPT_$(1),$$$$mig); done
	$$(call DUMPTBL_$(1), testreports/$(1)-mig.sql)

testreports/$(1)-init.sql:
	$$(call CREATEDB_$(1))
	$$(call EXECDBSCRIPT_$(1),dbmigrations/INIT-currentschema/$(1).sql)
	$$(call DUMPTBL_$(1), testreports/$(1)-init.sql)

schematest-$(1): testreports/$(1)-mig.sql testreports/$(1)-init.sql
	diff testreports/$(1)-mig.sql testreports/$(1)-init.sql
endef

$(foreach dbtype,$(DBTYPES),$(eval $(call EXEC_DBTESTS,$(dbtype))))

tests: $(foreach dbtype,$(DBTYPES),tests-$(dbtype))

# Checks that the schema after playing all migrations matches the one in INIT
schematest: $(foreach dbtype,$(DBTYPES),schematest-$(dbtype))

.PHONY: unittests

unittests: tests/unit/phpunit.xml
	@echo
	@echo  ==========================================================
	@echo "                   EXECUTING UNIT TESTS"
	@echo  ==========================================================
	@echo
	vendor/bin/phpunit -c tests/unit/phpunit.xml

