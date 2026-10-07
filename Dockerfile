FROM php:8.3-apache

RUN docker-php-ext-install pdo_mysql \
 && a2enmod headers \
 && sed -ri 's#/var/www/html#/var/www/app/public#g' /etc/apache2/sites-available/000-default.conf \
 && printf '<Directory /var/www/app/public>\n  FallbackResource /index.php\n  AllowOverride None\n  Require all granted\n</Directory>\n' > /etc/apache2/conf-enabled/app.conf

WORKDIR /var/www/app
COPY . .
RUN mkdir -p database && chown -R www-data:www-data database

# aplica o schema na subida; SEED_DEMO=1 cria os dados de demonstração se o banco estiver vazio
CMD ["sh", "-c", "php bin/migrate.php && if [ \"$SEED_DEMO\" = \"1\" ]; then php bin/seed.php; fi && chown -R www-data:www-data database && apache2-foreground"]
