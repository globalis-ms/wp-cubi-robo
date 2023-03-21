<?php

namespace Globalis\WP\Cubi\Robo;

use Globalis\Robo\Core\Command;

trait DeployTrait
{
    public function deploy($environment, $gitRevision, $options = ['ignore-assets' => false, 'ignore-composer' => false])
    {
        if (!isset($options['ignore-assets'])) {
            $options['ignore-assets'] = false;
        }

        if (!isset($options['ignore-composer'])) {
            $options['ignore-composer'] = false;
        }

        $this->io()->title('Deploy version ' . $gitRevision . ' to ' . $environment);

        if (!file_exists($this->fileVarsLocal($environment)) || \RoboFile::CONFIRM_CONFIG_BEFORE_DEPLOY) {
            $this->io()->text('You must answer a few questions about the remote environment:');
            $this->configure($environment, ['only-missing' => false]);
        } else {
            $this->configure($environment, ['only-missing' => true]);
        }

        $config         = $this->getConfig($environment);
        $collection     = $this->collectionBuilder();
        $buildDirectory = $collection->tmpDir();

        $this->gitExtractArchive($gitRevision, $buildDirectory);

        $this->build($environment, $buildDirectory, $options['ignore-assets'], $options['ignore-composer']);

        $this->deployWriteState(self::trailingslashit($buildDirectory) . 'deploy', $gitRevision);

        // 1. Dry Run
        $this->rsyncDeploy($buildDirectory, $config['REMOTE_HOSTNAME'], $config['REMOTE_USERNAME'], $config['REMOTE_PATH'], $config['REMOTE_PORT'], $options['ignore-assets'], true, true, $options['ignore-composer']);

        $deployed = false;

        if ($this->io()->confirm('Do you want to run ?', false)) {
            // 2. Run
            $this->rsyncDeploy($buildDirectory, $config['REMOTE_HOSTNAME'], $config['REMOTE_USERNAME'], $config['REMOTE_PATH'], $config['REMOTE_PORT'], $options['ignore-assets'], false, true, $options['ignore-composer']);

            $deployed = true;
        }

        $this->taskDeleteDir($buildDirectory)->run();

        // Run webhooks

        if ($deployed) {
            $site_url = $config['WEB_SCHEME'] . '://' . $config['WEB_DOMAIN'] . $config['WEB_PATH'];
            $site_url = self::trailingslashit($site_url);

            $this->io()->newLine();

            if ($this->io()->confirm('Reset opcache ?', true)) {
                $this->sendWebhookHttpRequest($site_url, 'reset-opcache');
            }

            $this->io()->newLine();

            if ($this->io()->confirm('Clear statcache ?', true)) {
                $this->sendWebhookHttpRequest($site_url, 'clear-statcache');
            }

            $this->io()->newLine();

            if ($this->io()->confirm('Flush rewrite rules ?', true)) {
                $this->sendWebhookHttpRequest($site_url, 'flush-rewrite-rules');
            }


            if ($this->io()->confirm('Clear wp-cubi transient cache ?', true)) {
                $this->sendWebhookHttpRequest($site_url, 'clear-wp-cubi-transient-cache');
            }

            // Ping home url to generate new cache

            $cmd = new Command('curl -S -s -o /dev/null');
            $cmd = $cmd->arg($site_url)
                ->getCommand();

            $this->taskExec($cmd)
                ->run();
        }

        return $deployed;
    }

    protected function rsyncDeploy($fromPath, $toHost, $toUser, $toPath, $remotePort, $ignoreAssets, $dryRun, $verbose = true, $ignore_composer = false)
    {
        $chmod       = 'Du=rwx,Dgo=rx,Fu=rw,Fgo=r';
        $excludeFrom = self::trailingslashit($fromPath) . '.rsyncignore';
        $delete      = true;
        $this->rsync(null, null, $fromPath, $toHost, $toUser, $toPath, $remotePort, $delete, $chmod, $excludeFrom, $ignoreAssets, $dryRun, $verbose, $ignore_composer);
    }

    protected function deployWriteState($directory, $gitRevision)
    {
        if ($gitCommit = $this->gitCommit($gitRevision)) {
            $this->taskWriteToFile(self::trailingslashit($directory) . 'git_commit')
                 ->line($gitCommit)
                 ->run();
        }

        if ($gitTag = $this->gitTag($gitRevision)) {
            $this->taskWriteToFile(self::trailingslashit($directory) . 'git_tag')
                 ->line($gitTag)
                 ->run();
        }

        $this->taskWriteToFile(self::trailingslashit($directory) . 'time')
             ->line(date('Y-m-d H:i:s'))
             ->run();
    }

