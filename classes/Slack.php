<?php
namespace Grav\Plugin\Mde;

use Grav\Common\Filesystem\Folder;
use Grav\Common\Grav;
use Grav\Common\Page\Page;
use Grav\Plugin\Email\Utils as EmailUtils;
use Grav\Plugin\MdePlugin;
use GuzzleHttp\Client as Guzzle;
use Maknz\Slack\Client;
use RuntimeException;

class Slack
{
    /* @var Grav $grav */
    protected $grav;

    /**
     * @var string
     */
    protected $redirect;

    /**
     * @var int
     */
    protected $redirectCode;

    protected $uri;
    protected $post;
    protected $path;

    /* @var Client $slack */
    protected $slack;

    const API = '/slack/';
    const CHANNEL = '#test';
    const WEBHOOK = 'https://hooks.slack.com/services/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';

    public function init()
    {
        $this->grav = Grav::instance();
        $this->uri = $this->grav['uri'];
        $this->post = $this->uri->post();
        $this->path = $this->uri->path();

        $this->initializeSlack();
    }

    /**
     * Initialize Slack composer class
     */
    public function initializeSlack() {
        require_once dirname(__DIR__, 1) . '/vendor/autoload.php';

        $settings = [
            'channel'      => self::CHANNEL,
            'link_names'   => true,
            'icon'         => ':urbansquid:',
            'unfurl_links' => true,
        ];

        $this->slack = new Client(self::WEBHOOK, $settings);
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
        $method = str_replace(self::API, '', $this->path);

        if (!method_exists($this, $method)) {
            throw new RuntimeException($method, 404);
        }

        try {
            $success = call_user_func([$this, $method]);
        } catch (RuntimeException $e) {
            $messages->add($e->getMessage(), 'error');
            $this->grav['log']->error('plugin.mde: '. $e->getMessage());
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

    public function sendMessage($text, $blocks = null, $channel = null)
    {
        $messages = $this->grav['messages'];
        $message = $this->slack->createMessage();
        $message->setText($text);

        if ($channel) {
            $message->setChannel($channel);
        }

        try {
            $this->slack->sendMessage($message, $blocks);

            $result = [
                'type' => 'success',
                'message' => 'Successfully submitted. Please stop making further changes to this page.',
            ];
        } catch (RuntimeException $e) {
            $messages->add($e->getMessage(), 'error');
            $this->grav['log']->error('plugin.mde: '. $e->getMessage());

            $result = [
                'type' => 'danger',
                'message' => 'Submission unsuccessful please try again',
                'error' => json_encode($e)
            ];
        }

        $this->jsonResponse($result);
    }

    public function actionEndpoint()
    {
        $payload = $_POST['payload'];
        $data = json_decode($payload);

        $name = $data->user->name;
        $action = $data->actions{0}->action_id;

        $value = $data->actions{0}->value ? json_decode($data->actions{0}->value, true) : null;
        $lang = $value['data']['lang'] ?? null;
        $page = $value['data']['page'] ?? null;


        $blocks = (array) $data->message->blocks;
        $email = str_replace('mailto://', '', $blocks[2]->elements{2}->url);
        $section = $this->addSection("*Something went wrong*");

        switch ($action)
        {
            case 'approve_button':
                if ($this->actionApproveTranslation($lang, $page)) {
                    $section = $this->addSection("*Translation approved by @{$name}*");
                }
                break;

            case 'deny_button':
                $this->actionDenyTranslation($lang, $page, $name, $email);
                $section = $this->addSection("*Translation denied by @{$name}*");
                break;
        }

        $blocks[2] = $section;

        $result = [
            "replace_original" => true,
            "text" => 'test',
            "blocks" => $blocks
        ];

        $guzzle = new Guzzle;
        $guzzle->post($data->response_url, ['body' => json_encode($result)]);
        $this->jsonResponse($result);
    }

    public function jsonResponse($result, $code = 200, $replace = true)
    {
        header('Content-Type: application/json', $replace, $code);
        echo json_encode($result);
        exit();
    }

    public function actionApproveTranslation($lang, $route)
    {
        /** @var Page $page */
        $page = $this->grav['pages']->find($route);
        $template = $page->template();
        $extension = ".{$lang}.md";

        // Origin(live) Page
        $path = $page->path() . DS . $template . $extension;
        $origin = new Page;
        $origin->init(new \SplFileInfo($path), $extension);

        // Translated Page
        $translated_path = MdePlugin::DATADIR . DS . $route;
        $file_location = $this->grav['locator']->findResource($translated_path . DS . $template . $extension);
        $translated_file = new \SplFileInfo($file_location);
        $translated = new Page;
        $translated->init($translated_file, $extension);

        // Merge the translated strings into the live page
        $header = (array) $origin->header();
        $translated_header = (array) $translated->header();
        $merged_headers = array_merge($header, $translated_header);
        $origin->header($merged_headers);

        // Attempt to save the results
        try {
            $origin->save();
        } catch (\RuntimeException $e) {
            return false;
        }

        // Remove the translation file now that it has been translated
        $translated->file()->delete();

        // also remove the folder if it is empty
        if (empty(Folder::all($translated_path))) {
            Folder::delete($translated_path);
        }

        return true;
    }

    /**
     * This function sends an email to
     *
     * @param $lang
     * @param $page
     * @param $name
     * @param $to
     * @return bool
     */
    public function actionDenyTranslation($lang, $page, $name, $to)
    {
        $body = "Translation in {$lang} for {$page} denied by @{$name}";
        $sent = EmailUtils::sendEmail('[Denied] Translation Approval Request', $body, $to);

        if ($sent < 1) {
            throw new \RuntimeException($this->grav['language']->translate('PLUGIN_LOGIN.EMAIL_SENDING_FAILURE'));
        }

        return true;
    }

    /**
     * Allows you to add a markdown supported section formatted for Slack
     *
     * @param $text
     * @return array
     */
    public function addSection($text)
    {
        return [
            "type"=> "section",
            "fields"=> [
                [
                    "type"=> "mrkdwn",
                    "text"=> $text
                ],
            ]
        ];
    }
}
