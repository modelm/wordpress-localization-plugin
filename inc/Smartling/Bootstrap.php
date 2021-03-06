<?php

namespace Smartling;

use Exception;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Smartling\Base\ExportedAPI;
use Smartling\ContentTypes\AutoDiscover\PostTypes;
use Smartling\ContentTypes\AutoDiscover\Taxonomies;
use Smartling\ContentTypes\ContentTypeNavigationMenu;
use Smartling\ContentTypes\ContentTypeNavigationMenuItem;
use Smartling\ContentTypes\ContentTypeWidget;
use Smartling\ContentTypes\CustomPostType;
use Smartling\ContentTypes\CustomTaxonomyType;
use Smartling\Exception\SmartlingBootException;
use Smartling\Extensions\Acf\AcfDynamicSupport;
use Smartling\Helpers\DiagnosticsHelper;
use Smartling\Helpers\MetaFieldProcessor\CustomFieldFilterHandler;
use Smartling\Helpers\SchedulerHelper;
use Smartling\Helpers\SimpleStorageHelper;
use Smartling\Helpers\SiteHelper;
use Smartling\Helpers\SmartlingUserCapabilities;
use Smartling\MonologWrapper\MonologWrapper;
use Smartling\Settings\SettingsManager;
use Smartling\WP\WPInstallableInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Yaml\Parser;

/**
 * Class Bootstrap
 * @package Smartling
 */
class Bootstrap
{

    use DebugTrait;
    use DITrait;

    public static $pluginVersion = 'undefined';

    const SELF_CHECK_IDENTIFIER = 'smartling_static_check_disabled';

    const DISABLE_LOGGING = 'smartling_disable_logging';

    const DISABLE_ACF_DB_LOOKUP = 'smartling_disable_db_lookup';

    const DISABLE_ACF = 'smartling_disable_acf';

    const SMARTLING_CUSTOM_LOG_FILE = 'smartling_log_file';

    const SMARTLING_CUSTOM_PAGE_SIZE = 'smartling_ui_page_size';

    const LOGGING_CUSTOMIZATION = 'smartling_logging_customization';

    public function __construct()
    {
        ignore_user_abort(true);
        set_time_limit(0);

        $scheduleHelper = new SchedulerHelper();
        add_filter('cron_schedules', [$scheduleHelper, 'extendWpCron']);
    }

    public static function getHttpHostName()
    {
        $url = network_site_url();
        $parts = parse_url($url);

        return $parts['host'];
    }

    /**
     * @var LoggerInterface|Logger
     */
    private static $loggerInstance = null;

    /**
     * @return LoggerInterface
     * @throws SmartlingBootException
     */
    public static function getLogger()
    {
        /** @noinspection ExceptionsAnnotatingAndHandlingInspection */
        $object = MonologWrapper::getLogger(get_called_class());

        if ($object instanceof LoggerInterface) {
            return $object;
        } else {
            $message = 'Something went wrong with initialization of DI Container and logger cannot be retrieved.';
            throw new SmartlingBootException($message);
        }
    }

    public static function getCurrentVersion()
    {
        return static::getContainer()->getParameter('plugin.version');
    }


    private static function setCoreParameters(ContainerBuilder $container)
    {
        // plugin dir (to use in config file)
        $container->setParameter('plugin.dir', SMARTLING_PLUGIN_DIR);
        $container->setParameter('plugin.upload', SMARTLING_PLUGIN_DIR . DIRECTORY_SEPARATOR . 'upload');

        $pluginUrl = '';

        if (function_exists('plugin_dir_url')) {
            $pluginUrl = plugin_dir_url(SMARTLING_PLUGIN_DIR . DIRECTORY_SEPARATOR . '..');
        }

        $container->setParameter('plugin.url', $pluginUrl);
    }

    public function activate()
    {
        $hooks = $this->fromContainer('hooks.installable', true);
        foreach ($hooks as $hook) {
            $object = $this->fromContainer($hook);
            if ($object instanceof WPInstallableInterface) {
                $object->activate();
            }
        }
    }

    public function deactivate()
    {
        $hooks = $this->fromContainer('hooks.installable', true);
        foreach ($hooks as $hook) {
            $object = $this->fromContainer($hook);
            if ($object instanceof WPInstallableInterface) {
                $object->deactivate();
            }
        }
    }

