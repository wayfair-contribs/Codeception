<?php

namespace Codeception;

use Codeception\Exception\ConfigurationException;
use Codeception\Util\Autoload;
use Codeception\Util\Template;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Yaml\Yaml;

class Configuration
{
    protected static $suites = [];

    /**
     * @var array Current configuration
     */
    protected static $config = null;

    /**
     * @var array environmental files configuration cache
     */
    protected static $envConfig = [];

    /**
     * @var string Directory containing main configuration file.
     * @see self::projectDir()
     */
    protected static $dir = null;

    /**
     * @var string Current project logs directory.
     */
    protected static $logDir = null;

    /**
     * @var string Current project data directory. This directory is used to hold
     * sql dumps and other things needed for current project tests.
     */
    protected static $dataDir = null;

    /**
     * @var string Directory with test support files like Actors, Helpers, PageObjects, etc
     */
    protected static $supportDir = null;

    /**
     * @var string Directory containing environment configuration files.
     */
    protected static $envsDir = null;

    /**
     * @var string Directory containing tests and suites of the current project.
     */
    protected static $testsDir = null;

    public static $lock = false;

    protected static $di;

    /**
     * @var array Default config
     */
    public static $defaultConfig = [
        'actor'      => 'Guy', // codeception 1.x compatibility
        'namespace'  => '',
        'include'    => [],
        'paths'      => [
        ],
        'modules'    => [],
        'extensions' => [
            'enabled'  => [],
            'config'   => [],
            'commands' => [],
        ],
        'reporters'  => [
            'xml'    => 'Codeception\PHPUnit\Log\JUnit',
            'html'   => 'Codeception\PHPUnit\ResultPrinter\HTML',
            'tap'    => 'PHPUnit_Util_Log_TAP',
            'json'   => 'PHPUnit_Util_Log_JSON',
            'report' => 'Codeception\PHPUnit\ResultPrinter\Report',
        ],
        'groups'     => [],
        'settings'   => [
            'colors'     => false,
            'bootstrap'  => false,
            'strict_xml' => false,
            'lint'       => true,
            'backup_globals' => true
        ],
        'coverage'   => [],
        'params'     => [],
        'gherkin'    => []
    ];

    public static $defaultSuiteSettings = [
        'class_name'  => 'NoGuy',
        'modules'     => [
            'enabled' => [],
            'config'  => [],
            'depends' => []
        ],
        'namespace'   => null,
        'path'        => '',
        'groups'      => [],
        'shuffle'     => false,
        'error_level' => 'E_ALL & ~E_STRICT & ~E_DEPRECATED',
    ];

    protected static $params;

    /**
     * Loads global config file which is `codeception.yml` by default.
     * When config is already loaded - returns it.
     *
     * @param null $configFile
     * @return array
     * @throws Exception\ConfigurationException
     */
    public static function config($configFile = null)
    {
        if (!$configFile && self::$config) {
            return self::$config;
        }

        if (self::$config && self::$lock) {
            return self::$config;
        }

        if ($configFile === null) {
            $configFile = getcwd() . DIRECTORY_SEPARATOR . 'codeception.yml';
        }

        if (is_dir($configFile)) {
            $configFile = $configFile . DIRECTORY_SEPARATOR . 'codeception.yml';
        }

        $dir = realpath(dirname($configFile));

        $configDistFile = $dir . DIRECTORY_SEPARATOR . 'codeception.dist.yml';

        if (!(file_exists($configDistFile) || file_exists($configFile))) {
            throw new ConfigurationException("Configuration file could not be found.\nRun `bootstrap` to initialize Codeception.", 404);
        }

        $config = self::mergeConfigs(self::$defaultConfig, self::getConfFromFile($configDistFile));
        $config = self::mergeConfigs($config, self::getConfFromFile($configFile));

        if ($config == self::$defaultConfig) {
            throw new ConfigurationException("Configuration file is invalid");
        }

        self::$dir = $dir;
        self::$config = $config;

        if (!isset($config['paths']['log'])) {
            throw new ConfigurationException('Log path is not defined by key "paths: log"');
        }

        self::$logDir = $config['paths']['log'];

        // fill up includes with wildcard expansions
        $config['include'] = self::expandWildcardedIncludes($config['include']);

        // config without tests, for inclusion of other configs
        if (count($config['include']) and !isset($config['paths']['tests'])) {
            return self::$config = $config;
        }

        if (!isset($config['paths']['tests'])) {
            throw new ConfigurationException(
                'Tests directory is not defined in Codeception config by key "paths: tests:"'
            );
        }

        if (!isset($config['paths']['data'])) {
            throw new ConfigurationException('Data path is not defined Codeception config by key "paths: data"');
        }

        // compatibility with 1.x, 2.0
        if (!isset($config['paths']['support']) and isset($config['paths']['helpers'])) {
            $config['paths']['support'] = $config['paths']['helpers'];
        }

        if (!isset($config['paths']['support'])) {
            throw new ConfigurationException('Helpers path is not defined by key "paths: support"');
        }

        self::$dataDir = $config['paths']['data'];
        self::$supportDir = $config['paths']['support'];
        self::$testsDir = $config['paths']['tests'];

        if (isset($config['paths']['envs'])) {
            self::$envsDir = $config['paths']['envs'];
        }

        Autoload::addNamespace(self::$config['namespace'], self::supportDir());
        self::prepareParams($config);
        self::loadBootstrap($config['settings']['bootstrap']);
        self::loadSuites();

        return $config;
    }

