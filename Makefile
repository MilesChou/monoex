#!/usr/bin/make -f

.PHONY: all clean clean-all check test coverage

# ---------------------------------------------------------------------

all: test

clean:
	rm -rf ./build

clean-all: clean
	rm -rf ./vendor
	rm -rf ./composer.lock

check:
	php vendor/bin/phpcs

test: clean check
	php -d xdebug.mode=coverage vendor/bin/phpunit

coverage: test
	@if [ "`uname`" = "Darwin" ]; then open build/coverage/index.html; fi
