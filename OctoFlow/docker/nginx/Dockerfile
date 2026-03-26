FROM nginx:1.26-alpine

ARG HOST_UID=1000
ARG HOST_GID=1000

#RUN rm /etc/nginx/conf.d/default.conf
#COPY nginx.conf /etc/nginx/conf.d
RUN apk update
RUN apk add bash curl nano openssl
RUN apk update

RUN deluser nginx \
    && addgroup -g "${HOST_GID}" -S octoflow \
    && adduser -S -D -H -u "${HOST_UID}" -G octoflow octoflow

WORKDIR /usr/src/app