    protected static function loadBootstrap($bootstrap)
    {
        if (!$bootstrap) {
            return;
        }
        $bootstrap = self::$dir . DIRECTORY_SEPARATOR . self::$testsDir . DIRECTORY_SEPARATOR . $bootstrap;
        if (file_exists($bootstrap)) {
            include_once $bootstrap;
        }
    }

    protected static function loadSuites()
    {
        $suites = Finder::create()
            ->files()
            ->name('*.{suite,suite.dist}.yml')
            ->in(self::$dir . DIRECTORY_SEPARATOR . self::$testsDir)
            ->depth('< 1');
        self::$suites = [];

        /** @var SplFileInfo $suite */
        foreach ($suites as $suite) {
            preg_match('~(.*?)(\.suite|\.suite\.dist)\.yml~', $suite->getFilename(), $matches);
            self::$suites[$matches[1]] = $matches[1];
        }
    }

    /**
     * Returns suite configuration. Requires suite name and global config used (Configuration::config)
     *
     * @param string $suite
     * @param array $config
     * @return array
     * @throws \Exception
     */
    public static function suiteSettings($suite, $config)
    {
        // cut namespace name from suite name
        if ($suite != $config['namespace'] && substr($suite, 0, strlen($config['namespace'])) == $config['namespace']) {
            $suite = substr($suite, strlen($config['namespace']));
        }

        if (!in_array($suite, self::$suites)) {
            throw new ConfigurationException("Suite $suite was not loaded");
        }

        // load global config
        $globalConf = $config['settings'];
        foreach (['modules', 'coverage', 'namespace', 'groups', 'env', 'gherkin'] as $key) {
            if (isset($config[$key])) {
                $globalConf[$key] = $config[$key];
            }
        }
        $settings = self::mergeConfigs(self::$defaultSuiteSettings, $globalConf);

        // load suite config
        $settings = self::loadSuiteConfig($suite, $config['paths']['tests'], $settings);

        // load from environment configs
        if (isset($config['paths']['envs'])) {
            $envConf = self::loadEnvConfigs(self::$dir . DIRECTORY_SEPARATOR . $config['paths']['envs']);
            $settings = self::mergeConfigs($settings, $envConf);
        }

        $settings['path'] = self::$dir . DIRECTORY_SEPARATOR . $config['paths']['tests']
            . DIRECTORY_SEPARATOR . $suite . DIRECTORY_SEPARATOR;

        return $settings;
    }

    /**
     * Loads environments configuration from set directory
     *
     * @param string $path path to the directory
     * @return array
     */
    protected static function loadEnvConfigs($path)
    {
        if (isset(self::$envConfig[$path])) {
            return self::$envConfig[$path];
        }
        if (!is_dir($path)) {
            self::$envConfig[$path] = [];
            return self::$envConfig[$path];
        }

        $envFiles = Finder::create()
            ->files()
            ->name('*.yml')
            ->in($path)
            ->depth('< 2');

        $envConfig = [];
        /** @var SplFileInfo $envFile */
        foreach ($envFiles as $envFile) {
            $env = str_replace(['.dist.yml', '.yml'], '', $envFile->getFilename());
            $envConfig[$env] = [];
            $envPath = $path;
            if ($envFile->getRelativePath()) {
                $envPath .= DIRECTORY_SEPARATOR . $envFile->getRelativePath();
            }
            foreach (['.dist.yml', '.yml'] as $suffix) {
                $envConf = self::getConfFromFile($envPath . DIRECTORY_SEPARATOR . $env . $suffix, null);
                if ($envConf === null) {
                    continue;
                }
                $envConfig[$env] = self::mergeConfigs($envConfig[$env], $envConf);
            }
        }

        self::$envConfig[$path] = ['env' => $envConfig];
        return self::$envConfig[$path];
    }

