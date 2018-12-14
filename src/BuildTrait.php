<?php

namespace Globalis\WP\Cubi\Robo;

trait BuildTrait
{
    public function build($environment = 'development', $root = \RoboFile::ROOT, $ignore_assets = false)
    {
        $task = $this->collectionBuilder()
            ->addTask($this->buildComposer($environment, $root))
            ->addTask($this->buildConfig($environment, $root))
            ->addTask($this->buildHtaccess($environment, $root));

        if (!$ignore_assets && method_exists($this, 'buildAssets')) {
            $task->addTask($this->buildAssets($environment, $root));
        }

        return $task;
    }

    public function buildComposer($environment = 'development', $root = \RoboFile::ROOT)
    {
        $task = $this->taskComposerInstall()
            ->workingDir($root)
            ->preferDist();

        if ('development' !== $environment) {
            $task->noDev()
            ->optimizeAutoloader();
        }

        return $task;
    }

    public function buildConfig($environment = 'development', $root = \RoboFile::ROOT)
    {
        return $this->collectionBuilder()
            ->addCode(function () use ($environment, $root) {
                $fileVarsLocal = $this->fileVarsLocal($environment, $root);
                if (!file_exists($fileVarsLocal)) {
                    $this->io()->section('ENVIRONMENT CONFIGURATION');
                    $this->io()->text(sprintf('Answer a few questions to setup project configuration for environment: %s', $environment));
                    $this->io()->text('Configuration will be saved at ' . $fileVarsLocal);
                    if ('development' === $environment) {
                        $this->io()->text('You can change configuration later by manually editing this file, or by running `./vendor/bin/robo configure`');
                    } else {
                        $this->io()->text(sprintf('You can change configuration later by manually editing this file, or by running `./vendor/bin/robo configure %s`', $environment));
                    }
                }

                $this->getConfig($environment);

                $target = self::trailingslashit($root) . \RoboFile::PATH_FILE_CONFIG_VARS;
                if (!file_exists($target)) {
                    copy($fileVarsLocal, $target);
                }

                $target = self::trailingslashit($root) . \RoboFile::PATH_FILE_CONFIG_LOCAL;
                if (!file_exists($target)) {
                    $source = self::trailingslashit(\RoboFile::ROOT) . \RoboFile::PATH_FILE_CONFIG_LOCAL_SAMPLE;
                    copy($source, $target);
                }
            });
    }

    public function buildHtaccess($environment = 'development', $root = \RoboFile::ROOT, $startPlaceholder = '<##', $endPlaceholder = '##>')
    {
        $pathBuild = self::trailingslashit($root) . \RoboFile::HTACCESS_BUILD;
        $pathSrc   = self::trailingslashit($root) . \RoboFile::HTACCESS_CONFIG_DIRECTORY;
        $parts     = [];

        foreach (\RoboFile::HTACCESS_PARTS as $part) {
            $partOverriden = $pathSrc . '/' . $part . '-' . $environment;
            if (file_exists($partOverriden)) {
                $parts[] = $partOverriden;
            } else {
                $parts[] = $pathSrc . '/' . $part;
            }
        }
        $config = $this->getConfig($environment);

        return $this->collectionBuilder()
            ->addTask(
                $this->taskConcat($parts)
                    ->to($pathBuild)
            )
            ->addTask(
                $this->taskReplacePlaceholders($pathBuild)
                    ->from(array_keys($config))
                    ->to($config)
                    ->startDelimiter($startPlaceholder)
                    ->endDelimiter($endPlaceholder)
            );
    }
}
