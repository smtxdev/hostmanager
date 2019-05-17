# Hostmanager

Updates automatically your hosts file (Mac / Linux: `/etc/hosts` - Windows: `c:/Windows/System32/drivers/etc/hosts`) for example for local development.

Can run as deamon/service to watch special env-vars which shall be written to the hosts file. Or just run it as a normal command and pass the hostnames as an option.

## Installation

```
composer config repositories.repo-name vcs https://github.com/smtxdev/hostmanager
composer require smtxdev/hostmanager:dev-master
```

## Usage

```
./vendor/bin/hostmanager --hostnames exmaple.de,exmaple2.de
```

## Parameters

```
./vendor/bin/hostmanager --help
```
