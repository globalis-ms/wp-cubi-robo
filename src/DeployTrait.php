<?php

namespace Globalis\WP\Cubi\Robo;

trait DeployTrait
{
    public function deploy($environment, $gitRevision, $options = ['ignore-assets' => false])
    {
        $task = $this->collectionBuilder();
        $buildDirectory = $task->tmpDir();

        $task->addCode(function () use ($gitRevision, $environment) {
            $this->io()->title('Deploy version ' . $gitRevision . ' to ' . $environment);
            $this->io()->text('You must answer a few questions about the remote environment:');
        })
        ->addTask(
            $this->configure($environment, ['only-missing' => false])
        )
        ->addCode(function () use ($gitRevision, $buildDirectory) {
            $this->gitExtractArchive($gitRevision, $buildDirectory);
        })
        ->addTask(
            $this->build($environment, $buildDirectory, $options['ignore-assets'])
        )
        ->addTask(
            $this->deployWriteState(\Globalis\WP\Cubi\trailingslashit($buildDirectory) . 'deploy', $gitRevision)
        )
        // 1. Dry Run
        ->addTask(
            $this->rsyncDeploy(
                $buildDirectory,
                $this->getConfig('REMOTE_HOSTNAME', $environment),
                $this->getConfig('REMOTE_USERNAME', $environment),
                $this->getConfig('REMOTE_PATH', $environment),
                $this->getConfig('REMOTE_PORT', $environment),
                $options['ignore-assets'],
                true
            )
        )
        // 2. Run
        ->addCode(function () use ($buildDirectory, $opts) {
            if ($this->io()->confirm('Do you want to run ?', false)) {
                return $this->rsyncDeploy(
                    $buildDirectory,
                    $this->getConfig('REMOTE_HOSTNAME', $environment),
                    $this->getConfig('REMOTE_USERNAME', $environment),
                    $this->getConfig('REMOTE_PATH', $environment),
                    $this->getConfig('REMOTE_PORT', $environment),
                    $options['ignore-assets'],
                    false
                )->run();
            }
        });

        return $task;
    }

    protected function rsyncDeploy($fromPath, $toHost, $toUser, $toPath, $remotePort, $ignoreAssets, $dryRun, $verbose = true)
    {
        $chmod       = 'Du=rwx,Dgo=rx,Fu=rw,Fgo=r';
        $excludeFrom = \Globalis\WP\Cubi\trailingslashit($fromPath) . '.rsyncignore';
        $delete      = true;
        return $this->rsync(null, null, $fromPath, $toHost, $toUser, $toPath, $remotePort, $delete, $chmod, $excludeFrom, $ignoreAssets, $dryRun, $verbose);
    }

    protected function deployWriteState($directory, $gitRevision)
    {
        $tack = $this->collectionBuilder();

        if ($gitCommit = $this->gitCommit($gitRevision)) {
            $task->addTask(
                $this->taskWriteToFile(\Globalis\WP\Cubi\trailingslashit($directory) . 'git_commit')
                 ->line($gitCommit)
            );
        }

        if ($gitTag = $this->gitTag($gitRevision)) {
            $task->addTask(
                $this->taskWriteToFile(\Globalis\WP\Cubi\trailingslashit($directory) . 'git_tag')
                 ->line($gitTag)
            );
        }

        return $task->addTask(
            $this->taskWriteToFile(\Globalis\WP\Cubi\trailingslashit($directory) . 'time')
                ->line(date('Y-m-d H:i:s'))
        );
    }

    public function deploySetup($environment)
    {
        $task = $this->collectionBuilder();
        $buildDirectory = $task->tmpDir();

        $task->addCode(function () use ($gitRevision, $environment) {
            $this->io()->title('Setup remote environment: ' . $environment);
            $this->io()->text('You must answer a few questions about the remote environment:');
        })
        ->addTask(
            $this->configure($environment, ['only-missing' => false])
        )
        ->addTask(
            $this->taskFilesystemStack()
                ->mkdir(\Globalis\WP\Cubi\trailingslashit($buildDirectory) . \RoboFile::PATH_DIRECTORY_CONFIG)
                ->mkdir(\Globalis\WP\Cubi\trailingslashit($buildDirectory) . \RoboFile::PATH_DIRECTORY_WEB)
                ->mkdir(\Globalis\WP\Cubi\trailingslashit($buildDirectory) . \RoboFile::PATH_DIRECTORY_MEDIA)
                ->mkdir(\Globalis\WP\Cubi\trailingslashit($buildDirectory) . \RoboFile::PATH_DIRECTORY_LOG)
        )
        ->addTask(
            $this->buildConfig($environment, $buildDirectory)
        )
        ->addCode(function () use ($buildDirectory) {
            $source = \Globalis\WP\Cubi\trailingslashit(\RoboFile::ROOT) . \RoboFile::PATH_FILE_CONFIG_LOCAL_SAMPLE;
            $target = \Globalis\WP\Cubi\trailingslashit($buildDirectory) . \RoboFile::PATH_FILE_CONFIG_LOCAL;
            copy($source, $target);
        })
        ->addCode(function () use ($buildDirectory) {
            $this->wpGenerateSaltKeys($buildDirectory);
        })
        // 1. Dry Run
        ->addTask(
            $this->rsync(
                null,
                null,
                $buildDirectory,
                $this->getConfig($environment, 'REMOTE_HOSTNAME'),
                $this->getConfig($environment, 'REMOTE_USERNAME'),
                $this->getConfig($environment, 'REMOTE_PATH'),
                $this->getConfig($environment, 'REMOTE_PORT'),
                true,
                'Du=rwx,Dgo=rx,Fu=rw,Fgo=r',
                false,
                false,
                true
            )
        )
        // 2. Run
        ->addCode(function () use ($buildDirectory, $opts) {
            if ($this->io()->confirm('Do you want to run ?', false)) {
                return $this->collectionBuilder()
                ->addTask(
                    $this->rsync(
                        null,
                        null,
                        $buildDirectory,
                        $this->getConfig($environment, 'REMOTE_HOSTNAME'),
                        $this->getConfig($environment, 'REMOTE_USERNAME'),
                        $this->getConfig($environment, 'REMOTE_PATH'),
                        $this->getConfig($environment, 'REMOTE_PORT'),
                        true,
                        'Du=rwx,Dgo=rx,Fu=rw,Fgo=r',
                        false,
                        false,
                        false
                    )
                )
                ->completionCode(function () {
                    $this->io()->newLine();
                    $this->io()->success('Remote environment was created.');
                    $this->io()->note('To complete environment installation, following steps are required:');
                    $this->io()->text('1. Upload your WordPress database.');
                    $this->io()->text('2. Upload your media directory (see command media:push).');
                    $this->io()->text('3. Ensure WordPress can write in ' . \RoboFile::PATH_DIRECTORY_MEDIA . ' and ' . \RoboFile::PATH_DIRECTORY_LOG . '.');
                    $this->io()->text('4. Deploy application (see command deploy).');
                })
                ->run();
            }
        });

        return $task;
    }

