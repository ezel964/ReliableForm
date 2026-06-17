# PHP runtime for the full-stack podman path (web pools + CLI workers).
#
# The application code is NOT copied in — compose.full.yaml mounts the repo at
# /app so edits on the host are picked up live (change ease). This image only
# provides the interpreter, the one required extension (pdo_mysql), and the
# FPM pool config. Redis and the Gearman protocol are pure-PHP in lib/, so no
# phpredis / pecl gearman extensions are needed.
FROM docker.io/library/php:8.2-fpm

RUN docker-php-ext-install pdo_mysql > /dev/null

# Replace the stock pool with ReliableForm's (listen :9000, clear_env=no so
# the container environment from compose reaches the app, /fpm-status surface).
COPY fpm-pool.conf /usr/local/etc/php-fpm.d/www.conf

WORKDIR /app
EXPOSE 9000
