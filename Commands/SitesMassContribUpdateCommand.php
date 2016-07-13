<?php

namespace Terminus\Commands;

use Terminus\Commands\TerminusCommand;
use Terminus\Exceptions\TerminusException;
use Terminus\Models\Collections\Sites;
use Terminus\Models\Organization;
use Terminus\Models\Site;
use Terminus\Models\Upstreams;
use Terminus\Models\User;
use Terminus\Models\Workflow;
use Terminus\Session;
use Terminus\Utils;

/**
 * Actions on multiple sites
 *
 * @command sites
 */
class MassContribUpdateCommand extends TerminusCommand {
  public $sites;

  /**
   * Perform mass Drupal contrib module updates on sites.
   *
   * @param array $options Options to construct the command object
   * @return MassContribUpdateCommand
   */
  public function __construct(array $options = []) {
    $options['require_login'] = true;
    parent::__construct($options);
    $this->sites = new Sites();
  }

  /**
   * Perform mass Drupal contrib module updates on sites.
   *
   * ## OPTIONS
   *
   * [--report]
   * : Display the contrib modules that need updates without actually performing the updates.
   *
   * [--update-only=<comma separated module list>]
   * : Specify a list of contrib modules to update.
   *
   * [--no-updatedb]
   * : Use flag to skip running update.php after the update has applied
   *
   * [--xoption=<theirs|ours>]
   * : Corresponds to git's -X option, set to 'theirs' by default
   *   -- https://www.kernel.org/pub/software/scm/git/docs/git-merge.html
   *
   * [--tag=<tag>]
   * : Tag to filter by
   *
   * [--org=<id>]
   * : Only necessary if using --tag. Organization which has tagged the site
   *
   * [--cached]
   * : Set to prevent rebuilding of sites cache
   *
   * @subcommand mass-contrib-update
   */
  public function massContribUpdate($args, $assoc_args) {
    // Ensure the sitesCache is up to date
    if (!isset($assoc_args['cached'])) {
      $this->sites->rebuildCache();
    }

    $data     = array();
    $report   = $this->input()->optional(
      array(
        'key'     => 'report',
        'choices' => $assoc_args,
        'default' => false,
      )
    );
    $confirm   = $this->input()->optional(
      array(
        'key'     => 'confirm',
        'choices' => $assoc_args,
        'default' => false,
      )
    );
    $tag       = $this->input()->optional(
      array(
        'key'     => 'tag',
        'choices' => $assoc_args,
        'default' => false,
      )
    );

    $org = '';
    if ($tag) {
      $org = $this->input()->orgId(array('args' => $assoc_args));
    }
    $sites = $this->sites->filterAllByTag($tag, $org);

    // Start status messages.
    if ($upstream) {
      $this->log()->info(
        'Looking for sites using {upstream}.',
        compact('upstream')
      );
    }

    foreach ($sites as $site) {
      $context = array('site' => $site->get('name'));
      $site->fetch();
      $updates = $site->getUpstreamUpdates();
      if (!isset($updates->behind)) {
        // No updates, go back to start.
        continue;
      }
      // Check for upstream argument and site upstream URL match.
      $siteUpstream = $site->info('upstream');
      if ($upstream && isset($siteUpstream->url)) {
        if ($siteUpstream->url <> $upstream) {
          // Uptream doesn't match, go back to start.
          continue;
        }
      }

      if ($updates->behind > 0) {
        $data[$site->get('name')] = array(
          'site'   => $site->get('name'),
          'status' => 'Needs update'
        );
        $env = $site->environments->get('dev');
        if ($env->info('connection_mode') == 'sftp') {
          $message  = '{site} has available updates, but is in SFTP mode.';
          $message .= ' Switch to Git mode to apply updates.';
          $this->log()->warning($message, $context);
          $data[$site->get('name')] = array(
            'site'=> $site->get('name'),
            'status' => 'Needs update - switch to Git mode'
          );
          continue;
        }
        $updatedb = !$this->input()->optional(
          array(
            'key'     => 'updatedb',
            'choices' => $assoc_args,
            'default' => false,
          )
        );
        $xoption  = !$this->input()->optional(
          array(
            'key'     => 'xoption',
            'choices' => $assoc_args,
            'default' => 'theirs',
          )
        );
        if (!$report) {
          $message = 'Apply upstream updates to %s ';
          $message .= '( run update.php:%s, xoption:%s ) ';
          $confirmed = $this->input()->confirm(
            array(
              'message' => $message,
              'context' => array(
                $site->get('name'),
                var_export($updatedb, 1),
                var_export($xoption, 1)
              ),
              'exit' => false,
            )
          );
          if (!$confirmed) {
            continue; // User says No, go back to start.
          }
          // Back up the site so it may be restored should something go awry
          $this->log()->info('Backing up {site}.', $context);
          $backup = $env->backups->create(['element' => 'all',]);
          // Only continue if the backup was successful.
          if ($backup) {
            $this->log()->info('Backup of {site} created.', $context);
            $this->log()->info('Updating {site}.', $context);
            $response = $site->applyUpstreamUpdates(
              $env->get('id'),
              $updatedb,
              $xoption
            );
            $data[$site->get('name')]['status'] = 'Updated';
            $this->log()->info('{site} is updated.', $context);
          } else {
            $data[$site->get('name')]['status'] = 'Backup failed';
            $this->failure(
              'There was a problem backing up {site}. Update aborted.',
              $context
            );
          }
        }
      } else {
        if (isset($assoc_args['report'])) {
          $data[$site->get('name')] = array(
            'site'   => $site->get('name'),
            'status' => 'Up to date'
          );
        }
      }
    }

    if (!empty($data)) {
      sort($data);
      $this->output()->outputRecordList($data);
    } else {
      $this->log()->info('No sites in need of updating.');
    }
  }

