<?php
/**
 * Terminus Plugin that that adds command(s) to facilitate cloning sites on [Pantheon](https://www.pantheon.io)
 *
 * See README.md for usage information.
 */

namespace Pantheon\TerminusSiteClone\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Commands\WorkflowProcessingTrait;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Commands\Site\SiteCommand;
use Pantheon\Terminus\Request\RequestAwareInterface;
use Pantheon\Terminus\Request\RequestAwareTrait;
use Pantheon\Terminus\Commands\Backup\SingleBackupCommand;
use Pantheon\Terminus\Site\SiteAwareTrait;
use Pantheon\Terminus\Commands\Remote\WPCommand;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Site Clone Command
 * @package Pantheon\TerminusSiteClone\Commands
 */
class SiteCloneCommand extends SingleBackupCommand implements RequestAwareInterface
{
    use RequestAwareTrait;
    use WorkflowProcessingTrait;
    use SiteAwareTrait;

    private $options = array();
    private $user_source;
    private $user_destination;
    private $source;
    private $destination;
    private $temp_dir;
    private $git_dir;
    
    /**
     * Copy the code, database, and media files from the specified Pantheon
     * source site to the specified Pantheon destination site.
     *
     * @command site:clone
     * @aliases site:copy
     * @param string $user_source The site UUID or machine name and environment of the SOURCE (<site>.<env>)
     * @param string $user_destination The site UUID or machine name and environment of the DESTINATION (<site>.<env>)
     * @param array $options
     * @option database Clone the database.
     * @option files Clone the (media) files.
     * @option code Clone the code.
     * @option source-backup Backup the source site environment before cloning.
     * @option destination-backup Backup the destination site environment before cloning.
     * @option cleanup-temp-dir Delete the temporary directory used for code clone after cloning is complete. 
     */
    public function clonePantheonSite(
            $user_source,
            $user_destination,
            $options = [
                'database' => true,
                'files' => true,
                'code' => true,
                'source-backup' => true,
                'destination-backup' => true,
                'cleanup-temp-dir' => true,
            ]
        )
    {

        // Make sure options are booleans and not strings
        foreach( $options as $key => $value ){
            $options[$key] = boolval( $value );
            $this->options[$key] = boolval( $value );
        }

        $this->user_source = $user_source;
        $this->source = $this->fetchSiteDetails($user_source);
        $this->user_destination = $user_destination;
        $this->destination = $this->fetchSiteDetails($user_destination);


        $this->temp_dir = sys_get_temp_dir() . '/terminus-site-clone-temp/';
        $this->git_dir = $this->temp_dir . $this->source['name'] . '/';

        if( $this->source['php_version'] !== $this->destination['php_version'] ){
            $proceed = $this->confirm(
                'Warning: the source site has a PHP version of {src_php} and the destination site has a PHP version of {dest_php}. Would you like to proceed? Doing so will overwrite the PHP version of the destination to {src_php}.',
                [
                    'src_php' => substr_replace($this->source['php_version'], '.', 1, 0),
                    'dest_php' => substr_replace($this->destination['php_version'], '.', 1, 0),
                ]
            );
            
            if (!$proceed) {
                return;
            }
        }

        if( $this->source['framework'] !== $this->destination['framework'] ){
            throw new TerminusException('Cannot clone sites with different frameworks.');
        }
        
        if( $this->source['frozen'] || $this->destination['frozen'] ){
            // @todo: Ask the user if they want to unfreeze the site
            throw new TerminusException('Cannot clone sites that are frozen.');
        }

        if( in_array($this->destination['env'], ['test', 'live']) && $this->options['code'] ){
            throw new TerminusException('Cannot clone code to the test or live environments. To clone database and files use --no-code.');
        }
        
        if( in_array($this->source['env'], ['test', 'live']) && $this->options['code'] ){
            throw new TerminusException('Cannot clone code from the test or live environments. To clone database and files use --no-code.');
        }

        $confirmation_message = 'Are you sure you want to clone from the {src}.{src_env} environment (source) to the {dest}.{dest_env} (destination)? This will completely destroy the destination.';

        if( $this->options['backup'] ){
            $confirmation_message .= ' A backup will be made first, just in case.';
        }

        $confirm = $this->confirm(
            $confirmation_message . "\n",
            [ 
                'src' => $this->source['name'],
                'src_env' => $this->source['env'],
                'dest' => $this->destination['name'],
                'dest_env' => $this->destination['env'],
            ]
        );

        if( ! $confirm ){
            return;
        }

        if( $this->options['destination-backup'] ){
            $this->createBackup($this->destination);
        }

        $backup_elements = $this->getBackupElements();

        $backup_all = ( 3 === count($backup_elements) );

        if( $this->options['source-backup'] && $backup_all ){
            $this->createBackup($this->source);
        }

        $this->log()->notice(
            'Cloning the following elements: {elements}',
            [
                'elements' => implode( ', ', $backup_elements),
            ]
        );
        
        $source_backups = [];
        
        foreach( $backup_elements as $element ){

            if( 'code' !== $element ){
            
                // Back up elements individually if not cloning all elements and source backups are on
                if( $this->options['source-backup'] && ! $backup_all ){
                    $this->createBackup($this->source, $element);
                }

                $source_backups[$element] = $this->getLatestBackup($this->source, $element);

                if( !isset( $source_backups[$element]['url'] ) || empty( $source_backups[$element]['url'] ) ){
                    $this->log()->notice(
                        'Failed to get a URL from the latest {element} backup on the {site}.{env} environment. It will not be imported.',
                        [
                            'site' => $this->source['name'],
                            'env' => $this->source['env'],
                            'element' => $element,
                        ]
                    );
                    continue;
                }

                $this->importBackup($element, $source_backups[$element]['url']);

            } else {
                $this->importBackup($element);
            }

        }

        $this->log()->notice(
            'Successfully cloned {elements} from the {src}.{src_env} environment (source) to the {dest}.{dest_env} (destination).',
            [
                'src' => $this->source['name'],
                'src_env' => $this->source['env'],
                'dest' => $this->destination['name'],
                'dest_env' => $this->destination['env'],
                'elements' => implode( ', ', $backup_elements),
            ]
        );

    }

