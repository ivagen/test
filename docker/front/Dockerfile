################################################################################
# Base image
################################################################################

FROM nginx

################################################################################
# Build instructions
################################################################################

# Remove default nginx configs.
RUN rm -f /etc/nginx/conf.d/*

# Install packages
RUN apt-get update && apt-get install -my \
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
  php5-curl \
  php5-fpm \
  php5-gd \
  php5-memcached \
  php5-mysql \
  php5-pgsql \
  php5-mcrypt \
  php5-sqlite \
  php5-xdebug \
  php5-cli \
  php5-dev \
  php-pear

#Then install PHP modules.
RUN pecl install event eio
RUN echo "extension=event.so" > /etc/php5/mods-available/event.ini
RUN echo "extension=eio.so" > /etc/php5/mods-available/eio.ini

#Creating links
RUN ln -s /etc/php5/mods-available/event.ini /etc/php5/cli/conf.d/event.ini
RUN ln -s /etc/php5/mods-available/eio.ini /etc/php5/cli/conf.d/eio.ini

# Install Composer and make it available in the PATH
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin/ --filename=composer

# Ensure that PHP5 FPM is run as root.
RUN sed -i "s/user = www-data/user = root/" /etc/php5/fpm/pool.d/www.conf
RUN sed -i "s/group = www-data/group = root/" /etc/php5/fpm/pool.d/www.conf

# Pass all docker environment
RUN sed -i '/^;clear_env = no/s/^;//' /etc/php5/fpm/pool.d/www.conf

# Get access to FPM-ping page /ping
RUN sed -i '/^;ping\.path/s/^;//' /etc/php5/fpm/pool.d/www.conf
# Get access to FPM_Status page /status
RUN sed -i '/^;pm\.status_path/s/^;//' /etc/php5/fpm/pool.d/www.conf

# Prevent PHP Warning: 'xdebug' already loaded.
# XDebug loaded with the core
RUN sed -i '/.*xdebug.so$/s/^/;/' /etc/php5/mods-available/xdebug.ini

# Install HHVM
RUN apt-key adv --recv-keys --keyserver hkp://keyserver.ubuntu.com:80 0x5a16e7281be7a449
RUN echo deb http://dl.hhvm.com/debian jessie main | tee /etc/apt/sources.list.d/hhvm.list
RUN apt-get update && apt-get install -y hhvm

# Install npm && bower
RUN apt-get install -y nodejs-legacy npm git git-core
RUN npm install -g bower gulp gulp-concat

#Prepare a folder for PHPDaemon.
RUN mkdir -p /opt/phpdaemon
#Installing PHPDaemon.
RUN cd /opt/phpdaemon
RUN git clone https://github.com/kakserpom/phpdaemon.git /opt/phpdaemon
#Creating configuration file from sample.
COPY conf/phpd.conf /opt/phpdaemon/conf/phpd.conf
#Let's create an alias of `phpd` for convenience.
RUN echo "alias phpd='/opt/phpdaemon/bin/phpd'" >> ~/.bashrc

# Add configuration files
COPY conf/nginx.conf /etc/nginx/
COPY conf/supervisord.conf /etc/supervisor/conf.d/
COPY conf/php.ini /etc/php5/fpm/conf.d/40-custom.ini

#ENV PS1 '\[\033[01;31m\]\u\[\033[01;33m\]@\[\033[01;36m\]\h \[\033[01;33m\]\w \[\033[01;35m\]\$ \[\033[00m\]'

ENV TERM xterm

################################################################################
# Volumes
################################################################################

VOLUME ["/var/www", "/etc/nginx/conf.d"]

################################################################################
# Ports
################################################################################

EXPOSE 80 443 9000

################################################################################
# Entrypoint
################################################################################

ENTRYPOINT ["/usr/bin/supervisord"]