    public static function uninstall()
    {
        $hooks = static::getContainer()->getParameter('hooks.installable');
        foreach ($hooks as $hook) {
            $object = static::getContainer()->get($hook);
            if ($object instanceof WPInstallableInterface) {
                $object->uninstall();
            }
        }
    }

    public function registerHooks()
    {
        /**
         * @var StartupRegisterManager $manager
         */
        $manager = $this->fromContainer('manager.register');

        $manager->registerServices();
    }

    /**
     * The initial entry point tor plugins_loaded hook
     */
    public function load()
    {
        register_shutdown_function([$this, 'shutdownHandler']);

        static::getContainer()->setParameter('plugin.version', static::$pluginVersion);

        //always try to migrate db
        try {
            $this->fromContainer('site.db')->activate();
        } catch (\Exception $e) {
            static::getLogger()->error(vsprintf('Migration attempt finished with error: %s', [$e->getMessage()]));
        }

        try {
                $this->test();
                $this->initializeContentTypes();
                $this->registerHooks();
                $this->run();

        } catch (Exception $e) {
            $message = "Unhandled exception caught. Disabling plugin.\n";
            $message .= "Message: '" . $e->getMessage() . "'\n";
            $message .= "Location: '" . $e->getFile() . ':' . $e->getLine() . "'\n";
            $message .= "Trace: " . $e->getTraceAsString() . "\n";
            static::getLogger()->emergency($message);
            DiagnosticsHelper::addDiagnosticsMessage($message, true);
        }

        static::getContainer()->get('extension.loader')->runExtensions();
    }


    /**
     * Add smartling capabilities to 'administrator' role by default
     */
    private function initRoles()
    {
        $role = get_role('administrator');

        if ($role instanceof \WP_Role) {
            foreach (SmartlingUserCapabilities::$CAPABILITY as $capability) {
                $role->add_cap($capability, true);
            }
        } else {
            $siteHelper = static::getContainer()->get('site.helper');
            /**
             * @var SiteHelper $siteHelper
             */
            $msg = vsprintf('\'administrator\' role doesn\'t exists in site id=%s', [$siteHelper->getCurrentBlogId()]);
            static::getLogger()->warning($msg);
        }
    }

    public function testMultilingualPressPlugin()
    {
        $mlPluginsStatuses = [
            'multilingual-press-pro' => false,
        ];

        $found = false;

        if (class_exists('Mlp_Load_Controller', false)) {
            $mlPluginsStatuses['multilingual-press-pro'] = true;
            $found = true;
        }

        if (false === $found) {
            add_action('admin_init', function () {
                DiagnosticsHelper::addDiagnosticsMessage('Recommended plugin <strong>Multilingual Press</strong> not found. Please install and activate it.', false);
            });
        }

        static::getContainer()->setParameter('multilang_plugins', $mlPluginsStatuses);
    }

