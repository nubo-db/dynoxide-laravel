#!/bin/sh
# Downloads the Dynoxide 1.6.0 release binary for one target, checks it
# against the release's sha256sums.txt, unpacks it and prints its path. The
# dynoxide:serve tests use it to cover a binary without the launcher flags.
# Needs GH_TOKEN, and gh on PATH. Linux and macOS targets only.
# Usage: fetch-legacy-binary.sh <target> <directory>
set -eu
target=$1 dir=$2
asset="dynoxide-$target.tar.gz"

mkdir -p "$dir"
gh release download v1.6.0 -R nubo-db/dynoxide -p "$asset" -p sha256sums.txt -D "$dir" --clobber >&2

expected=$(awk -v asset="$asset" '$2 == asset { print $1 }' "$dir/sha256sums.txt")
if [ -z "$expected" ]; then
  echo "sha256sums.txt in the 1.6.0 release has no line for $asset." >&2
  exit 1
fi
actual=$(shasum -a 256 "$dir/$asset" | awk '{ print $1 }')
if [ "$actual" != "$expected" ]; then
  echo "$asset has sha256 $actual, but the release lists $expected." >&2
  exit 1
fi

tar -xzf "$dir/$asset" -C "$dir"
binary="$dir/dynoxide"
chmod +x "$binary"
"$binary" --version >&2
echo "$binary"
