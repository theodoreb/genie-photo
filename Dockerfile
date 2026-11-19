# Runs bin/start in one container, for a machine without PHP 8.4. compose.yaml publishes the ports.
FROM php:8.4-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl git patch unzip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . .
RUN composer install --no-interaction --no-progress \
    && composer --working-dir=drupal install --no-interaction --no-progress --ignore-platform-reqs \
    && bin/drupack-ffi

ENV GENIE_LISTEN_IP=0.0.0.0
EXPOSE 7225 8000
CMD ["bin/start"]
