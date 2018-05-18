<?php
/**
 * Terminus Plugin that that adds command(s) to facilitate cloning sites on [Pantheon](https://www.pantheon.io)
 *
 * See README.md for usage information.
 */

namespace Pantheon\TerminusSiteClone\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Commands\Site\SiteCommand;
use Pantheon\Terminus\Request\RequestAwareInterface;
use Pantheon\Terminus\Request\RequestAwareTrait;
use Pantheon\Terminus\Commands\Backup\SingleBackupCommand;

/**
 * Site Clone Command
 * @package Pantheon\TerminusSiteClone\Commands
 */
class SiteCloneCommand extends SingleBackupCommand implements RequestAwareInterface
{
    use RequestAwareTrait;
    
    /**
     * Copy the code, db and files from the specified Pantheon source site
     * to the specified Pantheon destination site.
     *
     * @command site:clone
     * @aliases site:copy
     * @param string $user_source The site UUID or machine name of the SOURCE (<site>.<env>)
     * @param string $user_destination The site UUID or machine name of the DESTINATION (<site>.<env>)
     * @param array $options
     * @option database Clone the database.
     * @option files Clone the (media) files.
     * @option code Clone the code.
     * @option backup Backup the source and destination sites before cloning.
     */
    public function clonePantheonSite(
            $user_source,
            $user_destination,
            $options = [
                'database' => true,
                'files' => true,
                'code' => true,
                'backup' => true,
        ])
    {

        // Make sure options are booleans and not strings
        foreach( $options as $key => $value ){
            $options[$key] = boolval( $value );
        }

        $source = $this->fetchSiteDetails($user_source);
        $destination = $this->fetchSiteDetails($user_destination);

        if( $source['php_version'] !== $destination['php_version'] ){
            $this->log()->notice(
                'Warning: the source site has a PHP version of {src_php} and the destination site has a PHP version of {dest_php}',
                [
                    'src_php' => $source['php_version'],
                    'dest_php' => $destination['php_version'],
                ]
            );

            $proceed = $this->confirm('The environments do not have matching PHP versions. Would you like to proceed? This will overwrite the PHP version of the destination.');
            
            if (!$proceed) {
                return;
            }
        }

        if( $source['framework'] !== $destination['framework'] ){
            throw new TerminusException('Cannot clone sites with different frameworks.');
        }
        
        if( $source['frozen'] || $destination['frozen'] ){
            // @todo: Ask the user if they want to unfreeze the site
            throw new TerminusException('Cannot clone sites that are frozen.');
        }

        if( in_array($destination['env'], ['test', 'live']) && $options['code'] ){
            throw new TerminusException('Cannot clone code to the test or live environments. To clone database and files use --no-code.');
        }
        
        if( in_array($source['env'], ['test', 'live']) && $options['code'] ){
            throw new TerminusException('Cannot clone code from the test or live environments. To clone database and files use --no-code.');
        }

        $confirmation_message = 'Are you sure you want to clone from the {src}.{src_env} environment (source) to the {dest}.{dest_env} (destination)? This will completely destroy the destination.';

        if( $options['backup'] ){
            $confirmation_message .= ' A backup will be made first, just in case.';
        }

        $confirm = $this->confirm(
            $confirmation_message . "\n",
            [ 
                'src' => $source['name'],
                'src_env' => $source['env'],
                'dest' => $destination['name'],
                'dest_env' => $destination['env'],
            ]
        );

        if( ! $confirm ){
            return;
        }

        if( $options['backup'] ){
            $this->createBackup($destination);
        }

        $backup_elements = $this->getBackupElements($options);

        $this->log()->notice(
            'Cloning the following elements: {elements}',
            [
                'elements' => implode( ', ', $backup_elements),
            ]
        );
        
        $source_backups = [];
        
        foreach( $backup_elements as $element ){

            if( 'code' !== $element ){
            
                if( $options['backup'] ){
                    $this->createBackup($source, $element);
                }

                $source_backups[$element] = $this->getLatestBackup($source, $element, $options);

                if( !isset( $source_backups[$element]['url'] ) || empty( $source_backups[$element]['url'] ) ){
                    $this->log()->notice(
                        'Failed to backup {element} on the {site}.{env} environment. It will not be imported.',
                        [
                            'site' => $source['name'],
                            'env' => $source['env'],
                            'element' => $element,
                        ]
                    );
                    continue;
                }

                $this->importBackup($source, $destination, $element, $source_backups[$element]['url']);

            } else {
                $this->importBackup($source, $destination, $element);
            }

        }

    }

