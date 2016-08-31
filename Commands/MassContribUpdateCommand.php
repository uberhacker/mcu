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
 * Actions on multiple sites.
 *
 * @command sites
 */
class MassContribUpdateCommand extends TerminusCommand {
  public $sites;

  /**
   * Apply Drupal contrib updates on Pantheon sites.
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
   * Apply Drupal contrib updates on Pantheon sites.
   * Note: because of the size of this call, it is cached
   *   and also is the basis for loading individual sites by name.
   *
   * ## OPTIONS
   *
   * [--env=<env>]
   * : Filter sites by environment.  Default is 'mcu'.
   *
   * [--report]
   * : Display a report of contrib update status without actually performing the updates.
   *
   * [--message]
   * : Commit changes after updates are applied with a user-defined message.
   *
   * [--confirm]
   * : Prompt to confirm before actually performing the updates on each site.
   *
   * [--skip-backup]
   * : Skip backup before performing the updates on each site.
   *
   * [--security-only]
   * : Apply security updates only to contrib modules or themes.
   *
   * [--projects]
   * : A comma separated list of specific contrib modules or themes to update.
   *
   * [--reset]
   * : Delete the existing mcu multidev environment and create a new environment.
   *
   * [--team]
   * : Filter for sites you are a team member of.
   *
   * [--owner]
   * : Filter for sites a specific user owns. Use "me" for your own user.
   *
   * [--org=<id>]
   * : Filter sites you can access via the organization. Use 'all' to get all.
   *
   * [--name=<regex>]
   * : Filter sites you can access via name.
   *
   * [--cached]
   * : Causes the command to return cached sites list instead of retrieving anew.
   *
   * @subcommand mass-contrib-update
   * @alias mcu
   *
   * @param array $args       Array of main arguments
   * @param array $assoc_args Array of associative arguments
   *
   * @return null
   */
  public function massContribUpdate($args, $assoc_args) {
    $options = [
      'org_id'    => $this->input()->optional(
        [
          'choices' => $assoc_args,
          'default' => null,
          'key'     => 'org',
        ]
      ),
      'team_only' => isset($assoc_args['team']),
    ];
    $this->sites->fetch($options);

    if (isset($assoc_args['name'])) {
      $this->sites->filterByName($assoc_args['name']);
    }

    if (isset($assoc_args['owner'])) {
      $owner_uuid = $assoc_args['owner'];
      if ($owner_uuid == 'me') {
        $owner_uuid = $this->user->id;
      }
      $this->sites->filterByOwner($owner_uuid);
    }

    $sites = $this->sites->all();

    if (count($sites) == 0) {
      $this->log()->warning('You have no sites.');
    }

    // Validate the --env argument value, if needed.
    if (isset($assoc_args['report'])) {
      $env = 'dev';
    } else {
      $env = 'mcu';
    }
    if (isset($assoc_args['env'])) {
      $env = $assoc_args['env'];
    }
    $valid_envs = array('dev', 'mcu');
    $valid_env = in_array($env, $valid_envs);
    $deploy_envs = array('test', 'live');
    $deploy_env = in_array($env, $deploy_envs);
    if (!$valid_env && !$deploy_env) {
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
      $messages = array();
      $messages[] = 'Invalid --env argument value.  Allowed values are dev, mcu or a valid';
      $messages[] = ' multidev environment.  If you are attempting updates on test or live,';
      $messages[] = ' the best practice is to deploy instead.  See "terminus help site deploy".';
      $invalid_message = implode("\n", $messages);
      $this->failure($invalid_message);
    }

    // Loop through each site and update.
    $data = array();
    foreach ($sites as $site) {
      $new = true;
      $name = $site->get('name');
      $environments = $site->environments->all();
      foreach ($environments as $environment) {
        $e = $environment->get('id');
        if ($e == $env) {
          $new = false;
          break;
        }
      }
      $args = array(
        'name'      => $name,
        'env'       => $env,
        'new'       => $new,
        'framework' => $site->attributes->framework,
      );
      $status = $this->update($args, $assoc_args);
      if ($status) {
        $data[$name] = array(
          'site'   => $name,
          'status' => $status,
        );
      }
    }

    // Display a summary of contrib update status for each site.
    if (isset($assoc_args['report'])) {
      if (!empty($data)) {
        sort($data);
        $this->output()->outputRecordList($data);
      } else {
        $this->log()->info('No sites in need of updating.');
      }
    }
  }

