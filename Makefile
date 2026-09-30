# Pinned deliberately. openapi-generator ships no patch releases and no major
# since 2023, so every upgrade available is a minor, which is the tier its own
# policy says may change template-bound variables. php-nextgen is also marked
# beta upstream. Bumping this is a human-reads-the-diff operation, never
# automatic. See CONTRIBUTING.md.
OPENAPI_GENERATOR_VERSION := 7.25.0

# Pinned for the same reason the generator is: it decides whether we publish.
# Keep in step with the env block in .github/workflows/sync.yml.
OASDIFF_VERSION  := 1.32.1
# Versioned, like the generator jar: an unversioned path would keep serving a
# stale binary after OASDIFF_VERSION is bumped.
OASDIFF          := /tmp/oasdiff-$(OASDIFF_VERSION)
# oasdiff ships one universal darwin build and per-arch linux builds.
OASDIFF_OS       := $(shell uname -s | tr 'A-Z' 'a-z')
OASDIFF_PLATFORM := $(if $(filter darwin,$(OASDIFF_OS)),darwin_all,$(OASDIFF_OS)_$(shell uname -m | sed 's/x86_64/amd64/;s/aarch64/arm64/'))

SCHEMA_URL := https://api.incident.io/v1/openapiV3.json
GENERATOR  := /tmp/openapi-generator-cli-$(OPENAPI_GENERATOR_VERSION).jar

# Overridable so the targets run without a local PHP, e.g.
#   make test PHP="docker run --rm -v $$PWD:/app -w /app php:8.1-cli php" \
#             COMPOSER="docker run --rm -v $$PWD:/app -w /app composer:2"
PHP      ?= php
COMPOSER ?= composer

.DEFAULT_GOAL := help
.PHONY: help fetch generate verify test surface template-drift oasdiff clean

help:
	@grep -E '^[a-z-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

# -L because without it a redirect is a silent success writing zero bytes,
# which parses as an empty schema. --compressed because the endpoint serves
# gzip, which is 434KB against 7MB, and this runs hourly. OUT lets the release
# workflow fetch to a scratch path so it still has the previous schema to diff
# against.
OUT ?= openapi.json

fetch: ## Fetch the live schema (OUT= to write elsewhere)
	curl -sfSL --compressed $(SCHEMA_URL) -o $(OUT)

$(GENERATOR):
	curl -sfSL -o $@ \
		https://repo1.maven.org/maven2/org/openapitools/openapi-generator-cli/$(OPENAPI_GENERATOR_VERSION)/openapi-generator-cli-$(OPENAPI_GENERATOR_VERSION).jar

# .openapi-generator-ignore keeps the generator out of composer.json, the README
# and the rest of what we own.
#
# variableNamingConvention=camelCase because the parameter and property names
# are what PHP 8 named arguments and the model constructor arrays use, and
# camelCase is what PHP code expects. The generator's docs and stub tests are
# switched off: the tests are empty placeholders, and the docs would add 5MB
# nobody reads outside an IDE.
generate: openapi.json $(GENERATOR) ## Regenerate the client from the committed schema
	# Cleared first: the generator only writes, never deletes, so an endpoint or
	# model removed upstream would otherwise leave a stale class behind that
	# the autoloader still finds.
	rm -rf src .openapi-generator
	java -jar $(GENERATOR) generate \
		--input-spec openapi.json \
		--generator-name php-nextgen \
		--output . \
		--additional-properties=invokerPackage=IncidentIo,variableNamingConvention=camelCase,httpUserAgent=incident-io-sdk-php/dev \
		--global-property=apiDocs=false,modelDocs=false,apiTests=false,modelTests=false \
		> /tmp/openapi-generator.log 2>&1 || (tail -40 /tmp/openapi-generator.log && exit 1)
	python3 scripts/fix_generated.py src openapi.json

# There is no compile step, so this is the whole gate between a generation and
# Packagist: composer's own checks, loading every class through the autoloader,
# and the API surface check. Loading a file parses it, so a separate php -l
# pass would catch nothing more and costs a process per file; scripts/verify.php
# says what loading catches that php -l does not.
verify: ## Validate the package, load every class, check the API surface
	$(COMPOSER) validate --strict
	$(COMPOSER) install --no-interaction --no-progress --quiet
	$(PHP) scripts/verify.php check api-surface.txt

test: verify ## Everything verify does, plus the tests
	$(PHP) vendor/bin/phpunit

# Rewrites the baseline from the current src/. The release does this after the
# check, so additions and removals are both recorded.
surface: ## Record the current public API as the baseline
	$(COMPOSER) install --no-interaction --no-progress --quiet
	$(PHP) scripts/verify.php write api-surface.txt

# We deliberately do not fork the generator's templates (scripts/fix_generated.py
# explains why), so templates/pristine/ holds unmodified upstream copies, used
# for nothing but this check. The generator is never invoked with -t.
#
# The rewrites anchor on what these templates emit. An upstream edit shows up
# as a rewrite matching nothing, which stops the release; an upstream rename or
# split of a template would not show up at all. So assert both: the template
# still exists under the same name, and it still says what the anchors were
# written against.
template-drift: $(GENERATOR) ## Fail if the generator's templates moved under us
	rm -rf /tmp/upstream-templates-php
	java -jar $(GENERATOR) author template --generator-name php-nextgen --output /tmp/upstream-templates-php >/dev/null 2>&1
	# api for the filter call sites and @deprecated, model_generic for the enum
	# checks, ObjectSerializer for the filter encoding, Configuration for the
	# user agent, partial_header for the file header.
	#
	# A flag rather than `exit 1` inside the loop: make runs the recipe under
	# plain `sh -c`, where a loop's status is its last iteration's.
	@set -e; \
	drifted=""; \
	for t in api model_generic ObjectSerializer Configuration partial_header; do \
		if ! test -f /tmp/upstream-templates-php/$$t.mustache; then \
			echo "$$t.mustache is gone from the generator: scripts/fix_generated.py may no longer apply"; \
			drifted="yes"; \
		elif ! diff -u "templates/pristine/$$t.mustache" "/tmp/upstream-templates-php/$$t.mustache"; then \
			echo ""; \
			echo "The generator's $$t.mustache changed. Read the diff, re-check that"; \
			echo "scripts/fix_generated.py still applies, then copy the new file over"; \
			echo "templates/pristine/."; \
			drifted="yes"; \
		fi; \
	done; \
	test -z "$$drifted"

# The schema gate that makes a release a major, runnable by hand.
oasdiff: $(OASDIFF) ## Diff the live schema against the committed one, as the release does
	@$(MAKE) --no-print-directory fetch OUT=/tmp/openapi.json.new
	# curl -f passes a 200 with an empty or truncated body, and oasdiff reads an
	# empty file as every path having been removed.
	@python3 -c "import json; d=json.load(open('/tmp/openapi.json.new')); n=len(d.get('paths') or {}); \
		exit(0) if n >= 100 else exit(f'only {n} paths in the fetched schema')"
	$(OASDIFF) breaking openapi.json /tmp/openapi.json.new \
		--severity-levels oasdiff-severity.txt --fail-on ERR

$(OASDIFF):
	curl -sfSL "https://github.com/oasdiff/oasdiff/releases/download/v$(OASDIFF_VERSION)/oasdiff_$(OASDIFF_VERSION)_$(OASDIFF_PLATFORM).tar.gz" \
		| tar -xzO oasdiff > $@
	chmod +x $@

clean: ## Remove installed dependencies and caches
	rm -rf vendor .phpunit.cache
