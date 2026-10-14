# Full-store CS-Cart 4.21.2 app image (the version production runs; Apache + PHP 8.3).
FROM php:8.3-apache

# CS-Cart platform extensions — the same set docker/test-db/build.sh
# compiles for the console installer — plus pdo_mysql/mbstring used by the
# addons' data paths. mlocati's installer handles the apt libfoo-dev dance.
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions \
    && install-php-extensions mysqli pdo_mysql curl sockets gd exif soap zip intl opcache mbstring

# mod_rewrite for CS-Cart SEO URLs; AllowOverride so its .htaccess applies.
RUN a2enmod rewrite headers
COPY apache-cscart.conf /etc/apache2/conf-enabled/zz-cscart.conf

# Route every PHP mail() / PHPMailer send to the Mailpit container (SMTP :1025).
RUN apt-get update \
    && apt-get install -y --no-install-recommends msmtp unzip default-mysql-client \
    && rm -rf /var/lib/apt/lists/*
COPY msmtprc /etc/msmtprc
RUN chmod 600 /etc/msmtprc && chown www-data:www-data /etc/msmtprc

# Dev-friendly PHP: bigger limits, opcache that revalidates on every request
# so host edits to addon .php show up on the next page load, sendmail→msmtp.
COPY php-dev.ini /usr/local/etc/php/conf.d/zz-cscart-dev.ini

# Provisioning scripts (baked into the image; addon sources come from /repo).
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
COPY link-addons.sh /usr/local/bin/link-addons.sh
COPY install-addons.php /usr/local/bin/install-addons.php
RUN chmod +x /usr/local/bin/entrypoint.sh /usr/local/bin/link-addons.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
