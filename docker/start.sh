#!/bin/sh
set -eu
export APP_SECRET="$(cat /app/var/app-secret)"
exec "$@"