    /**
     * Loads configuration from Yaml file or returns given value if the file doesn't exist
     *
     * @param string $filename filename
     * @param mixed $nonExistentValue value used if filename is not found
     * @return array
     */
    protected static function getConfFromFile($filename, $nonExistentValue = [])
    {
        if (file_exists($filename)) {
            $yaml = file_get_contents($filename);
            if (self::$params) {
                $template = new Template($yaml, '%', '%');
                $template->setVars(self::$params);
                $yaml = $template->produce();
            }
            return Yaml::parse($yaml);
        }
        return $nonExistentValue;
    }

    /**
     * Returns all possible suite configurations according environment rules.
     * Suite configurations will contain `current_environment` key which specifies what environment used.
     *
     * @param $suite
     * @return array
     */
    public static function suiteEnvironments($suite)
    {
        $settings = self::suiteSettings($suite, self::config());

        if (!isset($settings['env']) || !is_array($settings['env'])) {
            return [];
        }

        $environments = [];

        foreach ($settings['env'] as $env => $envConfig) {
            $environments[$env] = $envConfig ? self::mergeConfigs($settings, $envConfig) : $settings;
            $environments[$env]['current_environment'] = $env;
        }

        return $environments;
    }

    public static function suites()
    {
        return self::$suites;
    }

    /**
     * Return list of enabled modules according suite config.
     *
     * @param array $settings suite settings
     * @return array
     */
    public static function modules($settings)
    {
        return array_filter(
            array_map(
                function ($m) {
                    return is_array($m) ? key($m) : $m;
                }, $settings['modules']['enabled'], array_keys($settings['modules']['enabled']))
            , function ($m) use ($settings) {
                if (!isset($settings['modules']['disabled'])) {
                    return true;
                }
                return !in_array($m, $settings['modules']['disabled']);
            }
        );
    }

    public static function isExtensionEnabled($extensionName)
    {
        return isset(self::$config['extensions'])
        && isset(self::$config['extensions']['enabled'])
        && in_array($extensionName, self::$config['extensions']['enabled']);
    }

    /**
     * Returns current path to `_data` dir.
     * Use it to store database fixtures, sql dumps, or other files required by your tests.
     *
     * @return string
     */
    public static function dataDir()
    {
        return self::$dir . DIRECTORY_SEPARATOR . self::$dataDir . DIRECTORY_SEPARATOR;
    }

    /**
     * Return current path to `_helpers` dir.
     * Helpers are custom modules.
     *
     * @return string
     */
    public static function supportDir()
    {
        return self::$dir . DIRECTORY_SEPARATOR . self::$supportDir . DIRECTORY_SEPARATOR;
    }

    /**
     * Returns actual path to current `_output` dir.
     * Use it in Helpers or Groups to save result or temporary files.
     *
     * @return string
     * @throws Exception\ConfigurationException
     */
    public static function outputDir()
    {
        if (!self::$logDir) {
            throw new ConfigurationException("Path for output not specified. Please, set output path in global config");
        }

        $dir = self::$logDir . DIRECTORY_SEPARATOR;
        if (strcmp(self::$logDir[0], "/") !== 0) {
            $dir = self::$dir . DIRECTORY_SEPARATOR . $dir;
        }

        if (!is_writable($dir)) {
            @mkdir($dir);
            @chmod($dir, 0777);
        }

        if (!is_writable($dir)) {
            throw new ConfigurationException(
                "Path for output is not writable. Please, set appropriate access mode for output path."
            );
        }

        return $dir;
    }

    /**
     * Compatibility alias to `Configuration::logDir()`
     * @return string
     */
    public static function logDir()
    {
        return self::outputDir();
    }

    /**
     * Returns path to the root of your project.
     * Basically returns path to current `codeception.yml` loaded.
     * Use this method instead of `__DIR__`, `getcwd()` or anything else.
     * @return string
     */
    public static function projectDir()
    {
        return self::$dir . DIRECTORY_SEPARATOR;
    }

    /**
     * Returns path to tests directory
     *
     * @return string
     */
    public static function testsDir()
    {
        return self::$dir . DIRECTORY_SEPARATOR . self::$testsDir . DIRECTORY_SEPARATOR;
    }

    /**
     * Return current path to `_envs` dir.
     * Use it to store environment specific configuration.
     *
     * @return string
     */
    public static function envsDir()
    {
        if (!self::$envsDir) {
            return null;
        }
        return self::$dir . DIRECTORY_SEPARATOR . self::$envsDir . DIRECTORY_SEPARATOR;
    }

