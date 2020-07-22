.PHONY: all stylecheck phpcompatcheck staticanalyses psalmanalysis doc

all: staticanalyses doc

staticanalyses: phpcompatcheck stylecheck psalmanalysis

stylecheck:
	vendor/bin/phpcs --colors --standard=PSR12 *.php src/ dbmigrations/

phpcompatcheck:
	vendor/bin/phpcs --colors --standard=PHPCompatibility --runtime-set testVersion 7.1 *.php src/ dbmigrations/

psalmanalysis:
	vendor/bin/psalm

doc:
	rm -r ~/www/carddavclient/*
	#phpDocumentor.phar -d src/ -t ~/www/carddavclient --title="CardDAV Client Library" 
	../phpdocumentor/bin/phpdoc -d src/ -t ~/www/carddavclient --title="CardDAV Client Library" 

tarball:
	VERS=$$(git tag --points-at HEAD); \
		if [ -z "$$VERS" ]; then echo "Error: HEAD has no version tag"; exit 1; else \
			git archive --format tgz --prefix carddav/ -o carddav-$$VERS.tgz --worktree-attributes HEAD; \
		fi