    public function updateGlobalExpertSettings () {
        $data =& $_POST['params'];

        $staticCheckDisabled = (int)$data['staticCheckDisabled'];
        $disableLogging = (int)$data['disableLogging'];
        $logPath = $data['loggingPath'];
        $disableACFDBLookup = (int)$data['disableDBLookup'];
        $defaultPageSize = Bootstrap::getPageSize(true);
        $rawPageSize = (int)$data['pageSize'];
        $pageSize = $rawPageSize < 1 ? $defaultPageSize : $rawPageSize;

        $parser = new Parser();
        $loggingCustomization = null;
        try {
            $loggingCustomization = $parser->parse($data['loggingCustomization'], true);
        } catch (\Exception $e) {
            Bootstrap::getLogger()
                     ->warning(vsprintf('Failed parsing new value: "%s"', [var_export($data['loggingCustomization'], true)]));
        }

        $disableACF = (int)$data['disableACF'];

        if (is_array($loggingCustomization)) {
            SimpleStorageHelper::set(Bootstrap::LOGGING_CUSTOMIZATION, $loggingCustomization);
        } else {
            Bootstrap::getLogger()->warning(var_export($loggingCustomization, true));
        }

        SimpleStorageHelper::set(Bootstrap::SELF_CHECK_IDENTIFIER, $staticCheckDisabled);
        SimpleStorageHelper::set(Bootstrap::DISABLE_LOGGING, $disableLogging);
        SimpleStorageHelper::set(static::DISABLE_ACF_DB_LOOKUP, $disableACFDBLookup);
        SimpleStorageHelper::set(Bootstrap::DISABLE_ACF, $disableACF);

        if (0 === $disableACF) {
            SimpleStorageHelper::drop(static::DISABLE_ACF);
        }

        if (0 == $disableACFDBLookup) {
            SimpleStorageHelper::drop(static::DISABLE_ACF_DB_LOOKUP);
        }

        if ($pageSize === $defaultPageSize) {
            SimpleStorageHelper::drop(static::SMARTLING_CUSTOM_PAGE_SIZE);
        } else {
            SimpleStorageHelper::set(static::SMARTLING_CUSTOM_PAGE_SIZE, $pageSize);
        }

        if ($logPath === Bootstrap::getLogFileName(false, true)) {
            SimpleStorageHelper::drop(static::SMARTLING_CUSTOM_LOG_FILE);
        } else {
            SimpleStorageHelper::set(static::SMARTLING_CUSTOM_LOG_FILE, $logPath);
        }

        wp_send_json($data);
    }

    /**
     * Tests if current Wordpress Configuration can work with Smartling Plugin
     * @return mixed
     */
    protected function test()
    {
        $this->testMultilingualPressPlugin();
        $this->testThirdPartyPluginsRequirements();

        $phpExtensions = [
            'curl',
            'mbstring',
        ];

        foreach ($phpExtensions as $ext) {
            $this->testPhpExtension($ext);
        }

        $this->testPluginSetup();
        $this->testMinimalWordpressVersion();
        $this->testTimeLimit();

        if (current_user_can(SmartlingUserCapabilities::SMARTLING_CAPABILITY_WIDGET_CAP)) {
            add_action('wp_ajax_' . 'smartling_expert_global_settings_update', [$this, 'updateGlobalExpertSettings']);
        }

        if (0 === (int)SimpleStorageHelper::get(static::SELF_CHECK_IDENTIFIER, 0)) {
            $this->testCronSetup();
            $this->testUpdates();
        }

        add_action('admin_notices', ['Smartling\Helpers\UiMessageHelper', 'displayMessages']);
    }

    protected function testTimeLimit($recommended = 300)
    {
        $timeLimit = ini_get('max_execution_time');

        if (0 !== (int)$timeLimit && $recommended >= $timeLimit) {
            $mainMessage = vsprintf('<strong>Smartling-connector</strong> configuration is not optimal.<br /><strong>max_execution_time</strong> is highly recommended to be set at least %s. Current value is %s', [$recommended,
                                                                                                                                                                                                                     $timeLimit]);

            static::$loggerInstance->warning($mainMessage);

            DiagnosticsHelper::addDiagnosticsMessage($mainMessage, false);
        }
    }

    protected function testCronSetup()
    {
        if (!defined('DISABLE_WP_CRON') || true !== DISABLE_WP_CRON) {
            $logMessage = 'Cron doesn\'t seem to be configured.';
            static::getLogger()->warning($logMessage);
            if (current_user_can('manage_network_plugins')) {
                $mainMessage = vsprintf('<strong>Smartling-connector</strong> configuration is not optimal.<br />Warning! Wordpress cron installation is not configured properly. Please follow configuration steps described <a target="_blank" href="https://help.smartling.com/hc/en-us/articles/360008158133-Install-and-Configure">here</a>.', []);
                DiagnosticsHelper::addDiagnosticsMessage($mainMessage, false);
            }
        }
    }

    protected function testMinimalWordpressVersion()
    {
        $minVersion = '4.6';
        if (version_compare(get_bloginfo('version'), $minVersion, '<')) {
            $msg = vsprintf('Wordpress has to be at least version %s to run smartlnig connector plugin. Please upgrade Your Wordpress installation.', [$minVersion]);
            static::getLogger()->critical('Boot :: ' . $msg);
            DiagnosticsHelper::addDiagnosticsMessage($msg, true);
        }
    }

