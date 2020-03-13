<?php

namespace Globalis\WP\Cubi\Robo;

trait ConfigTrait
{
    protected $properties = [];
    protected $config     = [];

    public function configure($environment = 'development', $options = ['only-missing' => false])
    {
        return $this->collectionBuilder()
            ->addTask($this->configureTask($environment, !$options['only-missing']));
    }

    protected function configureTask($environment = 'development', $force = false)
    {
        if($force) {
            $this->configurePrintInfo($environment);
        }

        return $this->collectionBuilder()
            ->addTask(
                $this->taskConfiguration()
                    ->initConfig($this->getProperties($environment))
                    ->configFilePath($this->fileVarsLocal($environment))
                    ->force($force)
            )
            ->addCode(function ($data) use ($environment) {
                $this->config[$environment] = $data->getData();
                foreach ($this->config[$environment] as $key => $value) {
                    if (is_string($value)) {
                        $this->config[$environment][$key . '_PQ'] = preg_quote($value);
                    }
                }
            });
    }

    protected function configurePrintInfo($environment)
    {
        $this->io()->section('ENVIRONMENT CONFIGURATION');
        $this->io()->text(sprintf('Answer a few questions to setup project configuration for environment: %s', $environment));
        $this->io()->text('Configuration will be saved at ' . $this->fileVars($environment));
        if ('development' === $environment) {
            $this->io()->text('You can change configuration later by manually editing this file, or by running `./vendor/bin/robo configure`');
        } else {
            $this->io()->text(sprintf('You can change configuration later by manually editing this file, or by running `./vendor/bin/robo configure %s`', $environment));
        }
    }

    protected function getConfig($environment, $key = null)
    {
        if(!isset($this->config[$environment])) {
            $this->configureTask($environment);
        }
        return isset($key) ? $this->config[$environment][$key] : $this->config[$environment];
    }

    protected function getProperties($environment)
    {
        if (!isset($this->properties[$environment])) {
            $this->properties[$environment] = include $this->path(\RoboFile::PATH_FILE_PROPERTIES);

            if (!$this->isLocalEnv($environment)) {
                $propertiesRemote = $this->path(include \RoboFile::PATH_FILE_PROPERTIES_REMOTE);
                $this->properties[$environment] = array_merge($this->properties[$environment], $propertiesRemote);
            }
        }
        return $this->properties[$environment];
    }

    protected function fileVars($environment = 'development')
    {
        switch ($environment) {
            case 'development':
                return \RoboFile::PATH_FILE_CONFIG_VARS;        
            default:
                return sprintf(\RoboFile::PATH_FILE_CONFIG_VARS_REMOTE, $environment);
        }
        
    }
}