  /**
   * Perform mass Drupal contrib module updates on sites.
   * Note: because of the size of this call, it is cached
   *   and also is the basis for loading individual sites by name
   *
   * ## OPTIONS
   *
   * [--env=<env>]
   * : Filter sites by environment.  Default is 'dev'.
   *
   * [--report]
   * : Display the contrib modules that need updates without actually performing the updates.
   *
   * [--team]
   * : Filter for sites you are a team member of
   *
   * [--owner]
   * : Filter for sites a specific user owns. Use "me" for your own user.
   *
   * [--org=<id>]
   * : Filter sites you can access via the organization. Use 'all' to get all.
   *
   * [--name=<regex>]
   * : Filter sites you can access via name
   *
   * [--cached]
   * : Causes the command to return cached sites list instead of retrieving anew
   *
   * @subcommand mass-contrib-update
   * @alias mcu
   *
   * @param array $args Array of main arguments
   * @param array $assoc_args Array of associate arguments
   *
   */
  public function index($args, $assoc_args) {
    // Check for prerequisite commands.
    exec('which terminus', $terminus_array, $terminus_error);
    if ($terminus_error || empty($terminus_array)) {
      $message = 'terminus command not found.';
      $this->failure($message);
    }

    exec('which drush', $drush_array, $drush_error);
    if ($drush_error || empty($drush_array)) {
      $message = 'drush command not found.';
      $this->failure($message);
    }

    // Always fetch a fresh list of sites.
    if (!isset($assoc_args['cached'])) {
      $this->sites->rebuildCache();
    }
    $sites = $this->sites->all();

    $yn = isset($assoc_args['report']) ? 'n' : 'y';

    if (isset($assoc_args['team'])) {
      $sites = $this->filterByTeamMembership($sites);
    }
    if (isset($assoc_args['org'])) {
      $org_id = $this->input()->orgId(
        [
          'allow_none' => true,
          'args'       => $assoc_args,
          'default'    => 'all',
        ]
      );
      $sites = $this->filterByOrganizationalMembership($sites, $org_id);
    }

    if (isset($assoc_args['name'])) {
      $sites = $this->filterByName($sites, $assoc_args['name']);
    }

    if (isset($assoc_args['owner'])) {
      $owner_uuid = $assoc_args['owner'];
      if ($owner_uuid == 'me') {
        $owner_uuid = Session::getData()->user_uuid;
      }
      $sites = $this->filterByOwner($sites, $owner_uuid);
    }

    if (count($sites) == 0) {
      $this->log()->warning('You have no sites.');
    }

    // Validate the --env argument value, if needed.
    $env = isset($assoc_args['env']) ? $assoc_args['env'] : 'dev';
    $valid_env = ($env == 'all');
    if (!$valid_env) {
      foreach ($sites as $site) {
        $environments = $site->environments->all();
        foreach ($environments as $environment) {
          $e = $environment->get('id');
          if ($e == $env) {
            $valid_env = true;
            break;
          }
        }
        if ($valid_env) {
          break;
        }
      }
    }
    if (!$valid_env) {
      $message = 'Invalid --env argument value. Allowed values are dev, test, live or a valid multi-site environment.';
      $this->failure($message);
    }

    // Loop through each site and update.
    foreach ($sites as $site) {
      $name = $site->get('name');
      $args = array(
        'name' => $name,
        'env'  => $env,
        'yn'   => $yn,
      );
      $this->update($args);
    }
  }

