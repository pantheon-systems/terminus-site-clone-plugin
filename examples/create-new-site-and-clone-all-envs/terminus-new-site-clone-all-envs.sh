#!/bin/bash

# Fail on any errors
set -e

# Where to clone from
source_site='my-cool-existing-site'

# Where to clone to
destination_site='my-cool-new-cloned-site'
destination_label='My Cool New Cloned Site'

# Get the upstream ID and name
upstream_id="$(terminus site:info $source_site --format=list --field=Upstream | cut -f1 -d':')"
upstream_name="$(terminus upstream:info $upstream_id --format=list --field=Name)"
echo -e "\nUsing the $upstream_name upstream detected from $source_site"

# Check for an org
org_id="$(terminus site:info $source_site --format=list --field=Organization)"
if [ -z "$org_id" ]
then
	echo -e "\nNo org detected on $source_site"
	echo -e "\nCreating the new site $destination_site WITHOUT an org..."
	terminus site:create $destination_site "$destination_label" $upstream_id
else
	echo -e "\nDetected an organization ($org_id) on the $source_site site"

	read -r -p "Would you like to associate the org from $source_site with $destination_site? Y or N " REPLY

	if [[ $REPLY =~ ^[Yy]$ ]]
	then
		echo -e "\nCreating the new site $destination_site WITH the org $org_id..."
		terminus site:create $destination_site "$destination_label" $upstream_id --org="$org_id"
	else
		echo -e "\nCreating the new site $destination_site WITHOUT an org..."
		terminus site:create $destination_site "$destination_label" $upstream_id
	fi
fi

# Start with dev cloning dev
echo -e "\nCloning the dev environment..."
terminus site:clone $source_site.dev $destination_site.dev --no-source-backup --no-destination-backup --yes

# Create the test environment
echo -e "\nCreating the test environment on $destination_site..."
terminus env:deploy $destination_site.test --note="Initialize the test environment"

# Clone db/files from the source site to the test environment
echo -e "\nCreating a fresh backup of the test environment on $source_site..."
terminus backup:create $source_site.test
terminus site:clone $source_site.test $destination_site.test --no-code --no-source-backup --no-destination-backup --yes

# Create the live environment
echo -e "\nCreating the live environment on $destination_site..."
terminus env:deploy $destination_site.live --note="Initialize the live environment"

# Clone db/files from the source site to the live environment
echo -e "\nCreating a fresh backup of the live environment on $source_site..."
terminus backup:create $source_site.live
terminus site:clone $source_site.live $destination_site.live --no-code --no-source-backup --no-destination-backup --yes

# Stash multidev list
source_multidevs="$(terminus multidev:list $source_site --format=list --field=Name)"

# Loop through the multidev environments on the source site
for multidev in $source_multidevs; do
	echo -e "\nCreating the $multidev multidev environment..."
	terminus multidev:create $destination_site.dev $multidev

	echo -e "\nCreating a fresh backup of the $multidev multidev environment from $source_site..."
	terminus backup:create $source_site.$multidev

	echo -e "\nCloning the $multidev multidev environment from $source_site..."
	terminus site:clone $source_site.$multidev $destination_site.$multidev --no-source-backup --no-destination-backup --yes
done
