FROM redis:7.2-alpine

ARG HOST_UID=1000
ARG HOST_GID=1000

RUN apk update \
    && apk add --no-cache bash curl nano \
    && rm -rf /var/cache/apk/*

WORKDIR /data

RUN chown -R redis:redis /data

USER redis
