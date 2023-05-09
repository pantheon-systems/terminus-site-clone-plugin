# Terminus Site Clone
Site Clone - A [Terminus](http://github.com/pantheon-systems/terminus) plugin that adds the `site:clone` command to facilitate cloning sites on [Pantheon](https://pantheon.io/).

[![Actively Maintained](https://img.shields.io/badge/Pantheon-Actively_Maintained-yellow?logo=pantheon&color=FFDC28)](https://pantheon.io/docs/oss-support-levels#actively-maintained-support)

## Disclaimer
While this script has worked well for us your mileage may vary due to local machine configuration. If you are having issues with running this plugin locally try using [this Dockerfile](https://github.com/pantheon-systems/docker-build-tools-ci/blob/4.x/Dockerfile), which has all the tools needed pre installed.

## Installation

### Installing via Terminus 3
`terminus self:plugin:install pantheon-systems/terminus-site-clone-plugin`

### Installing with Composer (deprecated method using Terminus 2)
`composer -n create-project pantheon-systems/terminus-site-clone-plugin:^2 ~/.terminus/plugins/terminus-site-clone-plugin`

### Manual installation
Clone this project into your Terminus plugins directory found at `$HOME/.terminus/plugins`. If the `$HOME/.terminus/plugins` directory does not exists you can safely create it. You will also need to run `composer install` in the plugin directory after cloning it. See [installing Terminus plugin](https://pantheon.io/docs/terminus/plugins/#install-plugins) for details.

## Requirements
* [Terminus](https://github.com/pantheon-systems/terminus) `2.0` or greater
* [git command line](https://git-scm.com/book/en/v2/Getting-Started-Installing-Git) `1.7.10` or greater

## Usage
`terminus site:clone <source>.<env> <destination>.<env>` where `<source>` and `<destination>` are site UUID or machine name and `<env>` is a valid environment (dev or multidev).

Code cannot be cloned to or from test and live environments as work must go through [the Pantheon workflow](https://pantheon.io/docs/pantheon-workflow/). You can, however, use `--no-code` to clone the files and database to or from a test or live environment. Note that if you use `--no-code`, the PHP version won't be set to the one from the site you are cloning from.

You can also pass the argument(s) `--no-database`, `--no-files` and/or `--no-code` to skip cloning one or more items. You cannot, however, skip all three as there would be nothing left to clone.

By default, backups are made on both the source and destination environment before cloning. Use `--no-source-backup` and/or `--no-destination-backup` to omit one of both backups.

## Notes

- Files and database backups over 500MBs will not work** due to Pantheon import file size limits. If your files or database are over 500MB they will need to be [manually migrated](https://pantheon.io/docs/migrate-manual/).
- If you pass the `--no-source-backup` flag, the system will use the last taken backup as the source database.

## Changelog

### `2.0.0`
* Add support for Terminus `2.0`
* Remove support for Terminus `1.x`
* Separate options for backing up source and destination
* Use `git clone --single-branch` to avoid downloading unnecessary history

### `1.0.0`
* Initial release

## License
MIT
