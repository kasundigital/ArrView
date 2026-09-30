FROM php:8.3-cli-alpine

RUN apk add --no-cache curl-dev sqlite-dev \
    && docker-php-ext-install curl pdo_sqlite \
    && echo "memory_limit=512M" > /usr/local/etc/php/conf.d/arrview.ini

WORKDIR /app

# php -S is single-threaded unless workers are enabled. Without this, one slow
# request (diagnostics, Deep Search) blocks every page and every Arr webhook.
ENV PHP_CLI_SERVER_WORKERS=4
COPY app /app

RUN find /app -name '*.php' -print0 | xargs -0 -n1 php -l \
    && chmod +x /app/bin/entrypoint.sh \
    && mkdir -p /app/data \
    && chown -R www-data:www-data /app/data

EXPOSE 8080

USER www-data
CMD ["/app/bin/entrypoint.sh"]
