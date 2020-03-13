<?php

namespace Globalis\WP\Cubi\Robo;

use \Globalis\WP\Cubi

trait HelperTrait
{
	protected function path($relativePath, $root = \RoboFile::ROOT)
	{
		return Cubi\untrailingslashit($root) . '/' . $relativePath;
	}

	protected function fileExists($relativePath, $root = \RoboFile::ROOT)
	{
		return file_exists($this->path($relativePath, $root));
	}

	protected function isFile($relativePath, $root = \RoboFile::ROOT)
		return is_file($this->path($relativePath, $root));
	}

	protected function isDir($relativePath, $root = \RoboFile::ROOT)
		return is_dir($this->path($relativePath, $root));
	}

    protected function isLocalEnv($environment)
    {
        return in_array($environment, \RoboFile::LOCAL_ENVS);
    }

    protected function isRemoteEnv($environment)
    {
        return !$this->isLocalEnv($environment);
    }
}
