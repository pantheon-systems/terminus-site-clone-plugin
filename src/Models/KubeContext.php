<?php

namespace Pantheon\TerminusSiteClone\Models;


use Pantheon\Terminus\Config\DefaultsConfig;
use Pantheon\Terminus\Config\DotEnvConfig;
use Pantheon\Terminus\Config\EnvConfig;
use Pantheon\Terminus\Config\YamlConfig;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Terminus;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 *
 */
class KubeContext
{
    /**
     * @var string
     */
    private ?string $cluster;
    /**
     * @var string
     */
    private ?string $ns;

    /**
     * @param $context
     * @param $kubeconfig
     */
    public function __construct(string $cluster, string $namespace)
    {
        $this->cluster = $cluster;
        $this->ns = $namespace;
    }

    public static function fromJson(string $json): KubeContext
    {
        $decoded = json_decode($json, true);
        if (empty($decoded)) {
            $err = json_last_error_msg();
            throw new TerminusException('Could not parse the kubernetes context: ' . $err);
        }
        $this->cluster = $decoded['cluster'];
        $this->ns = $decoded['namespace'];
    }


    /**
     * @return string
     */
    public function getCluster(): string
    {
        return $this->cluster;
    }

    /**
     * @param string $context
     * @return void
     */
    public function setCluster(string $cluster)
    {
        $this->cluster = $cluster;
    }

    /**
     * @return string
     */
    public function getNs(): string
    {
        return $this->ns;
    }

    /**
     * @param string $kubeconfig
     * @return void
     */
    public function setNs(string $ns)
    {
        $this->ns = $ns;
    }

    /**
     * @return bool
     */
    public function valid(): bool
    {
        return !empty($this->ns);
    }

    /**
     * @return Terminus
     */
    public function getTerminus(InputInterface $input, OutputInterface $output = null): Terminus
    {
        return new Terminus($this->getConfig(), $input, $output);
    }

    /**
     * @return array
     */
    public function getTerminus(InputInterface $input, OutputInterface $output = null): array
    {
        // if there's no output provided, just use the default console output
        if ($output == null) {
            $output = new ConsoleOutput();
        }
        $config = new DefaultsConfig();
        // Default root path is the terminus root directory
        // note: this may be inside of a phar file
        $root_path = $config->get('root');
        // Default home path is the user's home directory
        $home_path = $config->get('user_home');
        if ($this->ns !== null && $this->ns !== 'production') {
            // if the namespace is not production, we assume it is a sandbox namespace
            // and use the defaults folder in the sandbox's home directory
            $home_path = $config->get('user_home') . DIRECTORY_SEPARATOR . "." . $this->ns;
            $config->set('base_dir', $home_path);
        }
        // DefaultConstants for every version of terminus
        $config->extend(new YamlConfig($root_path . '/config/constants.yml'));
        // you can override the constants with a local config file
        // inside the sandbox or production directory with a file named config.yml
        $config->extend(new YamlConfig($home_path . '/config.yml'));
        // you can override the constants with a local env file
        // just be sure to preface all the variables with TERMINUS_
        $config->extend(new DotEnvConfig(getcwd()));
        $config->extend(new EnvConfig());
        $dependencies_folder_absent = false;
        if ($dependencies_version) {
            $dependenciesBaseDir = $config->get('dependencies_base_dir');
            $terminusDependenciesDir = $dependenciesBaseDir . '-' . $dependencies_version;
            $config->set('terminus_dependencies_dir', $terminusDependenciesDir);
            if (file_exists($terminusDependenciesDir . '/vendor/autoload.php')) {
                include_once("$terminusDependenciesDir/vendor/autoload.php");
            } else {
                $dependencies_folder_absent = true;
            }
        }
        if ($this->ns !== null && $this->ns !== 'production') {
            // if the namespace is not production, we assume it is a sandbox namespace
            // and get the IP address of the sandbox's load balancer
            $external_ip = exec('kubectl get svc pantheonapi -o jsonpath="{.status.loadBalancer.ingress[0].ip}"');
            $config->set("host", "https://${external_ip}");
            // This is the ssh host for the sandbox
            $ssh_host = exec(
                'kubectl get nodes --selector alpha.pantheon.io/cos-namespace=sandbox-lops,alpha.pantheon.io/type=appserver -o json | jq -r ".items[0].status.addresses[1].address"'
            );
            $config->set("ssh_host", $ssh_host);
        }


        $terminus = new static($config, $input, $output);

        if ($dependencies_folder_absent && $terminus->hasPlugins()) {
            $omit_reload_warning = true;
            $input_string = (string)$input;
            $plugin_reload_command_names = [
                'self:plugin:reload',
                'self:plugin:refresh',
                'plugin:reload',
                'plugin:refresh',
            ];
            foreach ($plugin_reload_command_names as $command_name) {
                if (strpos($input_string, $command_name) !== false) {
                    $omit_reload_warning = true;
                    break;
                }
            }

            if (!$omit_reload_warning) {
                $terminus->logger->warning(
                    'Could not load plugins because Terminus was upgraded. ' .
                    'Please run terminus self:plugin:reload to refresh.',
                );
            }
        }
        return $terminus;
    }

}