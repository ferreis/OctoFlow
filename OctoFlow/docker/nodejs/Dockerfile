FROM node:latest

ARG HOST_UID=1000
ARG HOST_GID=1000

RUN apt update -y
RUN apt install -y bash nano curl

RUN groupmod -o -g "${HOST_GID}" node \
    && usermod -o -u "${HOST_UID}" -g "${HOST_GID}" node

RUN mkdir -p /home/node/app/node_modules && chown -R node:node /home/node/app
WORKDIR /home/node/app

#COPY --chown=node:node ./node_app/ ./

#COPY --chown=node:node . .


RUN chown -R node:node /home/node/app 
ADD /docker/nodejs/node_entrypoint.sh /docker-entrypoint.sh
RUN chmod +x /docker-entrypoint.sh
#RUN npm install -g nodemon
USER node
CMD /docker-entrypoint.sh

#CMD [ "node", "app.js" ]
#CMD [ "/bin/bash" ]
