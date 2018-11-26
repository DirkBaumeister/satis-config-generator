<?php

namespace Twissi\SatisConfigGenerator\Commands;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

class Generator extends Command
{
    /**
     * @var string
     */
    private $configPath;

    /**
     * @var string
     */
    private $workDir;

    /**
     * @var string
     */
    private $composerCacheDir;

    /**
     * @var string
     */
    private $outputFile;

    /** @var Filesystem */
    private $fs;

    /** @var Client */
    private $http;

    public function __construct()
    {
        parent::__construct();

        $this
            ->setName('twissi:satis-config-generator')
            ->setDescription('Generate satis config file as configured in the config.yml file.');

        $this->fs = new Filesystem();
        $this->http = new Client();

        $this->configPath = __DIR__.'/../config.yml';
        $this->workDir = __DIR__.'/../workdir';
        $this->composerCacheDir = __DIR__.'/../composer-cache';
        $this->outputFile = __DIR__.'/../satis-config.json';
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        if (false === $this->fs->exists($this->configPath)) {
            $output->writeln('<error>Please create config file from config.yml.dist!</error>');
            exit(1);
        }

        $configFile = Yaml::parseFile($this->configPath);

        if (
            (false === isset($configFile['config']) || false === is_array($configFile['config'])) &&
            (
                false === is_string($configFile['config']['composer_path']) ||
                false === is_bool($configFile['config']['cache']) ||
                false === is_bool($configFile['config']['cleanup'])
            )
            &&
            (false === isset($configFile['repos']) || false === is_array($configFile['repos'])) &&
            (false === isset($configFile['satis']) || false === is_array($configFile['satis']))
        ) {
            $output->writeln('<error>Please provide config as shown in the config.yml.dist file!</error>');
            exit(1);
        }

        if (isset($configFile['config']['cleanup']) && false === $configFile['config']['cache']) {
            $this->composerCacheDir = '/dev/null';
        }

        $packages = [];

        foreach ($configFile['repos'] as $repoUrl) {
            if (false === $this->createComposerFile($repoUrl)) {
                $output->writeln(sprintf(
                    '<error>There was an error fetching the composer file from %s.</error>',
                    $repoUrl
                ));
                $this->doCleanUp($configFile);
                exit(1);
            }
        }

        foreach ($configFile['repos'] as $repoUrl) {
            $output->writeln('');
            $output->writeln(sprintf('<info># %s</info>', $repoUrl));
            $output->writeln('');
            foreach ($this->getCurrentPackages($configFile, $repoUrl) as $packageName => $packageVersion) {
                if (!isset($packages[$packageName])) {
                    $packages[$packageName] = $packageVersion;
                } else {
                    $packages[$packageName] = $packages[$packageName].'|'.$packageVersion;
                }
            }
        }

        if ($this->generateSatisConfigFile($configFile, $packages)) {
            $output->writeln('');
            $output->writeln('<info>Satis config file created.</info>');
        }

        if ($this->doCleanUp($configFile)) {
            $output->writeln('');
            $output->writeln('<info>Cleaned work directory.</info>');
        }
    }

    /**
     * @param array $configFile
     * @param array $packages
     *
     * @return bool
     */
    private function generateSatisConfigFile($configFile, $packages)
    {
        $data = $configFile['satis'];

        if (!isset($data['require']) || false === is_array($data['require'])) {
            $data['require'] = [];
        }

        foreach ($packages as $packageName => $packageVersion) {
            if (isset($data['require'][$packageName])) {
                if (1 === preg_match(sprintf('/%s/', $packageVersion), $data['require'][$packageName])) {
                    $data['require'][$packageName] = $data['require'][$packageName].'|'.$packageVersion;
                }
            } else {
                $data['require'][$packageName] = $packageVersion;
            }
        }

        $this->fs->dumpFile(
            $this->outputFile,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        return true;
    }

    /**
     * @param array $repoUrl
     *
     * @return bool
     */
    private function createComposerFile($repoUrl)
    {
        $repoWorkDir = $this->getRepoWorkDir($repoUrl);
        $this->fs->mkdir($repoWorkDir);
        if ($composerContent = $this->getComposerFile($repoUrl)) {
            @json_decode($composerContent);
            if (JSON_ERROR_NONE === json_last_error()) {
                $this->fs->dumpFile($repoWorkDir.'/composer.json', $composerContent);
            }
            return true;
        }
        return false;
    }

    /**
     * @param array  $configFile
     * @param string $repoUrl
     *
     * @return array
     */
    private function getCurrentPackages($configFile, $repoUrl)
    {
        $repoWorkDir = $this->getRepoWorkDir($repoUrl);
        chdir($repoWorkDir);

        exec(
            sprintf(
                'COMPOSER_CACHE_DIR=%s %s install --no-scripts',
                $this->composerCacheDir,
                $configFile['config']['composer_path']
            )
        );
        ob_start();
        passthru('composer show -f json');
        $packagesJson = ob_get_contents();
        ob_end_clean();

        $packages = json_decode($packagesJson, true);

        $data = [];

        foreach ($packages['installed'] as $package) {
            if (preg_match('/dev-master/', $package['version'])) {
                $package['version'] = 'dev-master';
            }
            $data[$package['name']] = $package['version'];
        }

        return $data;
    }

    /**
     * @param string $repoUrl
     *
     * @return string
     */
    private function getRepoWorkDir($repoUrl)
    {
        return $this->workDir.'/'.md5($repoUrl);
    }

    /**
     * @param string $repoUrl
     *
     * @return string|bool
     */
    private function getComposerFile($repoUrl)
    {
        try {
            return $this->http->get($repoUrl)->getBody()->getContents();
        } catch (ClientException $e) {
            return false;
        }
    }

    /**
     * @param array $configFile
     *
     * @return bool
     */
    private function doCleanUp($configFile)
    {
        if (isset($configFile['config']['cleanup']) && true === $configFile['config']['cleanup']) {
            exec(
                sprintf('rm -rf %s/*', $this->workDir)
            );
            return true;
        }
        return false;
    }
}
