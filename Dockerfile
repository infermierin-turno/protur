FROM php:8.2-apache

# Abilita i moduli Apache necessari per il rewrite degli URL
RUN a2enmod rewrite

# Installa le dipendenze di sistema e le estensioni PHP richieste (es. per Supabase, cURL e calendar)
RUN apt-get update && apt-get install -y \
    libicu-dev \
    libpq-dev \
    libzip-dev \
    unzip \
    git \
    && docker-php-ext-install \
    pdo \
    pdo_mysql \
    pdo_pgsql \
    pgsql \
    zip \
    intl \
    opcache \
    calendar

# Configura la cartella di lavoro del server web
WORKDIR /var/www/html

# Copia tutti i file del tuo progetto nella cartella di Apache
COPY . /var/www/html/

# Imposta i permessi corretti per i file web
RUN chown -R www-data:www-data /var/www/html

# Render assegna una porta dinamica tramite la variabile d'ambiente PORT
ENV PORT=10000
RUN sed -i 's/80/${PORT}/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf

# Esponi la porta
EXPOSE 10000
