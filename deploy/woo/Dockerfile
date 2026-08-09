FROM wordpress:php8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/* \
    && curl -fsSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o /usr/local/bin/wp \
    && chmod +x /usr/local/bin/wp \
    && curl -fsSL https://downloads.wordpress.org/plugin/woocommerce.latest-stable.zip -o /tmp/woocommerce.zip \
    && unzip -q /tmp/woocommerce.zip -d /usr/src/wordpress/wp-content/plugins \
    && rm /tmp/woocommerce.zip

COPY plugin/woo-storefront /usr/src/wordpress/wp-content/plugins/woo-storefront
COPY deploy/woo/start.sh /usr/local/bin/woo-store-start
RUN chmod +x /usr/local/bin/woo-store-start

EXPOSE 80
CMD ["/usr/local/bin/woo-store-start"]
