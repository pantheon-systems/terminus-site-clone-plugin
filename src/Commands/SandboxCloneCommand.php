<?php

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
class SandboxCloneCommand extends SingleBackupCommand implements RequestAwareInterface
{
    use RequestAwareTrait;
    use WorkflowProcessingTrait;
    use SiteAwareTrait;

    private $source;
    private $destination;

    /**
     * Clones a site from a production pantheon site to a new site
     * in the current active kube sandbox.
     *
     * @command sandbox:clone
     *
     * @param string $source The source site to clone from
     * @param string $destination The destination site to clone to
     *
     * @usage terminus sandbox:clone <source> <destination>
     *   Clones the site from the source to the destination
     */

    public function sandboxClone(
        string $source,
        string $destination,
        array $sourceOptions = ['env' => 'live'],
        array $destinationOptions = ['env' => 'dev']
    ){
        // get the value of the PANTHEON_CERT env var and use it to load the
        // user's employee certificate first.
        $cert = getenv('PANTHEON_CERT');
        $this->log()->notice('PANTHEON_CERT: ' . $cert);
        $this->log()->notice('Loading employee certificate...');
        $this->request()->setCertificate($cert);

        $this->source = $source;
        $this->destination = $destination;

        $this->validateSource();
        $this->validateDestination();
        $this->cloneSite();
    }

}








// kubectl config view --minify -o jsonpath='{..namespace}'
