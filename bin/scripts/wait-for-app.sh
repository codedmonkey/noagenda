#!/usr/bin/env sh

echo "Waiting for app to be ready..."

until nc -z "app" "9000"; do
  sleep 1
done

echo "App ready"

exit 0
