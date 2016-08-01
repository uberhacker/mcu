# Mass Contrib Update
Terminus plugin to perform Drupal contrib module or theme updates on Pantheon sites

## Installation:
Refer to the [Terminus Wiki](https://github.com/pantheon-systems/terminus/wiki/Plugins).

## Usage:
```
$ terminus sites mass-contrib-update
```

## Alias:
```
$ terminus sites mcu
```

## Help:
```
$ terminus help sites mcu
```

## Options:
```
[--env=<env>]
: Filter sites by environment.  Default is 'mcu'.

[--report]
: Display a report of contrib update status without actually performing the updates.

[--message]
: Commit changes after updates are applied with a user-defined message.

[--confirm]
: Prompt to confirm before actually performing the updates on each site.

[--skip-backup]
: Skip backup before performing the updates on each site.

[--security-only]
: Apply security updates only to contrib modules or themes.

[--projects]
: A comma separated list of specific contrib modules or themes to update.

[--reset]
: Delete the existing mcu multidev environment and create a new environment.

[--team]
: Filter for sites you are a team member of.

[--owner]
: Filter for sites a specific user owns. Use "me" for your own user.

[--org=<id>]
: Filter sites you can access via the organization. Use 'all' to get all.

[--name=<regex>]
: Filter sites you can access via name.

[--cached]
: Causes the command to return cached sites list instead of retrieving anew.
```

## Examples:
Display contrib updates that would be applied without actually performing the updates for each site:
```
$ terminus sites mass-contrib-update --report
```
Create a new multidev environment named mcu if it doesn't already exist, apply contrib updates and auto-commit with a generic message for each site:
```
$ terminus sites mcu
```
Delete the mcu multidev environment if it already exists, create a new environment, apply contrib updates and auto-commit with a generic message for each site:
```
$ terminus sites mcu --reset
```
Apply contrib security updates only, skip the automatic backup and auto-commit with a generic message on all dev environments for each site:
```
$ terminus sites mcu --env=dev --security-only --skip-backup
```
Apply contrib updates to the ctools and views projects only on the dev environment of the site named my-awesome-site and commit with a user-defined message:
```
$ terminus sites mcu --env=dev --message="Updated ctools and views contrib modules" --projects=ctools,views --name=my-awesome-site
```
Apply contrib updates to all dev environments, commit with a user-defined message and prompt to continue prior to performing the updates for each site:
```
$ terminus sites mcu --env=dev --message="Applied contrib updates" --confirm
```
