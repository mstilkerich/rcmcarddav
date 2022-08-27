# Directory of a roundcube working tree or release extract
ROUNDCUBEDIR=roundcubemail

# A list of the database types for which to execute the tests
# For each DBTYPE listed here, the following macros need to be defined:
# - TESTDB_$(DBTYPE): Name of the test database
# - MIGTESTDB_$(DBTYPE): Name of the database for the schema migrations test
# - EXECDBSCRIPT_$(DBTYPE): Command to execute an SQL script
# - CREATEDB_$(DBTYPE): Command to create a database
# - DUMPTBL_$(DBTYPE): Command to dump the schema of the rcmcarddav tables
DBTYPES=postgres sqlite3 mysql

TESTDB_sqlite3=testreports/test.db
MIGTESTDB_sqlite3=testreports/migtest.db

TESTDB_mysql=rcmcarddavtest
MIGTESTDB_mysql=rcmcarddavmigtest

TESTDB_postgres=rcmcarddavtest
MIGTESTDB_postgres=rcmcarddavmigtest

# A list of the DB tables of the rcmcarddav plugin
CD_TABLES=$(foreach tbl,addressbooks contacts groups group_user xsubtypes migrations,carddav_$(tbl))

# Where to store the generated API phpdoc documentation (doc target)
DOCDIR := doc/api/

# Version of a release to build a tarball for (tarball target), e.g. v4.3.0
# Normally this is automatically set to build a tarball from the current tagged HEAD
RELEASE_VERSION ?= $(shell git tag --points-at HEAD)

# MYSQL_CMD_PREFIX can be used to run the command inside a docker container
# For simplicity, we assume an isolated test database that we can directly access as the root user with no sensitive password
# The following environment variables are assumed for MYSQL:
#   - MYSQL_PASSWORD: Password of the MySQL root user
#   - MYSQL_CMD_PREFIX: Prefix to use for all mysql commands (intended use: docker exec)
MYSQL     := $(MYSQL_CMD_PREFIX) mysql -u root -p"$$MYSQL_PASSWORD"
MYSQLDUMP := $(MYSQL_CMD_PREFIX) mysqldump -u root -p"$$MYSQL_PASSWORD"

# POSTGRES_CMD_PREFIX can be used to run the command inside a docker container
# For simplicity, we assume an isolated test database that we can directly access as the postgres user with no sensitive password
# The following environment variables are assumed for PostgreSQL
#   - PGHOST: Hostname/IP of the postgres server
#   - PGUSER: Username to use (this user needs permissions to create / drop databases, typically this is postgres)
#   - POSTGRES_CMD_PREFIX: Prefix to use for all postgres commands (intended use: docker exec)
PG_CREATEDB := $(POSTGRES_CMD_PREFIX) createdb
PG_DROPDB	:= $(POSTGRES_CMD_PREFIX) dropdb
PG_DUMP     := $(POSTGRES_CMD_PREFIX) pg_dump
PSQL        := $(POSTGRES_CMD_PREFIX) psql

.PHONY: all stylecheck phpcompatcheck staticanalyses psalmanalysis tests verification doc

all: staticanalyses doc

verification: staticanalyses tests checktestspecs

staticanalyses: stylecheck phpcompatcheck psalmanalysis

stylecheck:
	vendor/bin/phpcs --colors --standard=PSR12 *.php src/ dbmigrations/ tests/

phpcompatcheck:
	vendor/bin/phpcs --colors --standard=PHPCompatibility --runtime-set testVersion 7.1 *.php src/ dbmigrations/ tests/

psalmanalysis: tests/dbinterop/DatabaseAccounts.php
	vendor/bin/psalm --no-cache --shepherd --report=testreports/psalm.txt --report-show-info=true --no-progress

