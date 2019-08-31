<?php

namespace Grav\Plugin\Mde;

use Grav\Common\Grav;
use Grav\Common\Language\LanguageCodes;
use Grav\Common\Page\Page;
use Grav\Common\Page\Pages;
use Grav\Common\User\DataUser\User;
use Grav\Common\Yaml;
use Grav\Plugin\MdePlugin;
use Maknz\Slack\Attachment;
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
    public $uri;


    public function initialize(Grav $grav, $type, $method, $post, $params)
    {
        $this->grav = $grav;
        $this->type = $type;
        $this->method = $method;
        $this->post = $post;
        $this->params = $params;
        $this->uri = $grav['uri'];
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

    /**
     * Save a translation in a temporary location
     */
    public function taskTranslateSave()
    {
        $post = $this->post;
        $header = $post['header'];
        /** @var Pages $pages */
        $pages = $this->grav['pages'];

        $data_dir = 'user-data://';
        $path = ltrim($this->grav['uri']->path(), '/');

        $locator = $this->grav['locator'];
        $file = $locator->findResource($data_dir . $path . '/advertorial.de.md');

        $language = $this->params['lang'];
        $extension = ".{$language}.md";
        $filepath = $data_dir . $path . DS . 'advertorial' . $extension;

        if (file_exists($file)) {
            $page = new Page();
            $page->init(new \SplFileInfo($filepath), $extension);
            $page->header((object)$header);
            $page->frontmatter(Yaml::dump((array)$page->header(), 20));
            $page->save();
        } else {
            $page = new Page();
            $page->filePath($filepath);
            $pages->addPage($page);
            $page->header((array) $header);
            $page->save();
        }

        $saved = true;

        if ($saved) {
            $result = [
                'type' => 'success',
                'message' => 'Save was successful.',
                'fp' => $filepath,
                'ext' => $extension
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

    public function taskTranslateApproval()
    {
        require_once dirname(__DIR__, 1) . '/classes/Slack.php';

        $slack = new Slack();
        $slack->init();

        $now = date("D, j F H:i:s");
        $lang_code = $this->params['lang'];
        $lang = LanguageCodes::getName($lang_code);

        // User Info
        /** @var User $user */
        $user = $this->grav['user'];
        $name = $user->fullname;
        $email = $user->email;

        // URL Info
        $url_base = $this->uri->base();
        $url = str_replace(MdePlugin::TRANSLATE,'', $this->uri->path());
        $preview_url = $url_base . DS . $lang_code . MdePlugin::PREVIEW . $url;


        $data = json_encode([
            "data" => [
                "lang" =>"{$lang_code}",
                "page" => "{$url}"
            ]
        ]);
        $blocks = [
            [
                "type"=> "section",
                "text"=> [
                    "type"=> "mrkdwn",
                    "text"=> "@here You have a new request for approval:\n" //*<fakeLink.toEmployeeProfile.com|Fred Enriquez - New device request>*
                ]
            ],
            [
                "type"=> "section",
                "fields"=> [
                    [
                        "type"=> "mrkdwn",
                        "text"=> "*Language:*\n{$lang}"
                    ],
                    [
                        "type"=> "mrkdwn",
                        "text"=> "*Submitted:*\n{$now}"
                    ],
                    [
                        "type"=> "mrkdwn",
                        "text"=> "*Product:*\n<{$preview_url}|Preview Translation>"
                    ],
                    [
                        "type"=> "mrkdwn",
                        "text"=> "*User:*\n{$name}"
                    ]
                ]
            ],
            [
                "type"=> "actions",
                "elements"=> [
                    [
                        "type"=> "button",
                        "action_id" => "approve_button",
                        "text"=> [
                            "type"=> "plain_text",
                            "emoji"=> true,
                            "text"=> "Approve"
                        ],
                        "style"=> "primary",
                        "value"=> $data,
                    ],
                    [
                        "type"=> "button",
                        "action_id" => "deny_button",
                        "text"=> [
                            "type"=> "plain_text",
                            "emoji"=> true,
                            "text"=> "Deny"
                        ],
                        "style"=> "danger",
                        "value"=> $data,
                        "confirm" => [
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
                        ],
                    ],
                    [
                        "type"=> "button",
                        "action_id" => "email_button",
                        "text"=> [
                            "type"=> "plain_text",
                            "text"=> "Email"
                        ],
                        "url"=> "mailto://{$email}"
                    ]
                ]
            ],
            [
                "type" => "divider"
            ]
        ];
        $slack->sendMessage('New translation submitted!', $blocks);
    }
}
