# Base image
FROM ubuntu:16.04

# Install packages
RUN apt-get update && apt-get install -my \
  nginx \
  nano \
  mc \
  supervisor \
  curl \
  wget \
  gcc \
  make \
  libcurl4-openssl-dev \
  libevent-dev \
  git \
  pkg-config \
  php7.0-cli \
  php7.0-dev \
  php7.0-curl \
  php7.0-fpm \
  php7.0-gd \
  php7.0-pgsql \
  php7.0-mcrypt \
  php7.0-mbstring \
  php-xdebug \
  php-pear

# Install PHP modules.
RUN pecl install event eio
RUN echo "extension=event.so" > /etc/php/7.0/mods-available/event.ini
RUN echo "extension=eio.so" > /etc/php/7.0/mods-available/eio.ini

# Creating links
RUN ln -s /etc/php/7.0/mods-available/event.ini /etc/php/7.0/cli/conf.d/event.ini
RUN ln -s /etc/php/7.0/mods-available/eio.ini /etc/php/7.0/cli/conf.d/eio.ini

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin/ --filename=composer

# Prepare a folder for PHPDaemon.
RUN mkdir -p /opt/phpdaemon
# Installing PHPDaemon.
RUN cd /opt/phpdaemon
RUN git clone https://github.com/kakserpom/phpdaemon.git /opt/phpdaemon
# Let's create an alias of `phpd` for convenience.
RUN echo "alias phpd='/opt/phpdaemon/bin/phpd'" >> ~/.bashrc

# Install npm && bower
RUN curl -sL https://deb.nodesource.com/setup_6.x -o nodesource_setup.sh
RUN bash nodesource_setup.sh
RUN apt-get install nodejs -y
RUN npm install -g bower gulp gulp-concat

# Add configuration files
COPY conf/php.ini /etc/php/7.0/mods-available/40-custom.ini
COPY ./files /

# Edit configuration
RUN cd / && \
  rm -rf /tmp/composer && \
  apt-get autoremove -y && \
  rm -rf /var/lib/apt/lists/* && \
  sed -i -e 's/^user = www-data$/;user = www-data/g' /etc/php/7.0/fpm/pool.d/www.conf && \
  sed -i -e 's/^group = www-data$/;group = www-data/g' /etc/php/7.0/fpm/pool.d/www.conf && \
  sed -i -e 's/^listen.owner = www-data$/;listen.owner = www-data/g' /etc/php/7.0/fpm/pool.d/www.conf && \
  sed -i -e 's/^listen.group = www-data$/;listen.group = www-data/g' /etc/php/7.0/fpm/pool.d/www.conf && \
  sed -i -e 's/max_execution_time = 30/max_execution_time = 300/g' /etc/php/7.0/fpm/php.ini && \
  sed -i -e 's/upload_max_filesize = 2M/upload_max_filesize = 50M/g' /etc/php/7.0/fpm/php.ini && \

  sed -i -e  '/^;clear_env = no/s/^;//' /etc/php/7.0/fpm/php.ini && \
  sed -i -e  '/^;ping\.path/s/^;//' /etc/php/7.0/fpm/php.ini && \
  sed -i -e  '/^;pm\.status_path/s/^;//' /etc/php/7.0/fpm/php.ini && \

  sed -i -e 's/post_max_size = 8M/post_max_size = 50M/g' /etc/php/7.0/fpm/php.ini

RUN rm -rf /etc/nginx/sites-enabled/default /etc/nginx/sites-available/* && \
  sed -i -e 's/fastcgi_param  SERVER_PORT        $server_port;/fastcgi_param  SERVER_PORT        $http_x_forwarded_port;/g' /etc/nginx/fastcgi.conf && \
  sed -i -e 's/fastcgi_param  SERVER_PORT        $server_port;/fastcgi_param  SERVER_PORT        $http_x_forwarded_port;/g' /etc/nginx/fastcgi_params && \
  sed -i -e 's/index index.html/index index.php index.html/g' /etc/nginx/sites-enabled/site.conf && \
  sed -i -e '/sendfile on;/a\        fastcgi_read_timeout 300\;' /etc/nginx/nginx.conf

RUN mkdir --mode 777 /var/run/php && \
    chmod 755 /var/www && \
    chmod 755 /var/run && \
    mkdir /var/www/web && \
    chmod -R 777 /var/www/web /var/log && \
    chmod 666 /etc/nginx/sites-enabled/site.conf && \
    chmod 666 /etc/supervisor/conf.d/nginx.conf && \
    chmod 666 /etc/supervisor/conf.d/php-fpm.conf

ENV TERM xterm

################################################################################
# Work dir
################################################################################

WORKDIR "/var/www"

################################################################################
# Entrypoint
################################################################################

ENTRYPOINT ["/usr/bin/supervisord"]