    /**
     * Fetch details of a site from '<site>.<env>' format
     *
     * @param string $site_env
     * @return array
     */
    private function fetchSiteDetails($site_env){
        $parsed = $this->getSiteEnv($site_env);
        $return = $parsed[0]->serialize();
        $return['site_raw'] = $parsed[0];
        // Turn the string value of 'true' or 'false' into a boolean
        $return['frozen'] = filter_var($return['frozen'], FILTER_VALIDATE_BOOLEAN);
        $return['env'] = $parsed[1]->id;
        $return['env_raw'] = $parsed[1];
        $return['url'] = 'https://' . $return['env'] . '-' . $return['name'] . '.pantheonsite.io/';
        $return['pantheon_domain'] = $return['env'] . '-' . $return['name'] . '.pantheonsite.io';

        return $return;
    }

    /**
     * Get backup elements based on input options
     *
     * @param array $options
     * @return array
     */
    private function getBackupElements($options)
    {
        $elements = [];
            
        if ( $options['code'] ) {
            $elements[] = 'code';
        }

        if ( $options['database'] ) {
            $elements[] = 'database';
        }
        
        if ( $options['files'] ) {
            $elements[] = 'files';
        }

        if( empty($elements) ){
            throw new TerminusNotFoundException('You cannot skip cloning all elements.');
        }

        return $elements;
    }

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
        
        $backup = $site['env_raw']->getBackups()->create($backup_options);

        while (!$backup->checkProgress()) {
            // @todo: Add Symfony progress bar to indicate that something is happening.
        }

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

