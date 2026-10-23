FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libicu-dev libzip-dev libpng-dev libjpeg-dev libfreetype6-dev msmtp-mta \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" mysqli pdo_mysql gd intl zip \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# Todo correo que envíe PHP (mail()) va a Mailpit, nunca a destinatarios reales.
RUN printf 'account default\nhost mail\nport 1025\nfrom agenda@localhost\n' > /etc/msmtprc \
    && printf 'sendmail_path = "/usr/bin/msmtp -t"\ndate.timezone = America/Santiago\nupload_max_filesize = 20M\npost_max_size = 20M\n' > /usr/local/etc/php/conf.d/local.ini
