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
     * @option no-db Skip cloning the database
     * @option no-files Skip cloning the (media) files
     * @option no-code Skip cloning the code
     * @option no-backup Skip making a fresh backup for export from the source site AND skip backup creation before import on the destination site
     */
    public function clonePantheonSite(
            $user_source,
            $user_destination,
            $options = [
                'no-db' => false,
                'no-files' => false,
                'no-code' => false,
                'no-backup' => false,
        ])
    {
        // $user = $this->session()->getUser();

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

            $proceed = $this->confirm('The sites do not have matching PHP versions. Would you like to proceed?');
            
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

        $confirmation_message = 'Are you sure you want to clone from the {src}.{src_env} environment (source) to the {dest}.{dest_env} (destination)? This will completely destroy the destination.';

        if( ! $options['no-backup'] ){
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

        if( ! $options['no-backup'] ){

            $this->createBackup($destination);
        }

        $backup_elements = $this->getBackupElements($options);
        
        $source_backups = [];
        
        foreach( $backup_elements as $element ){
            
            if( ! $options['no-backup'] ){
                $this->createBackup($source, $element);
            }

            $source_backups[$element] = $this->getLatestBackup($source, $element);

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
            
        if ( ! $options['no-db'] ) {
            $elements[] = 'database';
        }
        
        if ( ! $options['no-code'] ) {
            $elements[] = 'code';
        }
        
        if ( ! $options['no-files'] ) {
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

    private function getLatestBackup($site, $element = 'all')
    {
        // $backup_options = ['file' => null, 'element' => $element, 'to' => null,];

        $backups = $site['env_raw']->getBackups()->getFinishedBackups($element);

        if ( empty($backups) ) {
            $this->log()->notice(
                "No {element} backups in the {site}.{env} environment found.\n",
                [
                    'site' => $site['name'],
                    'env' => $site['env'],
                    'element' => $element,
                ]
            );

            $this->createBackup($site);
        }

        $latest_backup = array_shift($backups);

        $return = $latest_backup->serialize();

        $return['url'] = $latest_backup->getUrl();

        return $return;
    }

    private function importBackup($source, $destination, $element, $url = null)
    {
        if( ! $url || null === $url ){
            return;
        }
        
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
                /*
                $this->log()->notice(
                    'Importing code for {site}.{env}...',
                    [
                        'site' => $destination['name'],
                        'env' => $destination['env'],
                    ]
                );

                // todo: actually import code

                $this->log()->notice(
                    "Imported code to {site}.{env}.\n",
                    [
                        'site' => $destination['name'],
                        'env' => $destination['env'],
                    ]
                );
                */

                $this->log()->notice(
                    "Importing code for {site}.{env} goes here...\n",
                    [
                        'site' => $destination['name'],
                        'env' => $destination['env'],
                    ]
                );
                break;
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

}