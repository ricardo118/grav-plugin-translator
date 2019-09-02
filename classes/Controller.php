<?php

namespace Grav\Plugin\Translator;

use Grav\Common\Filesystem\Folder;
use Grav\Common\Grav;
use Grav\Common\Language\Language;
use Grav\Common\Language\LanguageCodes;
use Grav\Common\Page\Page;
use Grav\Common\Page\Pages;
use Grav\Common\Twig\Twig;
use Grav\Common\Uri;
use Grav\Common\User\DataUser\User;
use Grav\Common\Yaml;
use Grav\Plugin\Email\Email;
use Grav\Plugin\Email\Utils as EmailUtils;
use Grav\Plugin\TranslatorPlugin;
use RuntimeException;

class Controller
{
    /**
     * @var Grav
     */
    public $grav;

    /**
     * @var string
     */
    protected $redirect;

    /**
     * @var int
     */
    protected $redirectCode;

    /**
     * @var string
     */
    protected $method;
    protected $type;

    /**
     * @var array
     */
    public $post;
    public $params;

    /**
     * @var Uri
     */
    public $uri;


    public function initialize($method, $post, $params)
    {
        $this->grav = Grav::instance();
        $this->method = $method;
        $this->post = $post;
        $this->params = $params;
        $this->uri = $this->grav['uri'];
    }

    /**
     * Performs an action.
     * @throws RuntimeException
     */
    public function execute()
    {
        $messages = $this->grav['messages'];

        // Set redirect if available.
        if (isset($this->post['_redirect'])) {
            $redirect = $this->post['_redirect'];
            unset($this->post['_redirect']);
        }

        $success = false;
        $method = ucwords($this->method, '.');
        $method = str_replace('.', '', $method);
        $method = $this->type . $method;

        if (!method_exists($this, $method)) {
            throw new RuntimeException($method, 404);
        }

        try {
            $success = call_user_func([$this, $method]);
        } catch (RuntimeException $e) {
            $messages->add($e->getMessage(), 'error');
            $this->grav['log']->error('plugin.translator: '. $e->getMessage());
        }

        if (!$this->redirect && isset($redirect)) {
            $this->setRedirect($redirect, 303);
        }

        return $success;
    }

    /**
     * Redirects an action
     */
    public function redirect()
    {
        if ($this->redirect) {
            $this->grav->redirect($this->redirect, $this->redirectCode);
        }
    }

    /**
     * Set redirect.
     *
     * @param     $path
     * @param int $code
     */
    public function setRedirect($path, $code = 303)
    {
        $this->redirect = $path;
        $this->redirectCode = $code;
    }

    public function jsonResponse($result, $code = 200, $replace = true)
    {
        header('Content-Type: application/json', $replace, $code);
        echo json_encode($result);
        exit();
    }

    /**
     * Save a translation in a temporary location
     */
    public function translatorSave()
    {
        $header  = $this->post['header']  ?? null;
        $content = $this->post['content'] ?? null;

        /** @var Pages $pages */
        $pages = $this->grav['pages'];

        $save_dir  = 'user-data://';
        $path      = ltrim(str_replace(TranslatorPlugin::EDIT, '', $this->grav['uri']->path()), '/');
        $template  = $this->post['template'];
        $lang      = $this->params['lang'];
        $extension = ".{$lang}.md";
        $save_path = "{$save_dir}{$path}".DS."{$template}{$extension}";

        $file = $this->grav['locator']->findResource($save_path);
        try {
            if ($file) {
                $page = new Page();
                $page->init(new \SplFileInfo($save_path), $extension);
                $page->header((object)$header);
                $page->frontmatter(Yaml::dump((array)$page->header(), 20));
                $page->content($content);
                $page->save();
            } else {
                $page = new Page();
                $page->filePath($save_path);
                $pages->addPage($page);
                $page->header((array)$header);
                $page->content($content);
                $page->save();
            }
            $saved = true;

        } catch (\RuntimeException $e) {
            $saved = false;
        }

        if ($saved) {
            $result = [
                'type' => 'success',
                'message' => 'Save was successful.'
            ];
        } else {
            $result = [
              'type' => 'danger',
              'message' => 'Save was not successful. Please try again.'
            ];
        }

        header('Content-Type: application/json');
        echo json_encode($result);
        exit();
    }

