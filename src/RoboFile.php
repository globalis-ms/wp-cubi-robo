<?php

namespace Globalis\WP\Cubi\Robo;

class RoboFile extends \Globalis\Robo\Tasks
{
    protected $properties = [];
    protected $config     = [];

    /**
     * Configure project
     *
     * @param   string  $environment
     * @option  boolean only-missing
     */
    public function configure($environment = 'development', $options = ['only-missing' => false])
    {
        return $this->collectionBuilder()
            ->addTask($this->loadConfigTask($environment, $options['only-missing']));
    }

    protected function loadConfigTask($environment, $onlyMissing = true)
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
                    $this->config[$environment][$key . '_PQ'] = preg_quote($value);
                }
                if (method_exists($this, 'postLoadConfig')) {
                    $this->postLoadConfig($environment);
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

            if ('development' !== $environment) {
                $propertiesRemote = include \RoboFile::PATH_FILE_PROPERTIES_REMOTE;
                $this->properties[$environment] = array_merge($this->properties[$environment], $propertiesRemote);
            }
        }
        return $this->properties[$environment];
    }

    protected function fileVarsLocal($environment = 'development')
    {
        if ('development' === $environment) {
            return self::trailingslashit(\RoboFile::ROOT) . \RoboFile::PATH_FILE_CONFIG_VARS;
        } else {
            return self::trailingslashit(\RoboFile::ROOT) . sprintf(\RoboFile::PATH_FILE_CONFIG_VARS_REMOTE, $environment);
        }
    }

    protected static function trailingslashit($string)
    {
        return self::untrailingslashit($string) . '/';
    }

    protected static function untrailingslashit($string)
    {
        return rtrim($string, '/\\');
    }
}