    /**
     * Fetch details of a site from '<site>.<env>' format
     *
     * @param string $site_env
     * @return array
     */
    private function fetchSiteDetails($site_env){
        list($site,$env) = $this->getSiteEnv($site_env);
        $return = $site->serialize();
        $return['site_raw'] = $site;
        // Turn the string value of 'true' or 'false' into a boolean
        $return['frozen'] = filter_var($return['frozen'], FILTER_VALIDATE_BOOLEAN);
        $return['env'] = $env->id;
        $return['env_raw'] = $env;
        $return['php_version'] = $env->get('php_version');
        $return['url'] = 'https://' . $return['env'] . '-' . $return['name'] . '.pantheonsite.io/';
        $return['pantheon_domain'] = $return['env'] . '-' . $return['name'] . '.pantheonsite.io';

        return $return;
    }

    /**
     * Get backup elements based on input options
     *
     * @return array
     */
    private function getBackupElements()
    {
        $elements = [];
            
        if ( $this->options['code'] ) {
            $elements[] = 'code';
        }

        if ( $this->options['database'] ) {
            $elements[] = 'database';
        }
        
        if ( $this->options['files'] ) {
            $elements[] = 'files';
        }

        if( empty($elements) ){
            throw new TerminusNotFoundException('You must clone at least one element (code, database or file) and cannot skip them all.');
        }

        return $elements;
    }