# Example usage for non-HEAD version: RELEASE_VERSION=v4.1.0 make tarball
.PHONY: tarball
tarball:
	mkdir -p releases
	rm -rf releases/carddav
	@[ -n "$(RELEASE_VERSION)" ] || { echo "Error: HEAD has no version tag, and no version was set in RELEASE_VERSION"; exit 1; }
	( git show "$(RELEASE_VERSION):carddav.php" | grep -q "const PLUGIN_VERSION = '$(RELEASE_VERSION)'" ) || { echo "carddav::PLUGIN_VERSION does not match release" ; exit 1; }
	@grep -q "^## Version $(patsubst v%,%,$(RELEASE_VERSION))" CHANGELOG.md || { echo "No changelog entry for release $(RELEASE_VERSION)" ; exit 1; }
	git archive --format tar --prefix carddav/ -o releases/carddav-$(RELEASE_VERSION).tar --worktree-attributes $(RELEASE_VERSION)
	@# Fetch a clean state of all dependencies
	composer create-project --repository='{"type":"vcs", "url":"file://$(PWD)" }' -q --no-install --no-dev --no-plugins roundcube/carddav releases/carddav $(RELEASE_VERSION)
	cd releases/carddav && \
		jq -s '.[0] * .[1] | del(."require-dev")' composer.json .github/configs/composer-build-release.json >composer-release.json &&\
	    COMPOSER=composer-release.json composer update -q --no-dev --optimize-autoloader
	tar -C releases --owner 0 --group 0 -rf releases/carddav-$(RELEASE_VERSION).tar carddav/vendor
	@# gzip the tarball
	gzip -v releases/carddav-$(RELEASE_VERSION).tar

define EXECDBSCRIPT_postgres
sed -e 's/TABLE_PREFIX//g' $(2) | $(PSQL) $(1)
endef
define EXECDBSCRIPT_mysql
sed -e 's/TABLE_PREFIX//g' $(2) | $(MYSQL) --show-warnings $(1)
endef
define EXECDBSCRIPT_sqlite3
sed -e 's/TABLE_PREFIX//g' -e 's/-- .*//' $(2) | sqlite3 $(1)
endef

define CREATEDB_postgres
$(PG_DROPDB) --if-exists $(TESTDB_postgres)
$(PG_CREATEDB) -E UNICODE $(TESTDB_postgres)
$(call EXECDBSCRIPT_postgres,$(TESTDB_postgres),$(ROUNDCUBEDIR)/SQL/postgres.initial.sql)
$(PG_DROPDB) --if-exists $(MIGTESTDB_postgres)
$(PG_CREATEDB) -E UNICODE $(MIGTESTDB_postgres)
$(call EXECDBSCRIPT_postgres,$(MIGTESTDB_postgres),$(ROUNDCUBEDIR)/SQL/postgres.initial.sql)
endef
define CREATEDB_mysql
$(MYSQL) --show-warnings -e 'DROP DATABASE IF EXISTS $(TESTDB_mysql);'
$(MYSQL) --show-warnings -e 'CREATE DATABASE $(TESTDB_mysql) /*!40101 CHARACTER SET utf8 COLLATE utf8_general_ci */;'
$(call EXECDBSCRIPT_mysql,$(TESTDB_mysql),$(ROUNDCUBEDIR)/SQL/mysql.initial.sql)
$(MYSQL) --show-warnings -e 'DROP DATABASE IF EXISTS $(MIGTESTDB_mysql);'
$(MYSQL) --show-warnings -e 'CREATE DATABASE $(MIGTESTDB_mysql) /*!40101 CHARACTER SET utf8 COLLATE utf8_general_ci */;'
$(call EXECDBSCRIPT_mysql,$(MIGTESTDB_mysql),$(ROUNDCUBEDIR)/SQL/mysql.initial.sql)
endef
define CREATEDB_sqlite3
mkdir -p $(dir $(TESTDB_sqlite3))
mkdir -p $(dir $(MIGTESTDB_sqlite3))
rm -f $(TESTDB_sqlite3) $(MIGTESTDB_sqlite3)
$(call EXECDBSCRIPT_sqlite3,$(TESTDB_sqlite3),$(ROUNDCUBEDIR)/SQL/sqlite.initial.sql)
$(call EXECDBSCRIPT_sqlite3,$(MIGTESTDB_sqlite3),$(ROUNDCUBEDIR)/SQL/sqlite.initial.sql)
endef

