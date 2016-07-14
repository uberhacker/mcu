# Terminus plugin for Drupal contrib module updates
Terminus plugin to perform mass Drupal contrib module updates on Pantheon sites

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

## Options:
```
[--env=<env>]
: Filter sites by environment.  Default is 'dev'.

[--report]
: Display the contrib modules that need updates without actually performing the updates.

[--yes]
: Assume a yes response to any prompts while performing the updates.

[--confirm]
: Prompt to confirm before actually performing the updates on each site.

[--security-only]
: Apply contrib module security updates only.

[--skip-backup]
: Skip automatic backup before performing the updates on each site.

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
Display contrib modules updates that would be applied to all dev environments without actually performing the updates:
```
$ terminus sites mass-contrib-update --report
```
Apply contrib module updates to all dev environments and assume a yes response to any prompts while performing the updates:
```
$ terminus sites mcu --yes
```
Apply contrib module security updates only to all dev environments and skip the automatic backup while performing the updates:
```
$ terminus sites mcu --security-only --skip-backup
```
Apply contrib module updates to all live environments and prompt while performing the updates:
```
$ terminus sites mcu --env=live --confirm
```
