**Warning** this plugin is still in development. Use at your own risk.

# Terminus Site Clone
Site Clone - A [Terminus](http://github.com/pantheon-systems/terminus) plugin that adds the `site:clone` command to facilitate cloning sites on [Pantheon](https://pantheon.io/).

## Installation
Clone this project into your Terminus plugins directory found at `$HOME/.terminus/plugins`. If the `$HOME/.terminus/plugins` directory does not exists you can safely create it. See [installing Terminus plugin](https://pantheon.io/docs/terminus/plugins/#install-plugins) for details.

## Requirements
* [Terminus](https://github.com/pantheon-systems/terminus) `1.1.1` or greater
* [git command line](https://git-scm.com/book/en/v2/Getting-Started-Installing-Git)

## Usage
`terminus site:clone <source>.<env> <destination>.<env>` where `<source>` and `<destination>` are site UUID or machine name and `<env>` is a valid environment (dev or multidev).

The test and live environment cannot be cloned to or from as work must go through [the Pantheon workflow](https://pantheon.io/docs/pantheon-workflow/).

You can also pass the argument(s) `--no-database`, `--no-files` and/or `--no-code` to skip cloning one or more items. You cannot, however, skip all three as there would be nothing left to clone.

## License
MIT