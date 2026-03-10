FROM nginx:1.26-alpine

#RUN rm /etc/nginx/conf.d/default.conf
#COPY nginx.conf /etc/nginx/conf.d
RUN apk update
RUN apk add bash curl nano openssl
RUN apk update

WORKDIR /usr/src/app