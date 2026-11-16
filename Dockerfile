# The gate's image, ghcr.io/nightworksio/mutation-gate (ADR-0022): PHP's CLI
# with pcov, pcntl, git, unzip and Composer, and the gate's PHAR on the path as
# mutation-gate, run in /app. It runs the project's own tests, so a project
# extends it with the extensions its suite needs. A release builds it from the
# PHAR composer phar wrote into build/.
FROM php:8.5-cli@sha256:50956f094e90a716d43bea28087d447555ad80510e386ab8c9c10d8c70d781a9

RUN apt-get update \
    && apt-get install --yes --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/* \
    && pecl install pcov-1.0.12 \
    && docker-php-ext-enable pcov \
    && docker-php-ext-install pcntl

COPY --from=composer:2@sha256:b6dcd72ca98fa19d0e5b8476de179859704891fad06dd9e0b96833d4c430a5fc /usr/bin/composer /usr/local/bin/composer
COPY build/mutation-gate.phar /usr/local/bin/mutation-gate

WORKDIR /app

ENTRYPOINT ["mutation-gate"]
