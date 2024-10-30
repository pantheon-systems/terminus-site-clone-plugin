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
use Pantheon\TerminusSiteClone\Models\KubeContext;

/**
 * Site Clone Command
 * @package Pantheon\TerminusSiteClone\Commands
 */
class SandboxCloneCommand extends SingleBackupCommand implements RequestAwareInterface
{
    use RequestAwareTrait;
    use WorkflowProcessingTrait;

    private \Pantheon\Terminus\Models\Site $sourceSite;
    private \Pantheon\Terminus\Models\Site $destinationSite;

    /**
     * Clones a site from a production pantheon site to a new site
     * in the current active kube sandbox.
     *
     * @command sandbox:clone
     *
     * @param string $source The source site to clone from
     * @param string $destination The destination site to clone to
     * @param array $sourceOptions Options for the source site
     * @param array $destinationOptions Options for the destination site
     *
     * @usage terminus sandbox:clone <source> <destination>
     *   Clones the site from the source to the destination
     *
     * @assumptions
     *  - The source site is a pantheon site in the live production environment
     *  - The destination site is a new site in the current active kube sandbox
     *  - There is a logged in user with your current terminus production install
     *    e.g. `terminus auth:login` returns successfully.
     *  - The user has a pantheon employee certificate and the path to that cert
     *    is stored in the PANTHEON_CERT environment variable
     *  - The logged-in terminus platform user has the necessary permissions to clone the site
     *  - You have set up the TERMINUS_CACHE_DIR for termibox to $HOME/.termibox as per the
     *    instructions here:
     *    https://getpantheon.atlassian.net/wiki/spaces/VULCAN/pages/1596064047/Using+Terminus+with+Sandboxes
     *  - There is a valid cached terminus session for the sandbox in $HOME/.termibox
     */

    public function sandboxClone(
        string $sourceSiteName,
        string $destinationSiteName,
        array $sourceOptions = [
            'env' => 'live',
            'cluster' => null,
            'namespace' => 'production',
        ],
        array $destinationOptions = [
            'env' => 'dev',
            'cluster' => null,
            'namespace' => null
        ],
    ){
        // get the value of the PANTHEON_CERT env var and use it to load the
        // user's employee certificate first.
        $cert = getenv('PANTHEON_CERT');
        if (empty($cert)) {
            throw new TerminusException('In order to use this plugin you need a pantheon employee certificate. Set the PANTHEON_CERT to the path of the .pem.');
        }

        // Where is the work being done?
        $sourceContext = $this->getKubeContext($sourceOptions);
        $destinationContext = $this->getKubeContext($destinationOptions);

        if (! $sourceContext instanceof KubeContext || $sourceContext->valid() === false) {
            throw new TerminusException('Could not get/parse the kube source context.');
        }
        if (! $destinationContext instanceof KubeContext || $destinationContext->valid() === false) {
            throw new TerminusException('Could not get/parse the kube destination context.');
        }

        $this->log()->notice('PANTHEON_CERT: ' . $cert);
        $this->log()->notice('Loading employee certificate...');

        if (!file_exists($cert)) {
            throw new TerminusException('The certificate file does not exist at the path provided.');
        }

        // This class should have been pre-populated with a guzzle client
        // if not, make one.
        $cli = $this->request->getClient();
        if (! $cli instanceof \GuzzleHttp\Client) {
            $cli = new \GuzzleHttp\Client();
        }

        $this->log()->notice('Source site: ' $sourceContext->toString() . DIRECTORY_SEPARATOR . $sourceSiteName);
        $this->log()->notice('Destination site: ' $destinationContext->toString() . DIRECTORY_SEPARATOR . $destinationSiteName);

        $this->validateDestination();
        $this->cloneSite();
    }



    function getKubeContext($options) ?KubeContext {
        if ($this->ns == "production") {
            return new KubeContext();
        }

        // get the current context and use as default values
        $json = exec("kubectl config view --minify -o jsonpath='{..context}'");
        $toReturn = new KubeContext();
        if (!empty($json)) {
            $toReturn = new KubeContext::fromJson($kubeContext);
        }
        // if the values are set in the options, override all the other values
        if (isset($options['cluster']) ) {
            $toReturn->setCluster($options['cluster']);
        }
        if (isset($options['namespace']) ) {
            $toReturn->setNamespace($options['namespace']);
        }
        return $toReturn;
    }

    function protected getSiteModelFromSiteName(KubeContext $context, string $siteName): \Pantheon\Terminus\Models\Site {
        // get the site model from the site name

        return $site;
    }



}








//
