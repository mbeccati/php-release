#!/bin/bash
set -e

if [ -z "$1" ]; then
  echo "Usage: $0 <release-version>"
  exit 1
fi

RELEASE_VERSION=$1

# Translate version
VERSION_MAJOR=$(echo "$RELEASE_VERSION" | cut -d "." -f 1)
VERSION_MINOR=$(echo "$RELEASE_VERSION" | cut -d "." -f 2)
VERSION_PATCH=$(echo "$RELEASE_VERSION" | cut -d "." -f 3 | egrep -o "[0-9]+" | head -n 1)
if [ "$VERSION_PATCH" != "$(echo $1 | cut -d '.' -f 3)" ]; then
  # This is an alpha/beta/RC
  OFFSET=$((${#VERSION_PATCH}+1))
  VERSION_EXTRA=$(echo "$RELEASE_VERSION" | cut -d "." -f 3 | cut -c "$OFFSET"-)
else
  VERSION_EXTRA=""
fi
