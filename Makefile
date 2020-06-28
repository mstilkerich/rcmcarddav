.PHONY: all stylecheck phpcompatcheck staticanalyses psalmanalysis doc

all: staticanalyses doc

staticanalyses: phpcompatcheck psalmanalysis

stylecheck:
	vendor/bin/phpcs --colors --standard=PSR12 *.php src/

phpcompatcheck:
	vendor/bin/phpcs --colors --standard=PHPCompatibility --runtime-set testVersion 7.1 src/

psalmanalysis:
	vendor/bin/psalm

doc:
	rm -r ~/www/carddavclient/*
	#phpDocumentor.phar -d src/ -t ~/www/carddavclient --title="CardDAV Client Library" 
	../phpdocumentor/bin/phpdoc -d src/ -t ~/www/carddavclient --title="CardDAV Client Library" 
