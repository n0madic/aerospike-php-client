#!/bin/bash

# Aerospike PHP8 install and build script for linux

set -e

SCRIPT_PATH="$( cd -- "$(dirname "$0")" >/dev/null 2>&1 ; pwd -P )"

PROJ_FOLDER="php-client"


apt update


#install git, if needed
if ! command -v git >/dev/null 2>&1; then
  printf 'git was not installed.  Installing git...\n'
  apt -y install git
else
  printf 'git was already installed!\n'
fi


#determine if the script is being run via direct download or from within the repo
if [[ ${SCRIPT_PATH} == *${PROJ_FOLDER}* ]]; then
  echo "script is in the repo - no need to clone"
else
  echo "script is NOT in the repo - cloning..."
  #clone repo & cd into project folder:
  if ! git clone https://github.com/aerospike/php-client.git "${PROJ_FOLDER}" 2>/dev/null && [ -d "${PROJ_FOLDER}" ] ; then
    printf 'Git clone failed. Target folder exists. Assuming clone was already completed & continuing...\n'
  fi
  cd ${SCRIPT_PATH}/${PROJ_FOLDER}
fi


if [[ ${PWD} != *${PROJ_FOLDER}* && -d ${PROJ_FOLDER} ]]; then
  cd ${PROJ_FOLDER}
else
  if [[ ${PWD} == *build ]]; then
    echo "running in build directory!"
    cd ..
  fi
fi

pwd
#NOTE: we should now be in the project root, regardless of where the script is or where it was run from


#install PHP 8 if needed
if ! php -v 2>/dev/null | grep -q 'PHP 8'; then
  printf 'PHP 8 was not installed.\n'
  if apt-cache search php8.4 | grep -q 'php8.4'; then
    printf 'Installing PHP 8.4...\n'
    apt -y install php8.4
  elif apt-cache search php8.3 | grep -q 'php8.3'; then
    printf 'Installing PHP 8.3...\n'
    apt -y install php8.3
  fi
else
  printf 'PHP 8 was already installed!\n'
fi


#install php-dev, if needed
if ! command -v php-config >/dev/null 2>&1; then
  printf 'php-dev was not installed.\n'
  printf 'Installing php-dev...\n'
  apt -y install php-dev
else
  printf 'php-dev was already installed!\n'
fi


#install PHPUnit, if needed
if ! command -v phpunit >/dev/null 2>&1; then
  printf 'phpunit was not installed.  Installing phpunit...\n'
  apt -y install phpunit
else
  printf 'phpunit was already installed!\n'
fi


#install curl, if needed
if ! command -v curl >/dev/null 2>&1; then
  printf 'curl was not installed.  Installing curl...\n'
  apt -y install curl
else
  printf 'curl was already installed!\n'
fi


#install OpenSSL development headers (libssl-dev) — required by aerospike-client-rust TLS feature.
if ! dpkg -s libssl-dev >/dev/null 2>&1; then
  printf 'libssl-dev was not installed.  Installing libssl-dev...\n'
  apt -y install libssl-dev
else
  printf 'libssl-dev was already installed!\n'
fi


#install latest rustup via curl, if needed
if ! command -v rustup >/dev/null 2>&1; then
  printf "rustup was not installed.  Installing rustup...\n"
  curl https://sh.rustup.rs -sSf | sh -s -- -y
  . "$HOME/.cargo/env"
else
  printf "rustup was already installed!\n"
fi


# v2 (native client) no longer requires Go or protoc — the Rust extension
# talks to Aerospike directly via the official aerospike-client-rust crate.


#install build-essential meta package, if needed
if ! command -v make >/dev/null 2>&1; then
  printf "build-essential was not installed.  Installing build-essential...\n"
  apt-get -y install build-essential
else
  printf "build-essential was already installed!\n"
fi


#install clang, if needed
if ! command -v clang >/dev/null 2>&1; then
  printf "clang was not installed.  Installing clang...\n"
  apt-get -y install clang
else
  printf "clang was already installed!\n"
fi


#install pkg-config (required by openssl-sys), if needed
if ! command -v pkg-config >/dev/null 2>&1; then
  printf "pkg-config was not installed.  Installing pkg-config...\n"
  apt-get -y install pkg-config
else
  printf "pkg-config was already installed!\n"
fi


#Build & install PHP client
make

echo "Installation complete!"

# Configure your Aerospike connection in your PHP code via Client::connect(hosts, policy)