    /**
     * Generates the approval request, either via email
     * or via slack depending on the configs
     */
    public function translatorRequestApproval()
    {
        // Generic Variables
        $base_url = $this->uri->base();
        $now = date("D, j F H:i:s");
        $lang_code = $this->params['lang'];
        $lang = LanguageCodes::getName($lang_code);
        $configs = $this->grav['config']->get('plugins.translator');
        $logo = reset($configs['style']['logo'])['path'];

        /** @var User $user */
        $user = $this->grav['user'];
        $user_name = $user->fullname;
        $user_email = $user->email;

        $page_url = str_replace(TranslatorPlugin::$base_route . TranslatorPlugin::EDIT,'', $this->uri->path());
        $preview_url = $base_url . DS . $lang_code . TranslatorPlugin::$base_route . TranslatorPlugin::PREVIEW . $page_url;

        // Slack Related Configs
        $slack_configs = $configs['slack'];
        $useSlack = $slack_configs['enabled'];

        if ($useSlack) {
            $this->requestApprovalViaSlack($slack_configs, $now, $lang_code, $lang, $page_url, $preview_url, $user_name, $user_email);
        } else {

            // Email Configs
            $to = $configs['email'] ?? null;
            $emailendpoint = $base_url.TranslatorPlugin::$base_route.TranslatorPlugin::API.Api::EMAILENDPOINT;
            $queries = http_build_query([
                "lang"    => $lang_code,
                "name"    => 'an Admin.',
                "email"   => $user_email,
                "page"    => $page_url,
                "preview" => $preview_url
            ]);

            // Variables to send to email twig template
            $vars = [
                'color'       => TranslatorPlugin::$color,
                'lang'        => $lang,
                'preview_url' => $preview_url,
                'now'         => $now,
                'name'        => $user_name,
                'email'       => $user_email,
                'logo'        => $base_url.DS.$logo,
                'approve_url' => $emailendpoint."?{$queries}&action=approve_button",
                'deny_url'    => $emailendpoint."?{$queries}&action=deny_button",
            ];

            $this->requestApprovalViaEmail($to, $vars);
        }
    }

    /**
     * @param array $configs
     * @param $now
     * @param $lang_code
     * @param $lang
     * @param $url
     * @param $preview_url
     * @param $user_name
     * @param $user_email
     */
    private function requestApprovalViaSlack(array $configs, $now, $lang_code, $lang, $url, $preview_url, $user_name, $user_email)
    {
        require_once dirname(__DIR__, 1) . '/classes/Slack.php';
        $slack = new Slack($configs);
        $slack->init();

        $data = json_encode([
            "data" => [
                "lang" =>"{$lang_code}",
                "page" => "{$url}"
            ]
        ]);

        $blocks = [
            Slack::addSection("@here You have a new request for approval:\n"),
            Slack::addMultiSection([
                "*Language:*\n{$lang}",
                "*Submitted:*\n{$now}",
                "*Page:*\n<{$preview_url}|Preview Translation>",
                "*User:*\n{$user_name}"
            ]),
            [
                "type"=> "actions",
                "elements"=> [
                    Slack::addAction('approve_button', 'Approve', 'primary', $data),
                    Slack::addAction('deny_button', 'Deny', 'danger', $data, null, true),
                    Slack::addAction('email_button', 'Email', null, null, "mailto://{$user_email}"),
                ]
            ],
            ["type" => "divider"]
        ];

        $slack->sendMessage('New translation submitted!', $blocks);
    }

