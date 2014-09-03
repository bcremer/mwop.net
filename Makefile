# mwop.net Makefile
#
# Primary purpose is for deployment
#
# Configurable variables:
# - PHP      - PHP executable to use, if not in path
# - SITE     - Site deploying to (defaults to mwop.net)
# - VERSION  - Version name to use; defaults to a timestamp
# - CONFIGS  - Path to directory containing deployment-specific configs
# - ZSCLIENT - Path to zs-client.phar (defaults to zs-client.phar)
# - ZSTARGET - Target for zs-client.phar (defaults to mwop)
# - APPID    - Application ID on Zend Server (defaults to 25)
#
# Available targets:
# - composer - update the composer executable
# - grunt    - run grunt to minimize CSS
# - zpk      - build a zpk
# - deploy   - deploy the site
# - all      - synonym for deploy target

PHP ?= $(shell which php)
SITE ?= https://mwop.net
VERSION ?= $(shell date -u +"%Y.%m.%d.%H.%M")
CONFIGS ?= $(CURDIR)/../settings.mwop.net
ZSCLIENT ?= zs-client.phar
ZSTARGET ?= mwop
APPID ?= 25
GIT = $(shell which git)

COMPOSER = $(CURDIR)/composer.phar

.PHONY : all composer sitesub pagerules grunt zpk deploy clean

all : deploy

composer :
	@echo "Ensuring composer is up-to-date..."
	-$(COMPOSER) self-update
	@echo "[DONE] Ensuring composer is up-to-date..."

sitesub :
	@echo "Injecting site name into deploy scripts..."
	-sed --in-place -r -e "s#server \= '[^']+'#server = '$(SITE)'#" $(CURDIR)/zpk/scripts/post_activate.php
	@echo "[DONE] Injecting site name into deploy scripts..."

pagerules :
	@echo "Configuring page cache rules..."
	-$(GIT) checkout -- zpk/scripts/pagecache_rules.xml
	-$(PHP) $(CURDIR)/bin/mwop.net.php prep-page-cache-rules --appId=$(APPID) --site=$(SITE)
	@echo "[DONE] Configuring page cache rules..."

grunt :
	@echo "Running grunt to minimize CSS..."
	-grunt
	@echo "[DONE] Running grunt to minimize CSS..."

zpk : composer sitesub pagerules grunt
	@echo "Creating zpk..."
	-$(CURDIR)/vendor/bin/zfdeploy.php build mwop-$(VERSION).zpk --configs=$(CONFIGS) --zpkdata=$(CURDIR)/zpk --version=$(VERSION)
	@echo "[DONE] Creating zpk."

deploy : zpk
	@echo "Deploying ZPK..."
	-$(ZSCLIENT) applicationUpdate --appId=25 --appPackage=mwop-$(VERSION).zpk --target=$(ZSTARGET)
	@echo "[DONE] Deploying ZPK."

clean :
	@echo "Cleaning up..."
	-rm -Rf $(CURDIR)/*.zpk
	@echo "[DONE] Cleaning up."
