<?php

namespace Globalis\WP\Cubi\Robo;

use ConfigTrait;
use BuildTrait;

trait InstallTrait
{
    public function install($options = ['setup-wordpress' => false])
    {
        return $this->collectionBuilder()
            ->addTask($this->installTask($options['setup-wordpress']));
    }

    protected function installTask($setup_wordpress = false)
    {
        $task = $this->collectionBuilder()
            ->addTask($this->build());

        $dir = \Globalis\WP\Cubi\trailingslashit(\RoboFile::ROOT);

        if (!file_exists($dir . \RoboFile::PATH_FILE_CONFIG_LOCAL)) {
            $task->addCode(function () use ($dir) {
                copy($dir . \RoboFile::PATH_FILE_CONFIG_LOCAL_SAMPLE, $dir . \RoboFile::PATH_FILE_CONFIG_LOCAL);
            });
        }

        if ($options['setup-wordpress']) {
            // @TODO Wait for WP Tasks
            $task->addCode(function () {
                $this->wpInit();
            });
        } else {
            // @TODO Wait for WP Tasks
            $task->addCode(function () {
                $this->wpGenerateSaltKeys();
            });
        }

        foreach ([\RoboFile::PATH_DIRECTORY_LOG, \RoboFile::PATH_DIRECTORY_MEDIA] as $writable) {
            $task->addCode(function () use ($dir) {
                if (is_dir($dir . $writable)) {
                    @chmod($dir . $writable, 0777);
                }
            });
        }

        if (!is_dir(\RoboFile::ROOT . '/.git/')) {
            // @TODO Wait for Git Tasks
            $task->addCode(function () {
                $this->gitInit());
            });
        }

        return $task->onCompletionCode(function () {
            $this->io()->success('Installation is complete. Access site admin at ' . $this->wpUrl() . '/wp-admin/');
        });
    }
}
