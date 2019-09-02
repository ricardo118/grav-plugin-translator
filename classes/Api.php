<?php
namespace Grav\Plugin\Translator;

use Grav\Common\Grav;
use Grav\Common\Language\LanguageCodes;
use Grav\Common\Uri;
use Grav\Plugin\TranslatorPlugin;
use GuzzleHttp\Client;
use RuntimeException;

class Api
{
    /**
     *  @var Grav
     */
    protected $grav;

    /**
     *  @var Controller
     */
    protected $controller;

    /**
     * @var array
     */
    protected $configs;
    protected $post;

    /**
     * @var Uri
     */
    protected $uri;

    /**
     * @var string
     */
    protected $path;

    const SLACKENDPOINT = '/slackendpoint';
    const EMAILENDPOINT = '/emailendpoint';

    public function __construct($configs)
    {
        $this->configs = $configs;
    }

    public function init()
    {
        $this->grav = Grav::instance();
        $this->uri = $this->grav['uri'];
        $this->post = $this->uri->post();
        $this->path = $this->uri->path();
        $this->controller = new Controller();
        $this->controller->initialize('',$this->post,'');
    }

    /**
     * Performs an action by calling a method.
     * @throws RuntimeException
     */
    public function execute()
    {
        $messages = $this->grav['messages'];

        $success = false;
        $method = str_replace(TranslatorPlugin::$base_route.TranslatorPlugin::API.DS, '', $this->path);

        if (!method_exists($this, $method)) {
            throw new RuntimeException($method, 404);
        }

        try {
            $success = call_user_func([$this, $method]);
        } catch (RuntimeException $e) {
            $messages->add($e->getMessage(), 'error');
            $this->grav['log']->error('plugin.translator: '. $e->getMessage());
        }

        return $success;
    }

    /**
     * Json encodes and terminates execution. Used for ajax responses.
     *
     * @param $result
     * @param int $code
     * @param bool $replace
     */
    public function jsonResponse($result, $code = 200, $replace = true)
    {
        header('Content-Type: application/json', $replace, $code);
        echo json_encode($result);
        exit();
    }

    public function slackendpoint()
    {
        $payload = $_POST['payload'];
        $data = json_decode($payload);

        require_once dirname(__DIR__, 1) . '/classes/Slack.php';
        $slack_configs = $this->grav['config']->get('plugins.translator')['slack'];
        $slack = new Slack($slack_configs);
        $slack->init();

        $name = '@'.$data->user->name;
        $action = $data->actions{0}->action_id;

        $value = $data->actions{0}->value ? json_decode($data->actions{0}->value, true) : null;
        $lang = $value['data']['lang'] ?? null;
        $page = $value['data']['page'] ?? null;

        $blocks = (array) $data->message->blocks;
        $email = str_replace('mailto://', '', $blocks[2]->elements{2}->url);
        $section = Slack::addSection("*Processing...*");

        $blocks[2] = $section;

        $result = [
            "replace_original" => true,
            "text" => 'test',
            "blocks" => $blocks
        ];

        // we must respond to slack first to avoid warning icon (limit 3 sec response)
        $guzzle = new Client();
        $guzzle->post($data->response_url, ['body' => json_encode($result)]);

        switch ($action)
        {
            case 'approve_button':
                $this->controller->approveTranslation($lang, $page, $name, $email);
                $section = Slack::addSection("*Translation approved by {$name}*");
                break;

            case 'deny_button':
                $this->controller->denyTranslation($lang, $page, $name, $email);
                $section = Slack::addSection("*Translation denied by {$name}*");
                break;
        }
        $blocks[2] = $section;

        $result = [
            "replace_original" => true,
            "text" => 'test',
            "blocks" => $blocks
        ];

        $guzzle = new Client();
        $guzzle->post($data->response_url, ['body' => json_encode($result)]);
        $this->jsonResponse($result);
    }

    public function emailEndpoint()
    {
        $params = $this->uri->query(null, true);
        $action = $params['action'];

        $lang     = LanguageCodes::getName($params['lang']);
        $code     = $params['lang'];
        $name     = $params['name'];
        $page     = $params['page'];
        $email    = $params['email'];
        $preview  = $params['preview'];

        switch($action)
        {
            case 'approve_button':
                $result = $this->controller->approveTranslation($code, $page, $name, $email);

                break;

            case 'deny_button':
                $result = $this->controller->denyTranslation($lang, $preview, $name, $email);
                break;
            default:
                $result = [
                    'type' => 'Error',
                    'message' => 'Action failed, please try again.'
                ];
                break;
        }

        $this->jsonResponse($result);
    }
}
