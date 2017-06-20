FROM php:7.1-alpine

RUN \
    
    apk update && apk upgrade && \

    # install dependencies
   apk add \
       postgresql-dev \
       icu \
       icu-libs \
       icu-dev \
       freetype-dev \
       libjpeg-turbo-dev \
       libpng-dev \
       libxml2-dev \

   && \

   # install useful PHP extensions
   docker-php-ext-install \
       opcache \
       mysqli \
       pdo_mysql \
       pdo_pgsql \
       intl \
       bcmath \
       zip \
       gd \
       soap \

   && \

    # install Composer
    cd /tmp && curl -sS https://getcomposer.org/installer | php  -- --install-dir=/usr/bin --filename=composer

# create src directory to store action files
RUN mkdir -p /action/src

# install Composer dependencies
COPY composer.json /action
RUN cd /action && /usr/bin/composer install --no-plugins --no-scripts --prefer-dist --no-dev -o && rm composer.lock

# Run the actionproxy webserver on port 8080
RUN apk add gcc python3 python3-dev musl-dev
RUN pip3 install --upgrade pip

RUN pip3 install gevent
RUN pip3 install flask

COPY actionproxy.py /action
COPY phprunner.py /action
COPY runner.php /action

RUN chmod a+r /action/runner.php

EXPOSE 8080
ENV FLASK_PROXY_PORT 8080
CMD ["/bin/sh", "-c", "cd /action && PYTHONIOENCODING='utf-8' python3 -u phprunner.py"]
