<?php

namespace Globalis\WP\Cubi\Robo;

use Globalis\Robo\Core\Command;

trait WordPressTrait
{
    private $saltKeysUrl     = 'https://api.wordpress.org/secret-key/1.1/salt/';
    private $wp_default_lang = 'en_US';

    protected static function wpCli()
    {
        return new Command(\RoboFile::PATH_FILE_WP_CLI_EXECUTABLE);
    }

    protected function wpInit()
    {
        $this->io()->section('WORDPRESS INSTALLATION');
        $this->wpGenerateSaltKeys();
        $this->io()->newLine();
        $this->wpInitConfigFile();
        $this->io()->newLine();
        $this->wpDbCreate();
        $this->wpCoreInstall();
        $this->wpMaybeInstallAcfPro();
        $this->wpLanguageInstall(null, ['activate' => true]);
        $this->wpUpdateTimezone();
        $this->wpClean();
        $this->wpActivatePlugins();

        $this->io()->success('WordPress is ready.');
    }

    protected function wpUrl()
    {
        $scheme = $this->getConfig('development', 'WEB_SCHEME');
        $domain = $this->getConfig('development', 'WEB_DOMAIN');
        $path   = $this->getConfig('development', 'WEB_PATH');
        return $scheme . '://' . $domain . $path . '/wp';
    }

    protected function wpGenerateSaltKeys($root = \RoboFile::ROOT)
    {
        $target = self::trailingslashit($root) . \RoboFile::PATH_FILE_CONFIG_SALT_KEYS;

        if (file_exists($target)) {
            if ($this->io()->confirm(sprintf('%s already exists. Do you want to override it ?', $target), false)) {
                $this->taskFilesystemStack()
                 ->remove($target)
                 ->run();
            } else {
                return;
            }
        }

        $salt_keys = @file_get_contents($this->saltKeysUrl, false, null);

        if (empty($salt_keys)) {
            throw new \Exception(sprintf('Couldn\'t fetch response from %s', $this->saltKeysUrl));
        }

        $this->taskWriteToFile($target)
             ->line('<?php')
             ->line('')
             ->line('// WORDPRESS SALT KEYS generated from: ' . $this->saltKeysUrl)
             ->line($salt_keys)
             ->run();
    }

    protected function wpInitConfigFile($startPlaceholder = '<##', $endPlaceholder = '##>')
    {
        $settings = [];
        $settings['DB_PREFIX'] = $this->io()->ask('Database prefix', 'cubi_');
        $settings['WP_CUBI_WEBHOOKS_SECRET'] = bin2hex(random_bytes(16));

        $this->taskReplacePlaceholders(self::trailingslashit(\RoboFile::ROOT) . \RoboFile::PATH_FILE_CONFIG_APPLICATION)
         ->from(array_keys($settings))
         ->to($settings)
         ->startDelimiter($startPlaceholder)
         ->endDelimiter($endPlaceholder)
         ->run();
    }

    protected function wpDbCreate()
    {
        $db_name = $this->getConfig('development', 'DB_NAME');

        if ($this->mysqlAvailable()) {
            self::wpCli()
                ->arg('db')
                ->arg('create')
                ->execute();
            $this->io()->success(sprintf('Database `%s` was successfully created.', $db_name));
        } else {
            $this->io()->confirm(sprintf('Could not find `mysql` binary. Please create database `%s` manually then press ENTER', $db_name));
        }
    }

    protected function mysqlAvailable()
    {
        // Check that mysql binary path used by https://github.com/wp-cli/db-command/blob/master/src/DB_Command.php is valid
        $cmd = new Command('/usr/bin/env mysql');
        return $cmd->option('--version')
        ->executeWithoutException()
        ->isSuccessful();
    }

    protected function wpCoreInstall()
    {
        $title    = $this->io()->ask('Site title');
        $username = $this->io()->ask('Admin username');
        $password = $this->io()->askHidden('Admin password');
        $email    = $this->io()->ask('Admin email', $this->getConfig('development', 'DEV_MAIL'));

        self::wpCli()
            ->arg('core')
            ->arg('install')
            ->option('title', $title, '=')
            ->option('admin_user', $username, '=')
            ->option('admin_password', $password, '=')
            ->option('admin_email', $email, '=')
            ->option('url', $this->wpUrl(), '=')
            ->option('skip-email')
            ->execute();

        $this->io()->success('WordPress core was successfully installed.');
    }

    public function wpLanguageInstall($language = null, $options = ['activate' => false])
    {
        if (!isset($language)) {
            $language = $this->io()->ask('WordPress language', $this->wp_default_lang);
        }
        $this->wpLanguageUpdate($language, ['activate' => $options['activate']]);
    }