    /**
     * Is this a meta-configuration file that just points to other `codeception.yml`?
     * If so, it may have no tests by itself.
     *
     * @return bool
     */
    public static function isEmpty()
    {
        return !(bool)self::$testsDir;
    }

    /**
     * Adds parameters to config
     *
     * @param array $config
     * @return array
     */
    public static function append(array $config = [])
    {
        return self::$config = self::mergeConfigs(self::$config, $config);
    }

    public static function mergeConfigs($a1, $a2)
    {
        if (!is_array($a1) || !is_array($a2)) {
            return $a2;
        }

        $res = [];

        foreach ($a2 as $k2 => $v2) {
            if (!isset($a1[$k2])) { // if no such key
                $res[$k2] = $v2;
                unset($a1[$k2]);
                continue;
            }

            $res[$k2] = self::mergeConfigs($a1[$k2], $v2);
            unset($a1[$k2]);
        }

        foreach ($a1 as $k1 => $v1) { // only single elements here left
            $res[$k1] = $v1;
        }

        return $res;
    }

    /**
     * Loads config from *.dist.suite.yml and *.suite.yml
     *
     * @param $suite
     * @param $path
     * @param $settings
     * @return array
     */
    protected static function loadSuiteConfig($suite, $path, $settings)
    {
        $suiteDistConf = self::getConfFromFile(
            self::$dir . DIRECTORY_SEPARATOR . $path . DIRECTORY_SEPARATOR . "$suite.suite.dist.yml"
        );
        $suiteConf = self::getConfFromFile(
            self::$dir . DIRECTORY_SEPARATOR . $path . DIRECTORY_SEPARATOR . "$suite.suite.yml"
        );
        $settings = self::mergeConfigs($settings, $suiteDistConf);
        $settings = self::mergeConfigs($settings, $suiteConf);
        return $settings;
    }

    /**
     * Replaces wildcarded items in include array with real paths.
     *
     * @param $includes
     * @return array
     */
    protected static function expandWildcardedIncludes(array $includes)
    {
        if (empty($includes)) {
            return $includes;
        }
        $expandedIncludes = [];
        foreach ($includes as $include) {
            $expandedIncludes = array_merge($expandedIncludes, self::expandWildcardsFor($include));
        }
        return $expandedIncludes;
    }

    /**
     * Finds config files in given wildcarded include path.
     * Returns the expanded paths or the original if not a wildcard.
     *
     * @param $include
     * @return array
     * @throws ConfigurationException
     */
    protected static function expandWildcardsFor($include)
    {
        if (1 !== preg_match('/[\?\.\*]/', $include)) {
            return [$include,];
        }

        try {
            $configFiles = Finder::create()->files()
                ->name('/codeception(\.dist\.yml|\.yml)/')
                ->in(self::$dir . DIRECTORY_SEPARATOR . $include);
        } catch (\InvalidArgumentException $e) {
            throw new ConfigurationException(
                "Configuration file(s) could not be found in \"$include\"."
            );
        }

        $paths = [];
        foreach ($configFiles as $file) {
            $paths[] = codecept_relative_path($file->getPath());
        }

        return $paths;
    }

    private static function prepareParams($settings)
    {
        self::$params = [];

        foreach ($settings['params'] as $paramStorage) {
            if (is_array($paramStorage)) {
                static::$params = array_merge(self::$params, $paramStorage);
                continue;
            }

            // environment
            if ($paramStorage === 'env' || $paramStorage === 'environment') {
                static::$params = array_merge(self::$params, $_SERVER);
                continue;
            }

            $paramsFile = realpath(self::$dir . '/' . $paramStorage);
            if (!file_exists($paramsFile)) {
                throw new ConfigurationException("Params file $paramsFile not found");
            }

            // yaml parameters
            if (preg_match('~\.yml$~', $paramStorage)) {
                $params = Yaml::parse(file_get_contents($paramsFile));
                if (isset($params['parameters'])) { // Symfony style
                    $params = $params['parameters'];
                }
                static::$params = array_merge(self::$params, $params);
                continue;
            }

            // .env and ini files
            if (preg_match('~(\.ini$|\.env(\.|$))~', $paramStorage)) {
                $params = parse_ini_file($paramsFile);
                static::$params = array_merge(self::$params, $params);
                continue;
            }
            throw new ConfigurationException("Params can't be loaded from `$paramStorage`.");
        }
    }
}
