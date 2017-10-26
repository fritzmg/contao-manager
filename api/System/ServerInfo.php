<?php

namespace Contao\ManagerApi\System;

use Contao\ManagerApi\Config\ManagerConfig;
use Contao\ManagerApi\Process\PhpExecutableFinder;
use Symfony\Component\Yaml\Yaml;
use Terminal42\BackgroundProcess\Forker\DisownForker;
use Terminal42\BackgroundProcess\Forker\NohupForker;

class ServerInfo
{
    const PLATFORM_WINDOWS = 'windows';
    const PLATFORM_UNIX = 'unix';

    /**
     * @var IpInfo
     */
    private $ipInfo;

    /**
     * @var ManagerConfig
     */
    private $managerConfig;

    /**
     * @var array
     */
    private $pathMap;

    /**
     * @var array
     */
    private $domainMap;

    /**
     * @var array
     */
    private $configs;

    /**
     * Constructor.
     *
     * @param IpInfo        $ipInfo
     * @param ManagerConfig $managerConfig
     * @param string        $configFile
     */
    public function __construct(IpInfo $ipInfo, ManagerConfig $managerConfig, $configFile)
    {
        $this->ipInfo = $ipInfo;
        $this->managerConfig = $managerConfig;

        $data = Yaml::parse(file_get_contents($configFile));

        $this->pathMap = $data['paths'];
        $this->domainMap = $data['domains'];
        $this->configs = $data['configs'];
    }

    /**
     * Gets list of known server configurations.
     *
     * @return array
     */
    public function getConfigs()
    {
        return $this->configs;
    }

    /**
     * Detects the current server configuration based on PHP path or hostname.
     *
     * @return string|null
     */
    public function detect()
    {
        // localhost, try path detection
        if ((!isset($_SERVER['REMOTE_ADDR']) || in_array(@$_SERVER['REMOTE_ADDR'], ['127.0.0.1', 'fe80::1', '::1']))
            && null !== ($binary = constant('PHP_BINARY'))
        ) {
            foreach ($this->pathMap as $path => $configName) {
                if (0 === strpos($binary, $path)) {
                    return $configName;
                }
            }
        }

        $ipInfo = $this->ipInfo->collect();

        return $this->findServer($ipInfo['hostname']);
    }

    /**
     * Gets PHP executable by detecting known server paths.
     *
     * @return string|null
     */
    public function getPhpExecutable()
    {
        $paths = [];
        $server = $this->managerConfig->get('server');

        if ($server === 'custom' && ($php_cli = $this->managerConfig->get('php_cli'))) {
            return $php_cli;
        }

        if ($server && isset($this->configs[$server])) {
            foreach ($this->configs[$server]['php'] as $path => $arguments) {
                $paths[] = $this->getPhpVersionPath($path);
            }
        }

        return (new PhpExecutableFinder())->find($paths);
    }

    /**
     * Gets arguments for known PHP executable paths.
     *
     * @return array
     */
    public function getPhpArguments()
    {
        $executable = $this->getPhpExecutable();
        $server = $this->managerConfig->get('server');

        if ($executable && $server && isset($this->configs[$server])) {
            foreach ($this->configs[$server]['php'] as $path => $arguments) {
                if ($this->getPhpVersionPath($path) === $executable) {
                    return $arguments;
                }
            }
        }

        return [];
    }

    /**
     * Gets environment variables for the PHP command line process.
     *
     * @return array
     */
    public function getPhpEnv()
    {
        $env = array_map(function () { return false; }, $_ENV);
        $env['PATH'] = isset($_ENV['PATH']) ? $_ENV['PATH'] : false;
        $env['PHP_PATH'] = $this->getPhpExecutable();

        return $env;
    }

    /**
     * Returns the background process forker classes for the current server.
     *
     * @return array
     */
    public function getProcessForkers()
    {
        $server = $this->managerConfig->get('server');

        if ($server && isset($this->configs[$server]['process_forker'])) {
            return (array) $this->configs[$server]['process_forker'];
        }

        return [DisownForker::class, NohupForker::class];
    }

    /**
     * Returns the server platform (Windows or UNIX).
     *
     * @return string
     */
    public function getPlatform()
    {
        return '\\' === DIRECTORY_SEPARATOR ? self::PLATFORM_WINDOWS : self::PLATFORM_UNIX;
    }

    /**
     * Tries to find a server config from hostname.
     *
     * @param string $hostname
     *
     * @return string
     */
    private function findServer($hostname)
    {
        $offset = 0;

        while ($dot = strpos($hostname, '.', $offset)) {
            if (isset($this->domainMap[substr($hostname, $offset)])) {
                return $this->domainMap[substr($hostname, $offset)];
            }

            $offset = $dot + 1;
        }

        return null;
    }

    /**
     * Gets versionised path to PHP binary.
     *
     * @param string $path
     *
     * @return string
     */
    private function getPhpVersionPath($path)
    {
        return str_replace(
            [
                '{major}',
                '{minor}',
                '{release}',
                '{extra}',
            ],
            [
                PHP_MAJOR_VERSION,
                PHP_MINOR_VERSION,
                PHP_RELEASE_VERSION,
                PHP_EXTRA_VERSION,
            ],
            $path
        );
    }
}