    public function wpLanguageUpdate($language = null, $options = ['activate' => false])
    {
        if (!isset($language)) {
            $language = $this->wp_default_lang;

            $cmd = self::wpCli()
                ->arg('option')
                ->arg('get')
                ->arg('WPLANG');

            $process = $cmd->executeWithoutException();

            if ($process->isSuccessful()) {
                $language = rtrim($process->getOutput());
            }
        }

        $this->wpLanguageUpdateCore($language, $options['activate']);
        $this->wpLanguageUpdatePlugins($language);
        $this->wpLanguageUpdateThemes($language);
    }

    protected function wpLanguageUpdateCore($language, $activate)
    {
        $cmd = self::wpCli()
            ->arg('language')
            ->arg('core')
            ->arg('install')
            ->arg($language)
            ->execute();

        if ($activate) {
            $cmd = self::wpCli()
                ->arg('language')
                ->arg('core')
                ->arg('activate')
                ->arg($language)
                ->execute();
        }

        $cmd = self::wpCli()
            ->arg('language')
            ->arg('core')
            ->arg('update')
            ->execute();
    }

    protected function wpLanguageUpdatePlugins($language)
    {
        $cmd = self::wpCli()
            ->arg('plugin')
            ->arg('list')
            ->option('field', 'name', '=')
            ->option('status', 'active', '=');

        $process    = $cmd->execute();
        $pluginList = rtrim($process->getOutput());
        $plugins    = explode(PHP_EOL, $pluginList);

        $cmd = self::wpCli()
            ->arg('plugin')
            ->arg('list')
            ->option('field', 'name', '=')
            ->option('status', 'inactive', '=');

        $process    = $cmd->execute();
        $pluginList = rtrim($process->getOutput());
        $plugins    = array_merge($plugins, explode(PHP_EOL, $pluginList));

        foreach ($plugins as $plugin) {
            if (!empty($plugin)) {
                $cmd = self::wpCli()
                    ->arg('language')
                    ->arg('plugin')
                    ->arg('install')
                    ->arg($plugin)
                    ->arg($language)
                    ->executeWithoutException();
            }
        }

        $cmd = self::wpCli()
            ->arg('language')
            ->arg('plugin')
            ->arg('update')
            ->option('all')
            ->execute();
    }

    protected function wpLanguageUpdateThemes($language)
    {
        $cmd = self::wpCli()
            ->arg('theme')
            ->arg('list')
            ->option('field', 'name', '=');

        $process   = $cmd->execute();
        $themeList = rtrim($process->getOutput());
        $themes    = explode(PHP_EOL, $themeList);

        foreach ($themes as $theme) {
            if (!empty($theme)) {
                $cmd = self::wpCli()
                    ->arg('language')
                    ->arg('theme')
                    ->arg('install')
                    ->arg($theme)
                    ->arg($language)
                    ->executeWithoutException();
            }
        }

        $cmd = self::wpCli()
            ->arg('language')
            ->arg('theme')
            ->arg('update')
            ->option('all')
            ->execute();
    }

    public function wpUpdateTimezone()
    {
        $timezones = self::getTimeZones();

        $group     = $this->io()->choice('Wordpress Timezone (1/2)', array_keys($timezones));

        $timezone  = $this->io()->choice('Wordpress Timezone (2/2)', array_keys($timezones[$group]));

        $value     = $timezones[$group][$timezone];

        self::wpCli()
            ->arg('option')
            ->arg('update')
            ->arg('timezone_string')
            ->arg($value)
            ->execute();
    }

    private static function getTimeZones()
    {
        $groups = [];

        foreach (timezone_identifiers_list() as $timezone) {
            $parts   = explode('/', $timezone);
            $group   = $parts[0];
            $zone    = isset($parts[1]) ? $parts[1] : $parts[0];

            if (!isset($groups[$group])) {
                $groups[$group] = [];
            }

            $groups[$group][$zone] = $timezone;
        }

        return $groups;
    }

