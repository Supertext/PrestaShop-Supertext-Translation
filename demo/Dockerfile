# Supertext PrestaShop demo: PrestaShop 9 + German (CH), French and Italian + this module.
# Build context is the repository root:
#   docker build -f demo/Dockerfile -t supertext-prestashop-demo .
# Everything persistent is in MySQL (DATABASE_URL), including app/config/parameters.php;
# the container keeps no files between deploys. See docs/DEVELOPER.md -> Demo.

FROM composer:2 AS module
COPY supertext /build/supertext
RUN rm -rf /build/supertext/vendor \
 && composer dump-autoload --working-dir=/build/supertext --optimize --classmap-authoritative --no-dev

FROM prestashop/prestashop:9

COPY --from=module /build/supertext /opt/demo/supertext
COPY demo/setup.php demo/state.php demo/sample-content.json /opt/demo/
COPY demo/php.ini /usr/local/etc/php/conf.d/zz-supertext-demo.ini
COPY demo/entrypoint.sh /usr/local/bin/supertext-demo-entrypoint
RUN chmod +x /usr/local/bin/supertext-demo-entrypoint \
 # Railway's healthcheck: PrestaShop's own pages redirect to the HTTPS domain, a static file doesn't.
 && echo ok > /var/www/html/health && chown www-data:www-data /var/www/html/health

ENTRYPOINT ["supertext-demo-entrypoint"]
CMD ["apache2-foreground"]