    private function getLatestBackup($site, $element = 'all', $options)
    {
        // Refresh the site info.
        // Without this call it was possible to get an empty list.
        // https://github.com/pantheon-systems/terminus-site-clone-plugin/issues/2
        $site = $this->fetchSiteDetails($site['name'] . '.' . $site['env']);

        $backups = $site['env_raw']->getBackups()->getFinishedBackups($element);

        if ( empty($backups) ) {
            
            if( ! $options['backup'] ){
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

        $return['url'] = $latest_backup->getUrl();

        return $return;
    }

    private function importBackup($source, $destination, $element, $url = null)
    {
        
        switch( $element ){
            case 'db':
            case 'database':
                $this->log()->notice(
                    'Importing the database on {site}.{env}...',
                    [
                        'site' => $destination['name'],
                        'env' => $destination['env'],
                    ]
                );

                $workflow = $destination['env_raw']->importDatabase($url);
                
                while ( !$workflow->checkProgress() ) {
                    // @todo: Add Symfony progress bar to indicate that something is happening.
                }

                $this->log()->notice(
                    "Imported the database to {site}.{env}.\n",
                    [
                        'site' => $destination['name'],
                        'env' => $destination['env'],
                    ]
                );

                if( 'wordpress' === $destination['framework'] ){
                    $this->wpcliSearchReplace($source, $destination);
                }
                break;

            case 'files':
                $this->log()->notice(
                    'Importing media files on {site}.{env}...',
                    [
                        'site' => $destination['name'],
                        'env' => $destination['env'],
                    ]
                );

                $workflow = $destination['env_raw']->importFiles($url);
                
                while ( !$workflow->checkProgress() ) {
                    // @todo: Add Symfony progress bar to indicate that something is happening.
                }

                $this->log()->notice(
                    "Imported the media files to {site}.{env}.\n",
                    [
                        'site' => $destination['name'],
                        'env' => $destination['env'],
                    ]
                );
                break;

            case 'code':
                $this->log()->notice(
                    'Importing code on {site}.{env} with git...',
                    [
                        'site' => $destination['name'],
                        'env' => $destination['env'],
                    ]
                );

                $site_clone_dir = getcwd();
                $temp_dir = $site_clone_dir . '/terminus-site-clone-temp/';
                $git_dir = $temp_dir . $source['name'] . '/';
                $source_connection_info = $source['env_raw']->connectionInfo();
                $source_git_url = $source_connection_info['git_url'];
                $source_git_branch = ( in_array($source['env'], ['dev', 'test', 'live']) ) ? 'master' : $source['env'];
                $destination_connection_info = $destination['env_raw']->connectionInfo();
                $destination_git_url = $destination_connection_info['git_url'];
                $destination_git_branch = ( in_array($destination['env'], ['dev', 'test', 'live']) ) ? 'master' : $destination['env'];

                $destination['env_raw']->changeConnectionMode('git');
                
                clearstatcache();
                if( ! file_exists( $git_dir ) ){
                    mkdir($temp_dir, 0700, true);

                    $this->log()->notice(
                        'Cloning code for {site}.{env} to {git_dir}...',
                        [
                            'site' => $destination['name'],
                            'env' => $destination['env'],
                            'git_dir' => $git_dir,
                        ]
                    );

                    $this->passthru("git clone $source_git_url $git_dir");
                } else {
                    $this->log()->notice(
                        '{git_dir} already exists for for {site}.{env}. Fetching the latest...',
                        [
                            'site' => $destination['name'],
                            'env' => $destination['env'],
                            'git_dir' => $git_dir,
                        ]
                    );

                    $this->passthru("git -C $git_dir remote set-url origin " . $source_git_url);
                    $this->passthru("git -C $git_dir fetch --all");
                    $this->passthru("git -C $git_dir pull origin $source_git_branch");
                    $this->passthru("git -C $git_dir remote set-url origin " . $destination_git_url);
                }
                
                if( false === in_array( $destination['env'], ['dev','test','live'] ) ){
                    $this->passthru("git -C $git_dir checkout " . $source_git_branch);
                    $this->passthru("git -C $git_dir fetch origin " . $source_git_branch);
                    $this->passthru("git -C $git_dir merge origin/" . $source_git_branch);
                }

                $this->log()->notice(
                    'Force pushing to the {site}.{env} on the {git_branch} branch.',
                    [
                        'site' => $destination['name'],
                        'env' => $destination['env'],
                        'git_dir' => $git_dir,
                        'git_branch' => $destination_git_branch,
                    ]
                );

                $this->passthru("git -C $git_dir remote set-url origin " . $destination_git_url);
                
                $this->passthru("git -C $git_dir push origin $source_git_branch:$destination_git_branch --force");

                $this->log()->notice(
                    "Sucessfully imported code to {site}.{env}.\n",
                    [
                        'site' => $destination['name'],
                        'env' => $destination['env'],
                    ]
                );
        }
    }

    private function wpcliSearchReplace($source, $destination)
    {
        $this->log()->notice(
            "Using wp-cli search-replace to replace {src_url} with {dest_url} on {site}.{env} as WordPress stores URLs in the database. You may need to run the search-replace manually if using custom domains.\n",
            [
                'site' => $destination['name'],
                'env' => $destination['env'],
                'src_url' => $source['pantheon_domain'],
                'dest_url' => $destination['pantheon_domain'],
            ]
        );

        $destination['env_raw']->sendCommandViaSsh('wp search-replace ' . $source['pantheon_domain'] . ' ' . $destination['pantheon_domain']);

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