  /**
   * Filters an array of sites by whether the user is an organizational member
   *
   * @param Site[] $sites An array of sites to filter by
   * @param string $regex Non-delimited PHP regex to filter site names by
   * @return Site[]
   */
  private function filterByName($sites, $regex = '(.*)') {
    $filtered_sites = array_filter(
      $sites,
      function($site) use ($regex) {
        preg_match("~$regex~", $site->get('name'), $matches);
        $is_match = !empty($matches);
        return $is_match;
      }
    );
    return $filtered_sites;
  }

  /**
   * Filters an array of sites by whether the user is an organizational member
   *
   * @param Site[] $sites      An array of sites to filter by
   * @param string $owner_uuid UUID of the owning user to filter by
   * @return Site[]
   */
  private function filterByOwner($sites, $owner_uuid) {
    $filtered_sites = array_filter(
      $sites,
      function($site) use ($owner_uuid) {
        $is_owner = ($site->get('owner') == $owner_uuid);
        return $is_owner;
      }
    );
    return $filtered_sites;
  }

  /**
   * Filters an array of sites by whether the user is an organizational member
   *
   * @param Site[] $sites  An array of sites to filter by
   * @param string $org_id ID of the organization to filter for
   * @return Site[]
   */
  private function filterByOrganizationalMembership($sites, $org_id = 'all') {
    $filtered_sites = array_filter(
      $sites,
      function($site) use ($org_id) {
        $memberships    = $site->get('memberships');
        foreach ($memberships as $membership) {
          if ((($org_id == 'all') && ($membership['type'] == 'organization'))
            || ($membership['id'] === $org_id)
          ) {
            return true;
          }
        }
        return false;
      }
    );
    return $filtered_sites;
  }

  /**
   * Filters an array of sites by whether the user is a team member
   *
   * @param Site[] $sites An array of sites to filter by
   * @return Site[]
   */
  private function filterByTeamMembership($sites) {
    $filtered_sites = array_filter(
      $sites,
      function($site) {
        $memberships    = $site->get('memberships');
        foreach ($memberships as $membership) {
          if ($membership['name'] == 'Team') {
            return true;
          }
        }
        return false;
      }
    );
    return $filtered_sites;
  }

  /**
   * Perform the updates on a specific site and environment.
   *
   * @param array $args
   *   The site environment arguments.
   */
  private function update($args) {
    $name = $args['name'];
    $environ = $args['env'];
    $yn = $args['yn'];
    $assoc_args = array(
      'site' => $name,
      'env'  => $environ,
    );
    $site = $this->sites->get(
      $this->input()->siteName(['args' => $assoc_args])
    );
    $env  = $site->environments->get(
      $this->input()->env(array('args' => $assoc_args, 'site' => $site))
    );
    $backup = true;
    $mode = $env->info('connection_mode');
    if ($mode == 'sftp') {
      $diff = (array)$env->diffstat();
      if (!empty($diff)) {
        $backup = false;
      }
    }
    if ($backup) {
      // Backup the site in case something goes awry.
      $this->log()->notice("Start backup $environ environment of $name site.");
      $args = array(
        'element' => 'all',
      );
      $workflow = $env->backups->create($args);
      $this->log()->notice("End backup $environ environment of $name site.");
      // Perform drush updates.
      exec("terminus drush 'pm-update -no-core -$yn'", $update_array, $update_error);
      if (!empty($update_error)) {
        $message = implode("\n", $update_error);
        $this->log()->error($message);
      }
    } else {
      $message = "Unable to update $environ environment of $name site due to pending changes in sftp mode.";
      $this->log()->error($message);
    }
  }
}
