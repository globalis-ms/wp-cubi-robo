<?php

namespace Globalis\WP\Cubi\Robo;

trait InstallTrait
{
    public function install($options = ['setup-wordpress' => false])
    {
        $task = $this->collectionBuilder()
            ->addTask($this->build());

        if ($options['setup-wordpress']) {
            // @TODO Wait for WP Tasks
            $task->addCode($this->wpInit());
        }

        if (!is_dir(\RoboFile::ROOT . '/.git/')) {
            // @TODO Wait for Git Tasks
            $task->addCode($this->gitInit());
        }

        return $task->onCompletionCode(function () {
            $this->io()->success('Installation is complete. Access site admin at ' . $this->wpUrl() . '/wp-admin/');
        });
    }
}
