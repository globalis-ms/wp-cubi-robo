<?php

namespace Globalis\WP\Cubi\Robo;

trait InstallTrait
{
    public function install($options = ['setup-wordpress' => false])
    {
        $this->build();

        if ($options['setup-wordpress']) {
            $this->wpInit();
        } elseif (!file_exists(self::trailingslashit(\RoboFile::ROOT) . \RoboFile::PATH_FILE_CONFIG_SALT_KEYS)) {
            $this->wpGenerateSaltKeys();
        }

        if (!is_dir(\RoboFile::ROOT . '/.git/')) {
            $this->gitInit();
        }

        $this->io()->success('Installation is complete. Access site admin at ' . $this->wpUrl() . '/wp-admin/');
    }
}
