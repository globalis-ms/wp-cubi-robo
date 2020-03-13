<?php

namespace Globalis\WP\Cubi\Robo;

class RoboFile extends \Globalis\Robo\Tasks
{
    protected $properties = [];
    protected $config     = [];

    public function configure($environment = 'development', $options = ['only-missing' => false])
    {
        return $this->collectionBuilder()
            ->addTask($this->loadConfigTask($environment, $options['only-missing']));
    }

    protected function loadConfigTask($environment = 'development', $options = ['only-missing' => false])
    {
        $tasks = $this->collectionBuilder();
        if (isset($this->config[$environment])) {
            $tasks;
        }

        return $tasks
            ->addTask(
                $this->taskConfiguration()
                    ->initConfig($this->getProperties($environment))
                    ->configFilePath($this->fileVarsLocal($environment))
                    ->force(!$options['only-missing'])
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

    protected function getConfig($environment, $key = null)
    {
        $this->configure($environment, ['only-missing' => true]);
        return isset($key) ? $this->config[$environment][$key] : $this->config[$environment];
    }

    protected function getProperties($environment)
    {
        if (!isset($this->properties[$environment])) {
            $this->properties[$environment] = include \RoboFile::PATH_FILE_PROPERTIES;

            if (!$this->isLocalEnv($environment)) {
                $propertiesRemote = include \RoboFile::PATH_FILE_PROPERTIES_REMOTE;
                $this->properties[$environment] = array_merge($this->properties[$environment], $propertiesRemote);
            }
        }
        return $this->properties[$environment];
    }

    protected function fileVarsLocal($environment = 'development')
    {
        if ($this->isLocalEnv($environment)) {
            return \Globalis\WP\Cubi\trailingslashit(\RoboFile::ROOT) . \RoboFile::PATH_FILE_CONFIG_VARS;
        } else {
            return \Globalis\WP\Cubi\trailingslashit(\RoboFile::ROOT) . sprintf(\RoboFile::PATH_FILE_CONFIG_VARS_REMOTE, $environment);
        }
    }

    protected function isLocalEnv($environment)
    {
        return in_array($environment, \RoboFile::LOCAL_ENVS);
    }
}