    public function deploySetup($environment)
    {
        $this->io()->title('Setup remote environment: ' . $environment);
        $this->io()->text('You must answer a few questions about the remote environment:');

        $this->configure($environment);

        $config         = $this->getConfig($environment);
        $collection     = $this->collectionBuilder();
        $buildDirectory = $collection->tmpDir();

        $this->taskFilesystemStack()
             ->mkdir(self::trailingslashit($buildDirectory) . \RoboFile::PATH_DIRECTORY_CONFIG)
             ->mkdir(self::trailingslashit($buildDirectory) . \RoboFile::PATH_DIRECTORY_WEB)
             ->mkdir(self::trailingslashit($buildDirectory) . \RoboFile::PATH_DIRECTORY_MEDIA)
             ->mkdir(self::trailingslashit($buildDirectory) . \RoboFile::PATH_DIRECTORY_LOG)
             ->run();

        $this->buildConfig($environment, $buildDirectory);
        $this->wpGenerateSaltKeys($buildDirectory);

        $delete       = true;
        $chmod        = 'Du=rwx,Dgo=rx,Fu=rw,Fgo=r';
        $excludeFrom  = false;
        $ignoreAssets = false;

        // 1. Dry Run
        $this->rsync(null, null, $buildDirectory, $config['REMOTE_HOSTNAME'], $config['REMOTE_USERNAME'], $config['REMOTE_PATH'], $config['REMOTE_PORT'], $delete, $chmod, $excludeFrom, $ignoreAssets, true);

        $created = false;

        if ($this->io()->confirm('Do you want to run ?', false)) {
            // 2. Run
            $this->rsync(null, null, $buildDirectory, $config['REMOTE_HOSTNAME'], $config['REMOTE_USERNAME'], $config['REMOTE_PATH'], $config['REMOTE_PORT'], $delete, $chmod, $excludeFrom, $ignoreAssets, false);
            $created = true;
        }

        $this->taskDeleteDir($buildDirectory)->run();

        if ($created) {
            $this->io()->newLine();
            $this->io()->success('Remote environment was created.');
            $this->io()->note('To complete environment installation, following steps are required:');
            $this->io()->text('1. Upload your WordPress database.');
            $this->io()->text('2. Upload your media directory (see command media:push).');
            $this->io()->text('3. Ensure WordPress can write in ' . \RoboFile::PATH_DIRECTORY_MEDIA . ' and ' . \RoboFile::PATH_DIRECTORY_LOG . '.');
            $this->io()->text('4. Deploy application (see command deploy).');
        }
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
        $localPath  = self::trailingslashit(\RoboFile::ROOT) . \RoboFile::PATH_DIRECTORY_MEDIA;
        $remotePath = self::trailingslashit($config['REMOTE_PATH']) . \RoboFile::PATH_DIRECTORY_MEDIA;

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
        $this->rsync($fromHost, $fromUser, $fromPath, $toHost, $toUser, $toPath, $remotePort, $delete, $chmod, $excludeFrom, $ignoreAssets, $dryRun);
    }

    protected function rsync($fromHost, $fromUser, $fromPath, $toHost, $toUser, $toPath, $remotePort, $delete, $chmod, $excludeFrom, $ignoreAssets, $dryRun, $verbose = true, $ignore_composer = false)
    {
        $cmd = $this->taskRsync()
            ->fromHost($fromHost)
            ->fromUser($fromUser)
            ->fromPath(self::trailingslashit($fromPath))
            ->toHost($toHost)
            ->toUser($toUser)
            ->toPath(self::trailingslashit($toPath))
            ->option('rsh', 'ssh -p ' . $remotePort)
            ->recursive()
            ->checksum()
            ->compress()
            ->excludeVcs()
            ->option('copy-links')
            ->stats();

        if ($verbose) {
            $cmd->verbose();
            $cmd->itemizeChanges();
            $cmd->progress();
        } else {
            $cmd->option('quiet');
        }

        if ($ignoreAssets) {
            foreach (\RoboFile::PATH_FILES_BUILD_ASSETS as $assetPath) {
                $cmd->exclude($assetPath);
            }
        }

        if ($ignore_composer) {
            foreach (\RoboFile::PATH_VENDORS as $vendorPath) {
                $cmd->exclude($vendorPath);
            }
        }

        if (false !== $excludeFrom && file_exists($excludeFrom)) {
            $cmd->excludeFrom($excludeFrom);
        } else {
            $cmd->exclude('.gitkeep');
        }

        if (true === $delete) {
            $cmd->delete();
        }

        if (false !== $chmod) {
            $cmd->option('perms');
            $cmd->option('chmod', $chmod);
        }

        if (true === $dryRun) {
            $cmd->dryRun();
        }

        $cmd->run();
    }

    protected function sendWebhookHttpRequest($site_url, $webhook)
    {
        $url = self::trailingslashit($site_url);
        $url .= "?wp-cubi-webhooks-run=" . $webhook;
        $url .= "&wp-cubi-webhooks-secret=" . \RoboFile::WP_CUBI_WEBHOOKS_SECRET;

        $cmd = new Command('curl');
        $cmd = $cmd->arg($url)
            ->getCommand();

        $this->taskExec($cmd)
            ->run();
    }
}