    /**
     * Create Backup
     *
     * @param array $site the site details from fetchSiteDetails to backup
     * @param string $element the element to backup
     * @return object an instance of the backup element
     */
    private function createBackup($site, $element = 'all' )
    {
        $message = 'Creating a {element} backup on the {site}.{env} environment...';
        
        if( 'all' === $element ){
            $message = 'Creating a backup of the code, database and media files on the {site}.{env} environment...';
        }

        $this->log()->notice(
            $message,
            [
                'site' => $site['name'],
                'env' => $site['env'],
                'element' => $element,
            ]
        );

        $backup_options = ['element' => ( $element !== 'all' ) ? $element : null, 'keep-for' => 365,];
        
        $backup = $this->processWorkflow(
            $site['env_raw']->getBackups()->create($backup_options)
        );

        $message = "Finished backing up the {element} on the {site}.{env} environment.\n";
        
        if( 'all' === $element ){
            $message = "Finished backing up the code, database and media files on the {site}.{env} environment.\n";
        }
        
        $this->log()->notice(
            $message,
            [
                'site' => $site['name'],
                'env' => $site['env'],
                'element' => $element,
            ]
        );

        return $backup;
    }

    /**
     * Get latest backup
     *
     * @param array the site details from fetchSiteDetails to get a backup of
     * @param string $element the backup element to get
     * @return array information for the the latest backup
     */
    private function getLatestBackup($site, $element = 'all')
    {
        // Refresh the site info.
        // Without this call it was possible to get an empty list.
        // https://github.com/pantheon-systems/terminus-site-clone-plugin/issues/2
        $site = $this->fetchSiteDetails($site['name'] . '.' . $site['env']);

        $backups = $site['env_raw']->getBackups()->getFinishedBackups($element);

        if ( empty($backups) ) {
            
            if( ! $this->options['backup'] ){
                $backup_error_message = 'No {element} backups in the source {site}.{env} environment found and the backup argument is set to false. Please either enable the backup argument or manually make a backup of the source environment before re-running the site clone.';
            } else {
                $backup_error_message = 'No {element} backups in the source {site}.{env} environment found.';
            }

            throw new TerminusException(
                $backup_error_message,
                [
                    'site' => $site['name'],
                    'env' => $site['env'],
                    'element' => $element,
                ]
            );
            
        }

        $latest_backup = array_shift($backups);

        if( null === $latest_backup ){
            throw new TerminusException(
                'There was an error fetching a {element} backup from the source {site}.{env} environment.',
                [
                    'site' => $site['name'],
                    'env' => $site['env'],
                    'element' => $element,
                ]
            );
        }

        $return = $latest_backup->serialize();

        $return['url'] = $latest_backup->getArchiveURL();

        return $return;
    }

