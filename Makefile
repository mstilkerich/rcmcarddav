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

tests:
	@[ -f tests/dbinterop/DatabaseAccounts.php ] || (echo "Create tests/dbinterop/DatabaseAccounts.php from template tests/dbinterop/DatabaseAccounts.php.dist to execute tests"; exit 1)
	vendor/bin/phpunit

