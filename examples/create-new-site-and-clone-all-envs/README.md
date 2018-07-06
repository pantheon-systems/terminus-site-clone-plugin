# Terminus Site Clone Plugin Create a New Site and Clone All Environments Example

## Functionality

This script creates a new site and then uses the Terminus site clone plugin to copy the dev, test, live and all multidev environments from a source site to the new site.

## Purpose

This script was written to handle renaming sites on Pantheon as there is not currently functionality to do so.

## Disclaimer

The script is a proof of concept example, use at your own risk.

**Warning:** this script creates a new site and clones all content, including potentially sensitive information, from another site. It is for advanced users who are familiar with Terminus, scripting, the the nuances of the sites they manage, and who have the authority to manage both the source and destination site.

## Requirements

* [Terminus](https://github.com/pantheon-systems/terminus) `1.8.0` or greater
* [git command line](https://git-scm.com/book/en/v2/Getting-Started-Installing-Git)
* The latest version of the [Terminus site clone plugin](https://github.com/pantheon-systems/terminus-site-clone-plugin/issues/11#issuecomment-402937969)

## Instructions

These instructions assume a unix environment and have been tested on macOS High Sierra.

1) Copy the desired script, e.g. `terminus-new-site-clone-all-envs.sh`, to your desktop
1) Update the `source_site`, `destination_site` and `destination_label` variables
1) Authenticate with Terminus
    - Ensure you are using a machine token that has sufficient permissions to access, edit and deploy to the source site as well as create a new site
1) Double check the script logic, incuding your edits
1) Run the script
1) Review the results
1) Delete the script from your desktop once done
