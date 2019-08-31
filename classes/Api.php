<?php
namespace Grav\Plugin\Mde;

use Grav\Common\Grav;
use Grav\Common\Page\Page;
use RuntimeException;

class Api
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

    const API = '/v1/mde/';

    public function init()
    {
        $this->grav = Grav::instance();
        $this->uri = $this->grav['uri'];
        $this->post = $this->uri->post();
        $this->path = $this->uri->path();
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

    public function getchildren()
    {
        $route = $this->post['route'];
        $pages = $this->grav['pages'];
        $result = [];

        /** @var Page $child */
        foreach ($pages->find($route)->children() as $child) {
            $result[$child->route()] = ucfirst($child->template()) . ' (' . $child->slug() . ')';
        }

        header('Content-Type: application/json');
        echo json_encode($result);
        exit();
    }
}