define DUMPTBL_postgres
$(PG_DUMP) --no-owner -s $(foreach tbl,$(CD_TABLES),-t $(tbl)) $(1) >$(2)
endef
define DUMPTBL_mysql
$(MYSQLDUMP) --skip-comments --skip-dump-date --no-data $(1) $(CD_TABLES) | sed 's/ AUTO_INCREMENT=[0-9]\+//g' >$(2)
endef
define DUMPTBL_sqlite3
/bin/echo -e '$(foreach tbl,$(CD_TABLES),.schema $(tbl)\n)' | sed -e 's/^\s*//' | sqlite3 $(1) | sed -e 's/IF NOT EXISTS "carddav_\([^"]\+\)"/carddav_\1/' -e 's/^\s\+$$//' >$(2)
endef

define EXEC_DBTESTS
.INTERMEDIATE: tests/dbinterop/phpunit-$(1).xml
tests/dbinterop/phpunit-$(1).xml: tests/dbinterop/phpunit.tmpl.xml
	sed -e 's/%TEST_DBTYPE%/$(1)/g' tests/dbinterop/phpunit.tmpl.xml >tests/dbinterop/phpunit-$(1).xml

.PHONY: tests-$(1)
tests-$(1): tests/dbinterop/phpunit-$(1).xml tests/dbinterop/DatabaseAccounts.php
	@echo
	@echo  ==========================================================
	@echo "      EXECUTING DBINTEROP TESTS FOR DB $(1)"
	@echo  ==========================================================
	@echo
	@mkdir -p testreports
	@[ -f tests/dbinterop/DatabaseAccounts.php ] || { echo "Create tests/dbinterop/DatabaseAccounts.php from template tests/dbinterop/DatabaseAccounts.php.dist to execute tests"; exit 1; }
	$$(call CREATEDB_$(1))
	$$(call EXECDBSCRIPT_$(1),$(TESTDB_$(1)),dbmigrations/INIT-currentschema/$(1).sql)
	$$(call DUMPTBL_$(1),$(TESTDB_$(1)),testreports/$(1)-init.sql)
	vendor/bin/phpunit -c tests/dbinterop/phpunit-$(1).xml
	@echo Performing schema comparison of initial schema to schema resulting from migrations
	$$(call DUMPTBL_$(1),$(MIGTESTDB_$(1)),testreports/$(1)-mig.sql)
	diff testreports/$(1)-mig.sql testreports/$(1)-init.sql
endef

$(foreach dbtype,$(DBTYPES),$(eval $(call EXEC_DBTESTS,$(dbtype))))

tests: $(foreach dbtype,$(DBTYPES),tests-$(dbtype)) unittests
	vendor/bin/phpcov merge --html testreports/coverage testreports

# For github CI system - if DatabaseAccounts.php is not available, create from DatabaseAccounts.php.dist
tests/dbinterop/DatabaseAccounts.php: | tests/dbinterop/DatabaseAccounts.php.dist
	cp $| $@

.PHONY: unittests
unittests: tests/unit/phpunit.xml
	@echo
	@echo  ==========================================================
	@echo "                   EXECUTING UNIT TESTS"
	@echo  ==========================================================
	@echo
	vendor/bin/phpunit -c tests/unit/phpunit.xml

.PHONY: checktestspecs
checktestspecs:
	@for d in tests/unit/data/vcard*; do \
		for vcf in $$d/*.vcf; do \
			f=$$(basename "$$vcf" .vcf); \
			grep -q -- "- $$f:" $$d/README.md || { echo "No test description for $$d/$$f"; exit 1; } \
		done; \
	done

doc:
	rm -rf $(DOCDIR)
	phpDocumentor.phar -d . -i vendor/ -t $(DOCDIR) --title="RCMCardDAV Plugin for Roundcube"
