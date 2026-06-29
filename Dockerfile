# Build PHP releases with ease
# Based on dshafik/php-build, but removes interactivity requirement

# Build with: docker build -t $USER/php-release .
# Run with:
# docker run -it --rm -v/path/to/workspace:/workspace $USER/php-release

FROM ubuntu:resolute
LABEL org.opencontainers.image.authors=mbeccati@php.net

RUN apt-get update && apt-get update --fix-missing && \
    apt-get install --yes re2c build-essential git autoconf bison wget

VOLUME ["/workspace"]

COPY ./build.sh /build.sh
CMD ["/build.sh"]
