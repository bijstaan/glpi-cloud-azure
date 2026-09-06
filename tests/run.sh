#!/bin/sh
# The suites that need nothing but PHP.
#
#   glpi-cloud-azure/tests/run.sh
#
# types.php and rows.php cover what a resource *is* — the type map and the
# state ladder, where a mistake looks like a working plugin reporting an estate
# where everything is fine. graph.php covers paging and resumption, where a
# mistake looks like a small subscription. cost.php covers the column mapping,
# where a mistake looks like a plausible number on an invoice. descriptor.php
# hands the whole descriptor to glpi-cloud's own registry, and skips itself if
# that plugin is not checked out beside this one.
#
# tests/http.php is NOT run here: it needs Guzzle from GLPI's vendor tree, so it
# runs in the container. It still contacts nothing.
#
#   docker compose -p glpi exec glpi \
#     php /var/www/glpi/plugins/glpicloudazure/tests/http.php
set -e

cd "$(dirname "$0")/.."

status=0
php tests/types.php      || status=1
php tests/rows.php       || status=1
php tests/graph.php      || status=1
php tests/cost.php       || status=1
php tests/descriptor.php || status=1

exit $status