    public function mediaDump($environment, $options = ['delete' => false])
    {
        $this->mediaSync($environment, 'dump', $options['delete']);
    }

    public function mediaPush($environment, $options = ['delete' => false])
    {
        $this->mediaSync($environment, 'push', $options['delete']);
    }

    protected function mediaSync($environment, $action, $delete)
    {
        $config     = $this->getConfig($environment);
        $localPath  = \Globalis\WP\Cubi\trailingslashit(\RoboFile::ROOT) . \RoboFile::PATH_DIRECTORY_MEDIA;
        $remotePath = \Globalis\WP\Cubi\trailingslashit($config['REMOTE_PATH']) . \RoboFile::PATH_DIRECTORY_MEDIA;

        if (!is_dir($localPath)) {
            mkdir($localPath, 0777);
        }

        switch ($action) {
            case 'dump':
                $fromHost = $config['REMOTE_HOSTNAME'];
                $fromUser = $config['REMOTE_USERNAME'];
                $fromPath = $remotePath;
                $toHost   = null;
                $toUser   = null;
                $toPath   = $localPath;
                break;
            case 'push':
                $fromHost = null;
                $fromUser = null;
                $fromPath = $localPath;
                $toHost   = $config['REMOTE_HOSTNAME'];
                $toUser   = $config['REMOTE_USERNAME'];
                $toPath   = $remotePath;
                break;
            default:
                return;
        }

        // 1. Dry Run
        $this->rsyncMedia($fromHost, $fromUser, $fromPath, $toHost, $toUser, $toPath, $config['REMOTE_PORT'], true, $delete);

        $deployed = false;

        if ($this->io()->confirm('Do you want to run ?', false)) {
            // 2. Run
            $this->rsyncMedia($fromHost, $fromUser, $fromPath, $toHost, $toUser, $toPath, $config['REMOTE_PORT'], false, $delete);

            $deployed = true;
        }

        return $deployed;
    }

    protected function rsyncMedia($fromHost, $fromUser, $fromPath, $toHost, $toUser, $toPath, $remotePort, $dryRun, $delete)
    {
        $chmod        = false;
        $excludeFrom  = false;
        $ignoreAssets = false;
        $this->rsync($fromHost, $fromUser, $fromPath, $toHost, $toUser, $toPath, $remotePort, $delete, $chmod, $excludeFrom, $ignoreAssets, $dryRun)
        // @TODO Temp patch
        ->run();
    }

    protected function rsync($fromHost, $fromUser, $fromPath, $toHost, $toUser, $toPath, $remotePort, $delete, $chmod, $excludeFrom, $ignoreAssets, $dryRun, $verbose = true)
    {
        $task = $this->taskRsync()
            ->fromHost($fromHost)
            ->fromUser($fromUser)
            ->fromPath(\Globalis\WP\Cubi\trailingslashit($fromPath))
            ->toHost($toHost)
            ->toUser($toUser)
            ->toPath(\Globalis\WP\Cubi\trailingslashit($toPath))
            ->option('rsh', 'ssh -p ' . $remotePort)
            ->recursive()
            ->checksum()
            ->compress()
            ->excludeVcs()
            ->option('copy-links')
            ->stats();

        if ($verbose) {
            $task->verbose();
                ->itemizeChanges();
                ->progress();
        } else {
            $task->option('quiet');
        }

        if ($ignoreAssets) {
            foreach (\RoboFile::PATH_FILES_BUILD_ASSETS as $assetPath) {
                $task->exclude($assetPath);
            }
        }

        if (false !== $excludeFrom && file_exists($excludeFrom)) {
            $task->excludeFrom($excludeFrom);
        } else {
            $task->exclude('.gitkeep');
        }

        if (true === $delete) {
            $task->delete();
        }

        if (false !== $chmod) {
            $task->option('perms');
            $task->option('chmod', $chmod);
        }

        if (true === $dryRun) {
            $task->dryRun();
        }

        return $task;
    }
}
