<?php
namespace Grav\Plugin;

use Grav\Common\Grav;
use Grav\Common\Language\Language;
use Grav\Common\Language\LanguageCodes;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Page\Page;
use Grav\Common\Page\Pages;
use Grav\Common\Plugin;
use Grav\Common\Twig\Twig;
use Grav\Common\Uri;
use Grav\Plugin\Translator\Api;
use Grav\Plugin\Translator\Controller;

/**
 * Class TranslatorPlugin
 * @package Grav\Plugin
 */
class TranslatorPlugin extends Plugin
{
    /** @var Uri $uri */
    private $uri;

    /** @var array */
    public $configs;

    /** @var array */
    public $paths;

    /** @var string */
    public $base;
    public $path;
    public $default_lang;

    /** @var string */
    public static $base_route;
    public static $color;

    public const API           = '/api';
    public const EDIT          = '/edit';
    public const PREVIEW       = '/preview';
    public const SAVE_LOCATION = 'user-data://translator';

    public static function getSubscribedEvents() : array
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0]
        ];
    }

    /**
    * Initialize the plugin
    */
    public function onPluginsInitialized() : void
    {
        $this->uri          = $this->grav['uri'];
        $this->configs      = $this->getConfigs();
        self::$base_route   = $this->configs['base_route'];
        $this->base         = ltrim(self::$base_route, '/');
        $this->path         = $this->uri->path();
        $this->paths        = $this->uri->paths();
        $this->default_lang = $this->grav['language']->getDefault();
        self::$color        = $this->configs['style']['color'];

        // before we do anything, make sure we are in the plugin pages
        if (empty($this->paths) || $this->paths[0] !== $this->base || $this->isAdmin()) {
           return;
        }

        $this->autoload();

        if (!$this->continuePlugin()) {
            return;
        }

        $this->enable([
            'onTwigTemplatePaths' => ['onTwigTemplatePaths', 1]
        ]);
    }

    /**
     * Load the required Classes
     */
    public function autoload() : void
    {
        require_once __DIR__ . '/classes/Controller.php';
        require_once __DIR__ . '/classes/Slack.php';
        require_once __DIR__ . '/classes/Api.php';
    }

    /**
     * Get Plugin configurations
     * @return array
     */
    public function getConfigs() : array
    {
        return $this->config->get('plugins.translator');
    }

    /**
     * Check what route we are in and which events to enable.
     * Returns false to stop processing the rest of the plugin.
     *
     * @return bool
     */
    public function continuePlugin() : bool
    {
        $route = $this->paths[1] ?? false;
        $route = '/' . $route;

        switch ($route)
        {
            case self::API:
                $this->enable([
                    'onPagesInitialized' => ['apiCall', 0]
                ]);
                return false;
                break;

            case self::PREVIEW:
                $this->enable([
                    'onPagesInitialized' => ['addPreviewPage', 0]
                ]);
                break;

            case self::EDIT:
                $this->enable([
                    'onPagesInitialized'           => ['addEditPage', 0],
                    'onTwigSiteVariables'          => ['addEditPageVariables', 0],
                    'onTask.translator.save'             => ['taskController', 0],
                    'onTask.translator.request.approval' => ['taskController', 0]
                ]);
                break;

            default:
                $this->enable([
                    'onPagesInitialized' => ['addTranslatorsPage', 0]
                ]);
                break;
        }

        return true;
    }

    /**
     * Initialize and execute the API
     */
    public function apiCall() : void
    {
        $api = new Api($this->configs);
        $api->init();
        $api->execute();
    }

    /**
     * Add templates directory to twig lookup paths.
     */
    public function onTwigTemplatePaths() : void
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }

    /**
     * This method checks for tasks and or actions in the route and initialized the task controller
     * class which will execute the necessary tasks.
     */
    public function taskController() : void
    {
        $params = $this->uri->params(null, true);
        $task = $params['task'];
        $post = $_POST;

        unset($params['task']);

        $controller = new Controller();
        $controller->initialize($task, $post, $params);
        $controller->execute();
        $controller->redirect();
    }


    /**
     * Function to be called in /user_register form as the available languages
     * for a translator to be assigned to.
     * Used in register.md form blueprints
     * How to use:
     * `type: select`
     * `data-options@: '\Grav\Plugin\TranslatorPlugin::getLanguages'`
     * returns en: English, de: German, etc
     *
     * @return array
     */
    public static function getLanguages() : array
    {
        $languages = Grav::instance()['language'];
        $options = [];

        foreach ($languages->getLanguages() as $language)
        {
            $options[$language] = LanguageCodes::getName($language);
        }

        return $options;
    }

    /**
     * Grab a specific language version of a page.
     * For example, takes an english page and returns a german page
     *
     * @param PageInterface $page
     * @param string $language
     *
     * @return bool|Page
     */
    public function getPageInLanguage(PageInterface $page, $language)
    {
        $name = $page->template();
        $language = $language ?? $this->default_lang;
        $extension = ".{$language}.md";
        $path = $page->path() . DS . $name . $extension;

        if (file_exists($path)) {
            $page = new Page();
            $page->init(new \SplFileInfo($path), $extension);
            return $page;
        }

        return null;
    }

    /**
     * Merge multiple page headers - used for fallback of variables
     *
     * @param Page $page
     * @param array $array
     *
     * @return array $merged_headers
     */
    public function mergeHeaders($page, $array = []) : array
    {
        $header = (array) $page->header();
        $merging_headers = [];

        foreach ($array as $p) {
            // merge all the headers into an array
            $merging_headers[] = (array)$p->header();
        }
        // add the final header (original page's) to the array
        $merging_headers[] = $header;

        return array_merge(...$merging_headers);
    }

    /**
     * This function reads the url to find the default language page
     * and dynamically creates the page for the url param language.
     *
     * Outputted Twig Variables:
     * default => default language page (english by default)
     * translatable => the url param {$lang} page (can be any language the logged in user can translate)
     * translatableLang => the url param {$lang}
     *
     */
    public function addEditPageVariables() : void
    {
        $live_page_route = str_replace(self::$base_route . self::EDIT,'', $this->path);

        /** @var Twig $twig */
        $twig = $this->grav['twig'];
        $locator = $this->grav['locator'];

        /** @var Pages $pages */
        $pages= $this->grav['pages'];
        $live_page = $pages->find($live_page_route);

        if (!$live_page) {
            throw new \RuntimeException('No live page exists');
        }

        $lang = $this->uri->param('lang');
        $extension = ".{$lang}.md";
        $translatablePage = $this->getPageInLanguage($live_page, $lang) ?? $live_page;

        // check if a Saved Page already exists and merge the headers.
        $save = $locator->findResource(self::SAVE_LOCATION . $live_page->route() . DS . $live_page->template() . $extension);
        if ($save) {
            $savedPage = new Page();
            $savedPage->init(new \SplFileInfo($save), $extension);

            // merge headers of live page and temp page
            $translatablePage->header($this->mergeHeaders($savedPage, [$translatablePage]));

            // replace live content with temp page context (if exists)
            if (!empty($savedPage->rawMarkdown())) {
                $translatablePage->content($savedPage->rawMarkdown());
            }

            $twig->twig_vars['hasSave'] = true;
        }

        $twig->twig_vars['default'] = $live_page;
        $twig->twig_vars['translatable'] = $translatablePage;
        $twig->twig_vars['translatableLang'] = $lang;
    }

    /**
     * Dynamically add the preview based off the live page (name, template, route),
     * using the header data from the WIP page.
     *
     * Route: {$previewing_lang}/self::$base_route/preview/{route to a page}
     */
    public function addPreviewPage() : void
    {
        $base = self::$base_route;
        $route = str_replace("{$base}/preview", '', $this->path);

        /** @var Pages $pages */
        $pages = $this->grav['pages'];
        $live_page = $pages->find($route);

        if (!$live_page) {
            throw new \RuntimeException('No live page exists');
        }

        $template = $live_page->template();
        $extension = str_replace($template, '', $live_page->name());

        // grab the WIP page header from data folder
        $save_dir = self::SAVE_LOCATION . $route . DS . $live_page->name();
        $file = $this->grav['locator']->findResource($save_dir);
        if ($file) {
            $wip_page = new Page;
            $wip_page->init(new \SplFileInfo($file), $extension);
            $wip_page->header();
            dump($this->mergeHeaders($wip_page, [$live_page, $this->getPageInLanguage($live_page, $this->default_lang)]));

            if (empty($wip_page->rawMarkdown())) {
                $wip_page->content($live_page->rawMarkdown());
            }

            $wip_page->media($live_page->media());
            $pages->addPage($wip_page, $this->path);
        } else {
            throw new \RuntimeException('Nothing to preview. No translated page saved yet. Please save before previewing.');
        }
    }

    /**
     * Dynamically add the edit page
     * Route: /self::$base_route/edit/{route to live page}
     * Params: /lang:{$lang}
     */
    public function addEditPage() : void
    {
        $user_langs = $this->grav['user']->get('translator') ?? [];
        $lang = $this->uri->param('lang');
        $isAllowed = in_array($lang, $user_langs, false) || in_array('super', $user_langs, false);

        // back to translators page if the logged in user cant access this language
        if (!$lang || !$user_langs || !$isAllowed) {
            $this->grav->redirect(self::$base_route);
        }

        $filename = 'edit.md';
        $this->addPage($this->path, $filename);
    }

    /**
     * Dynamically add the translators page
     * Route: /self::$base_route
     */
    public function addTranslatorsPage() : void
    {
        $filename = 'translators.md';
        $this->addPage(self::$base_route, $filename);
    }

    /**
     * Dynamically add a page and register the route. Used for simple pages
     *
     * @param $url
     * @param $filename
     * @param $template
     * @param $extension
     */
    protected function addPage($url, $filename, $template = null, $extension = null) : void
    {
        $pages = $this->grav['pages'];
        $page = $pages->dispatch($url);

        if (!$page) {
            $page = new Page;
            $page->init(new \SplFileInfo(__DIR__ . '/pages/' . $filename), $extension);
            $page->slug(basename($url));

            if ($template) {
                $page->template($template);
            }

            $pages->addPage($page, $url);
        }
    }
}
