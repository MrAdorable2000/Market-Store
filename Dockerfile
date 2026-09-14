FROM dunglas/frankenphp:php8.3-bookworm

RUN install-php-extensions pdo_mysql gd

WORKDIR /app

COPY . /app

EXPOSE 8080

CMD ["frankenphp", "php-server", "--listen", ":8080", "--root", "/app"]
