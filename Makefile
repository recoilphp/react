# PHP_COMPOSER_INSTALL_ARGS += --ignore-platform-reqs

# ################################################################################

-include .makefiles/Makefile
-include .makefiles/pkg/php/v1/Makefile

.makefiles/%:
	@curl -sfL https://makefiles.dev/v1 | bash /dev/stdin "$@"