  /**
   * Create a MultiDev environment.
   *
   * @param array $args Array of main arguments.
   *   site : Site to use
   *   to-env : Name of environment to create
   *   from-env : Environment clone content from, default = dev
   *
   * @return boolean Return value.
   *   true : permission is granted and the environment is created successfully
   *   false : permission is not granted or the environment is not created
   *           successfully
   */
  private function createEnv($args) {
    $site = $this->sites->get($this->input()->siteName(['args' => $args]));

    if ((boolean)$site->getFeature('multidev')) {
      $name = $args['site'];
      $to_env = $args['to-env'];
      $from_env = $site->environments->get(
        $this->input()->env(
          array(
            'args' => $args,
            'key' => 'from-env',
            'label' => 'Environment to clone content from',
            'site' => $site,
          )
        )
      );
      $this->log()->notice(
        'Cloning the dev environment to create {multidev}.',
        ['multidev' => $to_env,]
      );
      if ($workflow = $site->environments->create($to_env, $from_env)) {
        $workflow->wait();
        $this->workflowOutput($workflow);
        // Set the drush version to 8 for the new environment.
        $mcu_args = array(
          'site' => $name,
          'env' => $to_env,
          'version' => 8,
        );
        if ($this->setDrushVersion($mcu_args)) {
          return true;
        } else {
          $this->log()->error(
            'Unable to set the Drush version for the {multidev} multidev environment.',
            ['multidev' => $to_env,]
          );
          return false;
        }
      } else {
        $this->log()->error(
          'Unable to create the {multidev} multidev environment.',
          ['multidev' => $to_env,]
        );
        return false;
      }
    } else {
      $this->log()->error(
        'This site does not have the authority to conduct this operation.'
      );
      return false;
    }
  }

  /**
   * Delete a MultiDev environment.
   *
   * @param array $args Array of main arguments
   *   site : Site to use
   *   env : Name of environment to delete
   *
   * @return boolean Return value
   *   true : environment is deleted successfully
   *   false : environment is not deleted successfully
   */
  public function deleteEnv($args) {
    $site = $this->sites->get($this->input()->siteName(['args' => $args]));
    $multidev_envs = array_diff(
      $site->environments->ids(),
      ['dev', 'test', 'live',]
    );
    if (empty($multidev_envs)) {
      $this->error(
        '{site} does not have any multidev environments to delete.',
        ['site' => $site->get('name'),]
      );
      return false;
    }
    $environment = $site->environments->get(
      $this->input()->env(
        [
          'args'    => $args,
          'label'   => 'Environment to delete',
          'choices' => $multidev_envs,
        ]
      )
    );

    if ($workflow = $environment->delete(
      ['delete_branch' => true]
    )) {
      $workflow->wait();
      $this->workflowOutput($workflow);
      return true;
    } else {
      return false;
    }
  }

  /**
   * Set the version of Drush to be used on a specific environment or site.
   *
   * @param array $args Array of main arguments.
   *   site : Site to use
   *   env : Name of environment to set the Drush version
   *   version : Drush version to use. Options are 5, 7, and 8.
   *
   * @return boolean Return value.
   *   true : Drush version is set successfully for each environment
   *   false : Drush version is not set successfully for each environment
   */
  public function setDrushVersion($args) {
    $sites = new Sites();
    $site = $sites->get($this->input()->siteName(['args' => $args,]));
    if (isset($args['env'])) {
      $environments = [$site->environments->get($args['env']),];
    } else {
      $environments = $site->environments->all();
    }
    $version = 8;
    if (isset($args['version'])) {
      $version = $args['version'];
    }
    $return = true;
    foreach ($environments as $environment) {
      if ($workflow = $environment->setDrushVersion((integer)$version)) {
        $workflow->wait();
        $this->workflowOutput($workflow);
      } else {
        $return = false;
        break;
      }
    }
    return $return;
  }

