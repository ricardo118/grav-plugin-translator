<?php
namespace Grav\Plugin\Translator;

use Grav\Common\Grav;
use Maknz\Slack\Client;
use RuntimeException;

class Slack
{
    /**
     *  @var Grav
     */
    protected $grav;

    /**
     * @var string
     */
    protected $redirect;

    /**
     * @var array
     */
    protected $configs;

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

        $this->initializeSlack();
    }

    /**
     * Initialize Slack composer class
     */
    public function initializeSlack() {
        require_once dirname(__DIR__, 1) . '/vendor/autoload.php';

        $settings = [
            'channel'      => $this->configs['channel'],
            'link_names'   => true,
            'icon'         => ':earth_africa:',
            'unfurl_links' => true,
        ];

        $this->slack = new Client($this->configs['webhook'], $settings);
    }

    /**
     * Sends a message to Slack
     *
     * @param $text
     * @param null $blocks
     * @param null $channel
     */
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
            $this->grav['log']->error('plugin.translator: '. $e->getMessage());

            $result = [
                'type' => 'danger',
                'message' => 'Submission unsuccessful please try again',
                'error' => json_encode($e)
            ];
        }

        $this->jsonResponse($result);
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

    /**
     * Allows you to add a markdown supported section formatted for Slack
     *
     * @param $text
     * @return array
     */
    public static function addSection($text)
    {
        return [
            "type"=> "section",
            "text"=> [
                "type"=> "mrkdwn",
                "text"=> $text
            ]
        ];
    }

    /**
     * Takes an array for strings, and generates multiple text (markdown enabled)
     * field sections formatted for Slack Blocks
     *
     * @param array $fields
     * @return array
     */
    public static function addMultiSection(array $fields)
    {
        $section = [
            "type"=> "section",
            "fields"=> []
        ];

        foreach ($fields as $text)
        {
            $field = [
                "type"=> "mrkdwn",
                "text"=> $text
            ];

            array_push($section['fields'], $field);
        }

        return $section;
    }

    /**
     * Creates a Slack formatted Action button
     *
     * @param $id
     * @param $text
     * @param null $style
     * @param null $data
     * @param null $url
     * @param bool $popup
     * @return array
     */
    public static function addAction($id, $text, $style = null, $data = null, $url = null, bool $popup = false)
    {
        $action = [
            "type"=> "button",
            "action_id" => $id,
            "text"=> [
                "type"=> "plain_text",
                "emoji"=> true,
                "text"=> $text
            ],
        ];

        if ($style) {
            $action['style'] = $style;
        }

        if ($data) {
            $action['value'] = $data;
        }

        if ($url) {
            $action['url'] = $url;
        }

        if ($popup) {
            $action['confirm'] = [
                "title"=> [
                    "type"=> "plain_text",
                    "text"=> "Are you sure?"
                ],
                "confirm"=> [
                    "type"=> "plain_text",
                    "text"=> "Deny"
                ],
                "deny"=> [
                    "type"=> "plain_text",
                    "text"=> "Cancel"
                ]
            ];
        }
        return $action;
    }
}