    /**
     * Import backup
     *
     * @param string $element
     * @param string $url
     * @return void
     */
    private function importBackup($element, $url = null)
    {
        
        switch( $element ){
            case 'db':
            case 'database':
                $this->log()->notice(
                    'Importing the database on {site}.{env}...',
                    [
                        'site' => $this->destination['name'],
                        'env' => $this->destination['env'],
                    ]
                );

                $this->processWorkflow(
                    $this->destination['env_raw']->importDatabase($url)
                );

                $this->log()->notice(
                    "Imported the database to {site}.{env}.\n",
                    [
                        'site' => $this->destination['name'],
                        'env' => $this->destination['env'],
                    ]
                );

                if( 'wordpress' === $this->destination['framework'] ){
                    $this->log()->notice(
                        "WordPress stores URLs in the database. You may want to use wp-cli search-replace to update URLs in the database.\n"
                    );
                    // $this->wpcliSearchReplace();
                }
                break;

            case 'files':
                $this->log()->notice(
                    'Importing media files on {site}.{env}...',
                    [
                        'site' => $this->destination['name'],
                        'env' => $this->destination['env'],
                    ]
                );

                $this->processWorkflow(
                    $this->destination['env_raw']->importFiles($url)
                );

                $this->log()->notice(
                    "Imported the media files to {site}.{env}.\n",
                    [
                        'site' => $this->destination['name'],
                        'env' => $this->destination['env'],
                    ]
                );
                break;

            case 'code':
                $this->log()->notice(
                    'Importing code on {site}.{env} with git...',
                    [
                        'site' => $this->destination['name'],
                        'env' => $this->destination['env'],
                    ]
                );

                $source_connection_info = $this->source['env_raw']->connectionInfo();
                $source_git_url = $source_connection_info['git_url'];
                $source_git_branch = ( in_array($this->source['env'], ['dev', 'test', 'live']) ) ? 'master' : $this->source['env'];
                $destination_connection_info = $this->destination['env_raw']->connectionInfo();
                $destination_git_url = $destination_connection_info['git_url'];
                $destination_git_branch = ( in_array($this->destination['env'], ['dev', 'test', 'live']) ) ? 'master' : $this->destination['env'];
                $destination_connection_mode = $this->destination['env_raw']->get('connection_mode');

                if( 'git' !== $destination_connection_mode ){
                    $this->destination['env_raw']->changeConnectionMode('git');
                }
                
                clearstatcache();
                $fs = new Filesystem();

                if( ! file_exists( $this->temp_dir ) ){
                    $this->log()->notice(
                        'Creating the temporary {temp_dir} directory...',
                        [
                            'temp_dir' => $this->temp_dir,
                        ]
                    );
                    mkdir($this->temp_dir, 0700, true);
                }

                if( file_exists( $this->git_dir ) ){
                    $fs->remove($this->git_dir);
                }

                $this->log()->notice(
                    'Cloning code for {site}.{env} to {git_dir}...',
                    [
                        'site' => $this->destination['name'],
                        'env' => $this->destination['env'],
                        'git_dir' => $this->git_dir,
                    ]
                );

                $this->passthru("git clone --no-tags --single-branch --branch $source_git_branch $source_git_url " . $this->git_dir);
                $this->passthru("git -C {$this->git_dir} fetch origin $source_git_branch");
                $this->passthru("git -C {$this->git_dir} pull origin $source_git_branch");
                
                $this->log()->notice(
                    'Force pushing to the {site}.{env} on the {git_branch} branch.',
                    [
                        'site' => $this->destination['name'],
                        'env' => $this->destination['env'],
                        'git_dir' => $this->git_dir,
                        'git_branch' => $destination_git_branch,
                    ]
                );

                $this->passthru("git -C {$this->git_dir} remote set-url origin $destination_git_url");
                
                $this->passthru("git -C {$this->git_dir} push origin $source_git_branch:$destination_git_branch --force");

                $this->log()->notice(
                    "Sucessfully imported code to {site}.{env}.\n",
                    [
                        'site' => $this->destination['name'],
                        'env' => $this->destination['env'],
                    ]
                );

                if( $this->options['cleanup-temp-dir'] ){
                
                    $this->log()->notice(
                        'Deleting the temporary {temp_dir} directory...',
                        [
                            'temp_dir' => $this->temp_dir,
                        ]
                    );
                    $fs->remove($this->temp_dir);

                }
                break;
        }
    }

    /**
     * Replace the sourceURL with the destination URL on the destination environment
     *
     * @return void
     */
    private function wpcliSearchReplace()
    {
        $this->log()->notice(
            "Using wp-cli search-replace to replace {src_url} with {dest_url} on {site}.{env} as WordPress stores URLs in the database. You may need to run the search-replace manually if you are using custom domains.\n",
            [
                'site' => $this->destination['name'],
                'env' => $this->destination['env'],
                'src_url' => $this->source['pantheon_domain'],
                'dest_url' => $this->destination['pantheon_domain'],
            ]
        );

        $WPCommand = new WPCommand();

        $WPCommand->wpCommand(
            $this->destination['name'] . '.' . $this->destination['env'],
            array('search-replace ' . $this->source['pantheon_domain'] . ' ' . $this->destination['pantheon_domain'])
        );

        $this->log()->notice("\n");
    }

    /**
     * Call passthru; throw an exception on failure.
     *
     * @param string $command
     */
    protected function passthru($command, $loggedCommand = '')
    {
        $result = 0;
        $loggedCommand = empty($loggedCommand) ? $command : $loggedCommand;
        // TODO: How noisy do we want to be?
        $this->log()->notice("Running {cmd}", ['cmd' => $loggedCommand]);
        passthru($command, $result);
        if ($result != 0) {
            throw new TerminusException('Command `{command}` failed with exit code {status}', ['command' => $loggedCommand, 'status' => $result]);
        }
    }

}
