<?php

namespace Globalis\WP\Cubi\Robo;

use ConfigTrait;

trait BuildTrait
{
    public function build()
    {
        return $this->collectionBuilder()
            ->addTask($this->buildTask());
    }

    public function buildComposer()
    {
        return $this->collectionBuilder()
            ->addTask($this->buildComposerTask());
    }

    public function buildHtaccess()
    {
        return $this->collectionBuilder()
            ->addTask($this->buildHtaccessTask());
    }

    protected function buildTask($environment = 'development', $root = \RoboFile::ROOT)
    {
        return $this->collectionBuilder()
            ->addTask($this->buildComposerTask($environment, $root))
            ->addTask($this->buildHtaccessTask($environment, $root));
    }

    protected function buildComposerTask($environment = 'development', $root = \RoboFile::ROOT)
    {
        if($this->isLocalEnv($environment)) {
            return $this->buildComposerLocalTask($environment, $root);
        } else {
            return $this->buildComposerRemoteTask($environment, $root);
        }
    }

    protected function buildComposerLocalTask($environment = 'development')
    {
        return $this->taskComposerInstall()
            ->workingDir($root)
            ->preferDist();
        }
    }

    protected function buildComposerRemoteTask($environment = 'development', $root = \RoboFile::ROOT)
    {
        return $this->taskComposerInstall()
            ->workingDir($root)
            ->preferDist()
            ->noDev()
            ->optimizeAutoloader();
    }

    protected function buildHtaccessTask($environment = 'development', $root = \RoboFile::ROOT, $startPlaceholder = '<##', $endPlaceholder = '##>')
    {
        $config = array_filter($this->getConfig($environment), 'is_string');

        return $this->collectionBuilder()
            ->addTask(
                $this->taskConcat($this->getHtaccessParts($environment, $root))
                    ->to($this->path(RoboFile::HTACCESS_BUILD, $root))
            )
            ->addTask(
                $this->taskReplacePlaceholders($this->path(RoboFile::HTACCESS_BUILD, $root))
                    ->from(array_keys($config))
                    ->to($config)
                    ->startDelimiter($startPlaceholder)
                    ->endDelimiter($endPlaceholder)
            );
    }

    protected function getHtaccessParts($environment = 'development', $root = \RoboFile::ROOT)
    {
        $dirSrc   = $this->untrailingslashit($this->path(RoboFile::HTACCESS_CONFIG_DIRECTORY, $root));
        $parts    = [];

        foreach (\RoboFile::HTACCESS_PARTS as $part) {
            if (file_exists($dirSrc . '/' . $part . '-local')) {
                $parts[] = $dirSrc . '/' . $part . '-local';
            } elseif (file_exists($pathSrc . '/' . $part . '-' . $environment)) {
                $parts[] = $dirSrc . '/' . $part . '-' . $environment;
            } elseif (file_exists($pathSrc . '/' . $part)) {
                $parts[] = $dirSrc . '/' . $part;
            }
        }

        return $parts;
    }
}
