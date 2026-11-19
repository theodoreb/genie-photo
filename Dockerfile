# Runs bin/start in one container, for a machine other than Linux x86-64. compose.yaml publishes the ports.
FROM debian:trixie-slim

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl git \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY . .
ENV DRUPACK_NO_UPDATE_CHECK=1
RUN bin/get-drupack \
    && drupal/drupack composer install --no-interaction --no-progress \
    && (cd drupal && ./drupack composer install --no-interaction --no-progress)

ENV GENIE_LISTEN_IP=0.0.0.0
EXPOSE 7225 8000
CMD ["bin/start"]