  /**
   * Perform the updates on a specific site and environment.
   *
   * @param array $args       The site environment arguments.
   * @param array $assoc_args The site associative arguments.
   *
   * @return null
   */
  private function update($args, $assoc_args) {
    // Set main arguments.
    $name = $args['name'];
    $environ = $args['env'];
    $new = $args['new'];
    $framework = $args['framework'];

    // Set associative arguments.
    $reset = isset($assoc_args['reset']);
    $report = isset($assoc_args['report']);
    $confirm = isset($assoc_args['confirm']);
    $skip = isset($assoc_args['skip-backup']);
    $message = 'Updates applied by Mass Contrib Update terminus plugin.';
    if (!empty($assoc_args['message'])) {
      $message = $assoc_args['message'];
    }
    $security = '';
    if (isset($assoc_args['security-only'])) {
      $security = '--security-only';
    }
    $projects = '';
    if (!empty($assoc_args['projects'])) {
      $projects = $assoc_args['projects'];
    }

    // Check for valid frameworks.
    $valid_frameworks = array(
      'drupal',
      'drupal8',
    );
    if (!in_array($framework, $valid_frameworks)) {
      $message = '{framework} is not a valid Drupal framework.  Contrib updates aborted for the';
      $message .= ' {environ} environment of {name} site.';
      $this->log()->notice(
        $message,
        ['framework' => $framework, 'environ' => $environ, 'name' => $name,]
      );
      return false;
    }

    // Beginning message.
    $this->log()->notice(
      'Started checking contrib updates for the {environ} environment of {name} site.',
      ['environ' => $environ, 'name' => $name,]
    );

    // Check if contrib updates are available via drush.
    if ($new || $reset) {
      $check_env = 'dev';
    } else {
      $check_env = $environ;
    }
    $drush_options = trim("pm-update -n --no-core $security $projects");
    exec(
      "terminus --site=$name --env=$check_env drush '$drush_options'",
      $report_array,
      $report_error
    );

    // Look for code updates in the output of the results.
    if (!empty($report_array)) {
      $report_message = implode("\n", $report_array);
      $this->log()->notice($report_message);
      if (!strpos($report_message, 'updates will be made to the following projects')) {
        return 'Up to date';
      } elseif ($report) {
        // Return if just checking updates.
        return 'Needs update';
      }
    }

    // Abort on error.
    if ($report_error) {
      $this->log()->error(
        'Unable to check contrib updates for the {environ} environment of {name} site.',
        ['environ' => $check_env, 'name' => $name,]
      );
      return false;
    }

    // Completion message.
    $this->log()->notice(
      'Finished checking contrib updates for the {environ} environment of {name} site.',
      ['environ' => $environ, 'name' => $name,]
    );

    // Prompt to confirm updates.
    if ($confirm) {
      $confirm_message = 'Apply contrib updates to the %s environment of %s site? ';
      $confirmed = $this->input()->confirm(
        array(
          'message' => $confirm_message,
          'context' => array(
            $environ,
            $name,
          ),
          'exit' => false,
        )
      );
      // User says No.
      if (!$confirmed) {
        return false;
      }
    }

    // Beginning message.
    $this->log()->notice(
      'Started applying contrib updates for the {environ} environment of {name} site.',
      ['environ' => $environ, 'name' => $name,]
    );

    // Delete existing mcu environment.
    if (!$new && $reset) {
      $mcu_args = array(
        'site' => $name,
        'env' => $environ,
      );
      $new = $this->deleteEnv($mcu_args);
    }

    // If allowed, create a new multidev environment for testing updates.
    if ($new) {
      $mcu_args = array(
        'site'     => $name,
        'from-env' => 'dev',
        'to-env'   => $environ,
      );
      if (!$this->createEnv($mcu_args)) {
        $message = 'Would you like to apply contrib updates to the dev environment';
        $message .= ' of %s site instead? ';
        $confirmed = $this->input()->confirm(
          array(
            'message' => $message,
            'context' => array(
              $name,
            ),
            'exit' => false,
          )
        );
        if ($confirmed) {
          $environ = 'dev';
        } else {
          $this->log()->notice(
            'Contrib updates aborted for the dev environment of {name} site.',
            ['name' => $name,]
          );
          return false;
        }
      }
    }

    // Load the environment.
    $assoc_args = array(
      'site' => $name,
      'env'  => $environ,
    );
    $this->sites = new Sites();
    $site = $this->sites->get(
      $this->input()->siteName(['args' => $assoc_args])
    );
    $env  = $site->environments->get(
      $this->input()->env(array('args' => $assoc_args, 'site' => $site))
    );
    $env->wake();
    $mode = $env->info('connection_mode');

    // Check for pending changes in sftp mode.
    if ($mode == 'sftp') {
      $diff = (array)$env->diffstat();
      if (!empty($diff)) {
        $message = 'Unable to update the {environ} environment of {name} site due';
        $message .= ' to pending changes.  Commit changes and try again.';
        $this->log()->error(
          $message,
          ['environ' => $environ, 'name' => $name,]
        );
        return false;
      }
    }

    // Backup the site in case something goes awry.
    if (!$skip && !$new) {
      $message = 'Started automatic backup for the {environ} environment of {name} site.';
      $this->log()->notice(
        $message,
        ['environ' => $environ, 'name' => $name,]
      );
      $args = array(
        'element' => 'all',
      );
      if ($workflow = $env->backups->create($args)) {
        if (is_string($workflow)) {
          $this->log()->info($workflow);
        } else {
          $workflow->wait();
          $this->workflowOutput($workflow);
        }
      } else {
        $message = 'Backup failed.  Contrib updates aborted for the {environ}';
        $message .= ' environment of {name} site.';
        $this->log()->error(
          $message,
          ['environ' => $environ, 'name' => $name,]
        );
        return false;
      }
    }

    // Set connection mode to sftp.
    if ($mode == 'git') {
      $workflow = $env->changeConnectionMode('sftp');
      if (is_string($workflow)) {
        $this->log()->info($workflow);
      } else {
        $workflow->wait();
        $this->workflowOutput($workflow);
      }
    }

    // Perform contrib updates via drush.
    $drush_options = trim("pm-update -y --no-core $security $projects");
    exec(
      "terminus --site=$name --env=$environ drush '$drush_options'",
      $update_array,
      $update_error
    );

    // Display output of update results.
    if (!empty($update_array)) {
      $update_message = implode("\n", $update_array);
      $this->log()->notice($update_message);
    }

    // Abort on error.
    if ($update_error) {
      $this->log()->error(
        'Unable to perform contrib updates for the {environ} environment of {name} site.',
        ['environ' => $environ, 'name' => $name,]
      );
      return false;
    }

    // Reload the environment.
    $env  = $site->environments->get(
      $this->input()->env(array('args' => $assoc_args, 'site' => $site))
    );

    // Commit updates.
    if ($workflow = $env->commitChanges($message)) {
      if (is_string($workflow)) {
        $this->log()->info($workflow);
      } else {
        $workflow->wait();
        $this->workflowOutput($workflow);
      }
    } else {
      $this->log()->error(
        'Unable to perform automatic update commit for the {environ} environment of {name} site.',
        ['environ' => $environ, 'name' => $name,]
      );
      return false;
    }

    // Set connection mode back to git.
    if ($mode == 'git') {
      $workflow = $env->changeConnectionMode('git');
      if (is_string($workflow)) {
        $this->log()->info($workflow);
      } else {
        $workflow->wait();
        $this->workflowOutput($workflow);
      }
    }

    // Completion message.
    $this->log()->notice(
      'Finished applying contrib updates for the {environ} environment of {name} site.',
      ['environ' => $environ, 'name' => $name,]
    );
  }

}
