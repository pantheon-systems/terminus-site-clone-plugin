**Warning** this plugin is still in development. Use at your own risk.

# Terminus Site Clone
Site Clone - A [Terminus](http://github.com/pantheon-systems/terminus) plugin that adds command(s) to facilitate cloning sites on [Pantheon](https://pantheon.io/).

## Requirements
* [Terminus](https://github.com/pantheon-systems/terminus)
* [git command line](https://git-scm.com/book/en/v2/Getting-Started-Installing-Git)

## Usage
`terminus site:clone <source>.<env> <destination>.<env>` where `<source>` and `<destination>` are site UUID or machine name and `<env>` is a valid environment (dev, test, live or multidev). You can also pass the argument(s) `--no-database`, `--no-files` and `--no-code` to skip cloning one or more items. You cannot, however, skip all three.

## License
MIT