    protected function wpClean()
    {
        $this->taskDeleteDir(\RoboFile::ROOT . '/web/app/uploads')->run();
        $this->taskDeleteDir(\RoboFile::ROOT . '/web/app/upgrade')->run();

        self::wpCli()
            ->arg('option')
            ->arg('update')
            ->arg('blogdescription')
            ->execute();

        $cmd = self::wpCli()
            ->arg('post')
            ->arg('list')
            ->option('post_type=', 'any', '=')
            ->option('format', 'ids', '=')
            ->execute();

        $post_ids = $cmd->getOutput();

        if (!empty($post_ids)) {
            self::wpCli()
                ->arg('post')
                ->arg('delete')
                ->arg(explode(' ', $post_ids))
                ->option('force')
                ->option('quiet')
                ->execute();
        }

        self::wpCli()
            ->arg('option')
            ->arg('update')
            ->arg('sidebars_widgets')
            ->arg('{}')
            ->option('format=json')
            ->option('quiet')
            ->execute();

        self::wpCli()
            ->arg('option')
            ->arg('update')
            ->arg('widget_recent-posts')
            ->arg('{}')
            ->option('format=json')
            ->option('quiet')
            ->execute();

        self::wpCli()
            ->arg('option')
            ->arg('update')
            ->arg('widget_recent-comments')
            ->arg('{}')
            ->option('format=json')
            ->option('quiet')
            ->execute();
    }

    protected function wpActivatePlugins()
    {
        self::wpCli()
            ->arg('plugin')
            ->arg('activate')
            ->option('all')
            ->execute();

        self::wpCli()
            ->arg('cap')
            ->arg('add')
            ->arg('administrator')
            ->arg('view_query_monitor')
            ->execute();
    }

    protected function loadWpConfig()
    {
        $config_vars = \RoboFile::ROOT . '/' . \RoboFile::PATH_FILE_CONFIG_VARS;
        $config_application = \RoboFile::ROOT . '/' . \RoboFile::PATH_FILE_CONFIG_APPLICATION;
        $config_local = \RoboFile::ROOT . '/' . \RoboFile::PATH_FILE_CONFIG_LOCAL;

        defined('WP_CUBI_CONFIG') || define('WP_CUBI_CONFIG', require $config_vars);
        require_once $config_application;
        require_once $config_local;
    }

    protected function wpMaybeInstallAcfPro()
    {
        if ($this->io()->confirm('Do you want to install ACF PRO (paid license key required) ?', false)) {
            $this->wpInstallAcfPro();
        }
    }

    public function wpInstallAcfPro($options = ['username' => '', 'password' => ''])
    {
        $this->io()->info(
            "Installation of ACF PRO is made using connect.advancedcustomfields.com private Composer repository and require HTTP basic authentication.\n
The username is the ACF PRO license key, and the password is the site URL (including https:// or http://) that the license is registered for (not necessarily the site URL of this project).\n
See the official ACF PRO documentation for more information on https://www.advancedcustomfields.com/resources/installing-acf-pro-with-composer/"
        );

        if (!empty($options['username'])) {
            $username = $options['username'];
        } else {
            $username = $this->io()->ask('connect.advancedcustomfields.com username (license key)');
        }

        if (!empty($options['password'])) {
            $password = $options['password'];
        } else {
            $password = $this->io()->ask('connect.advancedcustomfields.com password (license site URL)');
        }

        $this->taskComposer('remove')
            ->arg('wpackagist-plugin/advanced-custom-fields')
            ->run();

        $this->taskComposer('config')
            ->arg('http-basic.connect.advancedcustomfields.com')
            ->arg($username)
            ->arg($password)
            ->run();

        $this->taskComposer('config')
            ->arg('repositories.advancedcustomfields')
            ->arg('composer')
            ->arg('https://connect.advancedcustomfields.com')
            ->run();

        $task = $this->taskComposer('require')
            ->arg('wpengine/advanced-custom-fields-pro')
            ->run();

        $this->writeln("");

        if ($task->wasSuccessful()) {
            $this->io()->success('ACF PRO was successfully installed.');
        }
    }

    protected function wpShowAvailablePatch()
    {
        $cmd = new Command('composer');
        $process = $cmd->arg('outdated')
            ->arg('roots/wordpress')
            ->option('--patch-only')
            ->option('--strict')
            ->option('--format', 'json')
            ->executeWithoutException();

        $json = $process->getOutput();

        $data = json_decode($json, true);

        if (!is_array($data)) {
            return false;
        }

        if (!isset($data['versions'])) {
            return false;
        }

        if (!isset($data['latest'])) {
            return false;
        }

        $currentVersion = current($data['versions']);
        $latestVersion = $data['latest'];

        if (version_compare($currentVersion, $latestVersion) >= 0) {
            return false;
        }

        return $latestVersion;
    }

    public function wpApplyAvailablePatch()
    {
        $version = $this->wpShowAvailablePatch();

        if (empty($version)) {
            $this->io()->info("There is no available patch for package roots/wordpress.");
            return;
        }

        $this->taskComposer('require')
            ->arg('roots/wordpress:~' . $version)
            ->option('--with-all-dependencies')
            ->run();

        $this->taskComposer('bump')
            ->arg('roots/wordpress')
            ->run();
    }
}
