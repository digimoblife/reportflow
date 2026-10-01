ARG APP_IMAGE=reportflow-app:latest

FROM ${APP_IMAGE} AS app

FROM nginx:1.27-alpine

RUN rm -f /etc/nginx/conf.d/default.conf

COPY vps/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public