    /**
     * Uses email plugin to send an email to the defined email
     * or the default email plugin `to`
     *
     * @param $to
     * @param $vars
     */
    private function requestApprovalViaEmail($to, $vars)
    {
        /** @var Twig $twig */
        $twig = $this->grav['twig']->init();

        /** @var Email $twig */
        $email = $this->grav['Email'];

        $subject = "You have a new request for approval";
        $body = $twig->processTemplate('partials/email.html.twig', $vars);

        $params = [
            'body' => $body,
            'from_name' => 'Translator Plugin',
            'subject' => $subject
        ];

        if ($to) {
            $params['to'] = $to;
        }

        $message = $email->buildMessage($params);
        $sent = $email->send($message);

        if ($sent < 1) {
            $result = [
                'type' => 'danger',
                'message' => 'Submission unsuccessful please try again'
            ];
        } else {
            $result = [
                'type' => 'success',
                'message' => 'Successfully submitted. Please stop making further changes to this page.'
            ];
        }

        $this->jsonResponse($result);
    }

    /**
     * This function sends an email to
     *
     * @param $lang
     * @param $page
     * @param $name
     * @param $to
     * @return array
     */
    public function denyTranslation($lang, $page, $name, $to)
    {
        $body = "Translation in {$lang} denied by {$name}<br><a href='{$page}'>Preview Page</a>";
        $sent = EmailUtils::sendEmail('[Denied] Translation Approval Request', $body, $to);

        if ($sent < 1) {
            $result = [
                'type' => 'danger',
                'message' => 'Submission unsuccessful please try again'
            ];
        } else {
            $result = [
                'type' => 'success',
                'message' => 'Successfully submitted. The user has been notified via email of the denial.'
            ];
        }

        return $result;
    }

    public function approveTranslation($lang, $route, $name, $email)
    {
        /** @var Pages $page */
        $pages = $this->grav['pages'];

        /** @var Page $page */
        $page = $pages->find($route);
        $template = $page->template();
        $extension = ".{$lang}.md";

        // Origin(live) Page
        $path = $page->path() . DS . $template . $extension;
        if (file_exists($path)) {
            $origin = new Page;
            $origin->init(new \SplFileInfo($path), $extension);
        } else {
            $origin = new Page();
            $origin->filePath($path);
            $pages->addPage($origin);
            $origin->header((array)$page->header());
            $origin->content($page->rawMarkdown());
            $origin->save();
        }

        // Translated Page
        $translated_path = TranslatorPlugin::SAVE_LOCATION . DS . $route;
        $file_location = $this->grav['locator']->findResource($translated_path . DS . $template . $extension);
        $translated_file = new \SplFileInfo($file_location);
        $translated = new Page;
        $translated->init($translated_file, $extension);

        // Merge the translated strings into the live page
        $header = (array) $origin->header();
        $translated_header = (array) $translated->header();
        $merged_headers = array_merge($header, $translated_header);
        $origin->header($merged_headers);

        if (!empty($translated->rawMarkdown())) {
            $origin->content($translated->rawMarkdown());
        }

        // Attempt to save the results
        try {
            $origin->save();
        } catch (\RuntimeException $e) {
            return $result = [
                'type' => 'error',
                'message' => 'Failed to approve page. Please try again or contact an Admin.'
            ];
        }

        // Remove the translation file now that it has been translated
        $translated->file()->delete();

        // also remove the folder if it is empty
        if (empty(Folder::all($translated_path))) {
            Folder::delete($translated_path);
        }

        $link = $this->uri->base().$route;
        $lang_name = LanguageCodes::getName($lang);
        $body = "Translation in {$lang_name} approved by {$name}<br><a href='{$link}'>View Page</a>";
        $sent = EmailUtils::sendEmail('[APPROVED] Translation Approval Request', $body, $email);

        if ($sent < 1) {
            $result = [
                'type' => 'danger',
                'message' => 'Submission unsuccessful please try again'
            ];
        } else {
            $result = [
                'type' => 'success',
                'message' => 'Successfully submitted. The user has been notified via email of the denial.'
            ];
        }

        return $result;
    }
}
