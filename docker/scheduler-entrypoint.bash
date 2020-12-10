#!/bin/bash

set -e

>&2 echo "Waiting for app to be ready..."
until nc -z "app" "9000"; do
  sleep 1
done

cron

exec $@
