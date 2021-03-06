FROM sadokf/php7fpm_mozjpeg

MAINTAINER sadoknet@gmail.com
ENV DEBIAN_FRONTEND=noninteractive


RUN \
  apt-get -y update && \
  apt-get -y install \
  nginx supervisor

RUN echo "deb http://packages.dotdeb.org jessie all" >> /etc/apt/sources.list.d/dotdeb.org.list && \
    echo "deb-src http://packages.dotdeb.org jessie all" >> /etc/apt/sources.list.d/dotdeb.org.list && \
    wget -O- http://www.dotdeb.org/dotdeb.gpg | apt-key add -

#PHP7 dependencies
RUN apt-get -y update && \
    apt-get -y install \
    php7.0-intl php-pear \
    php7.0-imap php7.0-mcrypt \
    php7.0-xdebug && \
    docker-php-ext-install opcache

RUN \
    echo "extension=/usr/lib/php/20151012/intl.so" > /usr/local/etc/php/conf.d/intl.ini && \
    echo "zend_extension=/usr/lib/php/20151012/xdebug.so" > /usr/local/etc/php/conf.d/xdebug.ini


#facedetect script
WORKDIR /var

RUN \
    apt-get -y install python-numpy libopencv-dev python-opencv && \
    git clone https://github.com/wavexx/facedetect.git && \
    chmod +x /var/facedetect/facedetect && \
    ln -s /var/facedetect/facedetect /usr/local/bin/facedetect

RUN echo "\nln /dev/null /dev/raw1394" >> ~/.bashrc

#install composer
RUN \
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

RUN apt-get -y upgrade nginx

#copy etc/
COPY docker/resources/etc/ /etc/

COPY .    /var/www/html

WORKDIR /var/www/html

RUN mkdir -p var/cache/ var/logs/ var/sessions/ web/uploads/.tmb && \
    chown -R www-data:www-data var/  web/uploads/ && \
    chmod 777 -R var/  web/uploads/


EXPOSE 80

CMD ["/usr/bin/supervisord"]