    protected function testThirdPartyPluginsRequirements()
    {
        /**
         * @var array $data
         */
        $data = static::getContainer()->getParameter('multilang_plugins');

        $blockWork = true;

        foreach ($data as $value) {
            // there is at least one plugin that can be used
            if (true === $value) {
                $blockWork = false;
                break;
            }
        }

        if (true === $blockWork) {
            // replace localization plugin proxy with dummy class

            Bootstrap::getContainer()->register('multilang.proxy', 'Smartling\DbAl\DummyLocalizationPlugin');

        } else {
            $data = SimpleStorageHelper::get('state_modules', false);
            $advTranslatorKey = 'class-Mlp_Advanced_Translator';
            if (is_array($data) && array_key_exists($advTranslatorKey, $data) && 'off' !== $data[$advTranslatorKey]) {
                $msg = '<strong>Advanced Translator</strong> feature of Multilingual Press plugin is currently turned on.<br/>
 Please turn it off to use Smartling-connector plugin. <br/> Use <a href="' . get_site_url() .
                       '/wp-admin/network/settings.php?page=mlp"><strong>this link</strong></a> to visit Multilingual Press network settings page.';
                static::getLogger()->critical('Boot :: ' . $msg);
                DiagnosticsHelper::addDiagnosticsMessage($msg, true);
            }

            add_filter('wpmu_new_blog', function () {
                // ignore basedOn value by setting it to 0
                $_POST['blog']['basedon'] = 0;
            }, 9);

            add_filter('mlp_after_new_blog_fields', function () {
                // remove basedOn select element from UI
                echo '<script>(function($){$($(\'#mlp-base-site-id\').parents(\'tr\')).remove();})(jQuery);</script>';
            }, 99);
        }
    }

    protected function testPhpExtension($extension)
    {
        if (!extension_loaded($extension)) {
            $mainMessage = $extension . ' php extension is required to run the plugin is not installed or enabled.';

            static::$loggerInstance->critical('Boot :: ' . $mainMessage);

            DiagnosticsHelper::addDiagnosticsMessage($mainMessage, true);
        }
    }

    protected function testUpdates()
    {
        $staticSlug = $this->fromContainer('plugin.name', true);
        $cur_version = static::$pluginVersion;
        $new_version = '0.0.0';

        $info = get_site_transient('update_plugins');
        if (is_object($info) && isset($info->response)) {
            $response = $info->response;
            if (is_array($response)) {
                foreach ($response as $definition) {
                    if ($staticSlug !== $definition->slug) {
                        continue;
                    }
                    $new_version = $definition->new_version;
                    break;
                }
            }
        } else {
            static::getLogger()->warning('No cached information found about updates. Requesting info...');
            if (!function_exists('plugins_api')) {
                require_once(ABSPATH . 'wp-admin/includes/plugin-install.php');
            }
            $args = ['slug' => $staticSlug, 'fields' => ['version' => true]];
            $response = plugins_api('plugin_information', $args);

            if (is_wp_error($response)) {
                static::getLogger()
                    ->error(vsprintf('Updates information request ended with error: %s', [$response->get_error_message()]));
            } else {
                $new_version = $response->version;
            }
        }

        if (version_compare($new_version, $cur_version, '>')) {
            $mainMessage = vsprintf(
                'A new version <strong>%s</strong> of Smartling Connector plugin is available for download. Current version is %s. Please update plugin <a href="%s">here</a>.',
                [
                    $new_version, $cur_version,
                    site_url('/wp-admin/network/plugins.php?s=smartling+connector&plugin_status=all'),
                ]);

            static::$loggerInstance->warning($mainMessage);
            DiagnosticsHelper::addDiagnosticsMessage($mainMessage, false);
        }
    }

    protected function testPluginSetup()
    {
        /**
         * @var SettingsManager $sm
         */
        $sm = static::getContainer()->get('manager.settings');

        $total = 0;
        $profiles = $sm->getEntities([], null, $total, true);

        if (0 === count($profiles)) {
            $mainMessage = 'No active smartling configuration profiles found. Please create at least one on '
                           .
                           '<a href="' . get_site_url() .
                           '/wp-admin/admin.php?page=smartling_configuration_profile_list">settings page</a>';

            static::getLogger()->critical('Boot :: ' . $mainMessage);

            DiagnosticsHelper::addDiagnosticsMessage($mainMessage, true);
        }
    }

