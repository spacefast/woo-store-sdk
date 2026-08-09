#!/bin/sh
# wp-cli against the woo-e2e-wp container (default bridge, no compose network)
DBIP=$(docker inspect -f '{{.NetworkSettings.Networks.bridge.IPAddress}}' woo-e2e-db)
exec docker run --rm --user 33:33 --network bridge --volumes-from woo-e2e-wp \
  -e WORDPRESS_DB_HOST="$DBIP" -e WORDPRESS_DB_USER=wp \
  -e WORDPRESS_DB_PASSWORD=wp -e WORDPRESS_DB_NAME=wordpress \
  wordpress:cli wp "$@"