    /**
     * @param ContainerBuilder $di
     */
    private function initializeBuildInContentTypes(ContainerBuilder $di)
    {
        ContentTypeWidget::register($di);

        ContentTypeNavigationMenuItem::register($di);
        ContentTypeNavigationMenu::register($di);

        $handlers = [
            'taxonomies' => (new Taxonomies($di)),
            'posts'      => (new PostTypes($di)),
        ];

        $action = defined('DOING_CRON') && true === DOING_CRON ? 'wp_loaded' : 'admin_init';

        if (1 === (int)SimpleStorageHelper::get(Bootstrap::DISABLE_ACF, 0)) {
            DiagnosticsHelper::addDiagnosticsMessage('Warning, ACF plugin support is <strong>disabled</strong>.');
        } else {
            add_action($action, function () {
                $postTypes = array_keys(get_post_types());
                if (in_array('acf', $postTypes, true)) {
                    $msg = 'Detected a free version of ACF plugin that is not supported anymore. Please upgrade to full version.';
                    DiagnosticsHelper::addDiagnosticsMessage($msg, false);
                    MonologWrapper::getLogger(get_class($this))->notice($msg);
                }
            });

            add_action($action, function () {

                /**
                 * Initializing ACF and ACF Option Pages support.
                 */
                (new AcfDynamicSupport(static::fromContainer('entity.helper')))->run();
            });
        }
        /**
         * Post types and taxonomies are registered on 'init' hook, but this code is executed on 'plugins_loaded' hook,
         * so we need to postpone dynamic handlers execution
         */
        add_action($action, function () use ($di) {

            // registering taxonomies first.
            $dynTermDefinitions = [];
            $dynTermDefinitions = apply_filters(ExportedAPI::FILTER_SMARTLING_REGISTER_CUSTOM_TAXONOMY, $dynTermDefinitions);
            foreach ($dynTermDefinitions as $dynTermDefinition) {
                CustomTaxonomyType::registerCustomType($di, $dynTermDefinition);
            }

            // then registering posts
            $externalDefinitions = [];
            $externalDefinitions = apply_filters(ExportedAPI::FILTER_SMARTLING_REGISTER_CUSTOM_POST_TYPE, $externalDefinitions);
            foreach ($externalDefinitions as $externalDefinition) {
                CustomPostType::registerCustomType($di, $externalDefinition);
            }

            // then registering filters
            $filters = [
                // categories may have parent
                [
                    'pattern'       => '^(parent)$',
                    'action'        => 'localize',
                    'serialization' => 'none',
                    'value'         => 'reference',
                    'type'          => 'category',
                ],
                // post-based content may have parent
                [
                    'pattern'       => '^(post_parent)$',
                    'action'        => 'localize',
                    'serialization' => 'none',
                    'value'         => 'reference',
                    'type'          => 'post',
                ],
                // featured images use _thumbnail_id meta key
                [
                    'pattern'       => '_thumbnail_id',
                    'action'        => 'localize',
                    'serialization' => 'none',
                    'value'         => 'reference',
                    'type'          => 'media',
                ],
            ];


            $filters = apply_filters(ExportedAPI::FILTER_SMARTLING_REGISTER_FIELD_FILTER, $filters);
            foreach ($filters as $filter) {
                try {
                    CustomFieldFilterHandler::registerFilter($di, $filter);
                } catch (\Exception $e) {
                    static::getLogger()->warning(
                        vsprintf(
                            'Error registering filter with message: \'%s\', params: \'%s\'',
                            [
                                $e->getMessage(),
                                var_export($filter, true),
                            ]
                        )
                    );
                }
            }
        }, 999);
    }

    public function initializeContentTypes()
    {
        $this->initializeBuildInContentTypes(static::getContainer());
        do_action(ExportedAPI::ACTION_SMARTLING_REGISTER_CONTENT_TYPE, static::getContainer());
    }

    public function run()
    {

        $this->initRoles();
    }
}
