<?php
namespace App\Listener;

use Tk\Event\Subscriber;
use Tk\Kernel\KernelEvents;
use Tk\Event\ControllerEvent;
use Tk\Event\GetResponseEvent;
use Tk\Event\AuthEvent;
use Tk\Auth\AuthEvents;

/**
 * Class StartupHandler
 *
 * @author Michael Mifsud <info@tropotek.com>
 * @link http://www.tropotek.com/
 * @license Copyright 2015 Michael Mifsud
 */
class AuthHandler implements Subscriber
{

    /**
     * do any auth init setup
     *
     * @param GetResponseEvent $event
     */
    public function onSystemInit(GetResponseEvent $event)
    {
        // if a user is in the session add them to the global config
        // Only the identity details should be in the auth session not the full user object, to save space and be secure.
        $config = \App\Config::getInstance();
        $auth = $config->getAuth();
        $user = null;                       // public user
        if ($auth->getIdentity()) {         // Check if user is logged in
            $user = \App\Db\UserMap::create()->findByUsername($auth->getIdentity());
            $config->setUser($user);
        }

        // Get page access permission from route params (see config/routes.php)
        $role = $event->getRequest()->getAttribute('role');

        // no role means page is publicly accessible
        if (!$role || empty($role)) return;
        if ($user) {
            if (!$user->hasRole($role)) {
                // Could redirect to a authentication error page.
                \Tk\Alert::addWarning('You do not have access to the requested page.');
                $user->getHomeUrl()->redirect();
            }
        } else {
            \Tk\Uri::create('/login.html')->redirect();
        }
    }

    /**
     * Check the user has access to this controller
     *
     * @param ControllerEvent $event
     */
    public function onControllerAccess(ControllerEvent $event)
    {
        /** @var \App\Controller\Iface $controller */
        $controller = $event->getController();

//        if ($controller instanceof \App\Controller\Iface) {
//            $config = \App\Config::getInstance();
//            /** @var \App\Db\User $user */
//            $user = $config->getUser();
//
//            // Get page access permission from route params (see config/routes.php)
//            $role = $event->getRequest()->getAttribute('role');
//
//            vd($role, $user->hasRole($role));
//            // Check the user has access to the controller in question
//            if (!$role || empty($role)) return;
//            // Check the user has access to the controller in question
//            if (empty($role)) return;
//            if (!$user) \Tk\Uri::create('/login.html')->redirect();
//            if ($user && !$user->hasRole($role)) {
//                vd($user, $role, $user->hasRole($role));
//                // Could redirect to a authentication error page.
//                \Tk\Alert::addWarning('You do not have access to the requested page.');
//                $user->getHomeUrl()->redirect();
//            }
//        }
    }


    /**
     * @param AuthEvent $event
     * @throws \Exception
     */
    public function onLogin(AuthEvent $event)
    {
        $config = \App\Config::getInstance();
        $result = null;
        $adapterList = $config->get('system.auth.adapters');
        foreach($adapterList as $name => $class) {
            $adapter = $config->getAuthAdapter($class, $event->all());
            if (!$adapter) continue;
            $result = $event->getAuth()->authenticate($adapter);
            $event->setResult($result);
            if ($result && $result->isValid()) {
                break;
            }
        }

        if (!$result) {
            throw new \Tk\Auth\Exception('Invalid username or password');
        }
        if (!$result->isValid()) {
            return;
        }
        
        $user = \App\Db\UserMap::create()->findByUsername($result->getIdentity());
        if (!$user) {
            throw new \Tk\Auth\Exception('User not found: Contact Your Administrator');
        }
        $config->setUser($user);
        $event->set('user', $user);
    }

    /**
     * @param AuthEvent $event
     * @throws \Exception
     */
    public function onLoginSuccess(AuthEvent $event)
    {
        /** @var \App\Db\User $user */
        $user = $event->get('user');
        if (!$user) {
            throw new \Tk\Exception('No user found.');
        }
        $user->lastLogin = \Tk\Date::create();
        $user->save();
        \Tk\Uri::create($user->getHomeUrl())->redirect();

    }

    /**
     * @param AuthEvent $event
     * @throws \Exception
     */
    public function onLogout(AuthEvent $event)
    {
        $event->getAuth()->clearIdentity();
    }


    /**
     * @param \Tk\Event\Event $event
     * @throws \Tk\Mail\Exception
     */
    public function onRegister(\Tk\Event\Event $event)
    {
        /** @var \App\Db\User $user */
        $user = $event->get('user');

        // on success email user confirmation
        $body = \Dom\Loader::loadFile($event->get('templatePath').'/xtpl/mail/account.registration.xtpl');
        $body->insertText('name', $user->name);
        $url = \Tk\Uri::create('/register.html')->set('h', $user->hash);
        $body->insertText('url', $url->toString());
        $body->setAttr('url', 'href', $url->toString());
        $subject = 'Account Registration Request.';

        $message = new \Tk\Mail\Message($body->toString(), $subject, $user->email, \App\Config::getInstance()->get('site.email'));
        \App\Config::getInstance()->getEmailGateway()->send($message);

    }

    /**
     * @param \Tk\Event\Event $event
     * @throws \Tk\Mail\Exception
     */
    public function onRegisterConfirm(\Tk\Event\Event $event)
    {
        /** @var \App\Db\User $user */
        $user = $event->get('user');

        // Send an email to confirm account active
        $body = \Dom\Loader::loadFile($event->get('templatePath').'/xtpl/mail/account.activated.xtpl');
        $body->insertText('name', $user->name);
        $url = \Tk\Uri::create('/login.html');
        $body->insertText('url', $url->toString());
        $body->setAttr('url', 'href', $url->toString());
        $subject = 'Account Registration Activation.';

        $message = new \Tk\Mail\Message($body->toString(), $subject, $user->email, \App\Config::getInstance()->get('site.email'));
        \App\Config::getInstance()->getEmailGateway()->send($message);

    }

    /**
     * @param \Tk\Event\Event $event
     * @throws \Tk\Mail\Exception
     */
    public function onRecover(\Tk\Event\Event $event)
    {
        /** @var \App\Db\User $user */
        $user = $event->get('user');
        $pass = $event->get('password');

        // Send an email to confirm account active
        $body = \Dom\Loader::loadFile($event->get('templatePath').'/xtpl/mail/account.recover.xtpl');
        $body->insertText('name', $user->name);
        $body->insertText('password', $pass);
        $url = \Tk\Uri::create('/login.html');
        $body->insertText('url', $url->toString());
        $body->setAttr('url', 'href', $url->toString());
        $subject = 'Account Password Recovery.';

        $message = new \Tk\Mail\Message($body->toString(), $subject, $user->email, \App\Config::getInstance()->get('site.email'));
        \App\Config::getInstance()->getEmailGateway()->send($message);

    }

    /**
     * Returns an array of event names this subscriber wants to listen to.
     *
     * The array keys are event names and the value can be:
     *
     *  * The method name to call (priority defaults to 0)
     *  * An array composed of the method name to call and the priority
     *  * An array of arrays composed of the method names to call and respective
     *    priorities, or 0 if unset
     *
     * For instance:
     *
     *  * array('eventName' => 'methodName')
     *  * array('eventName' => array('methodName', $priority))
     *  * array('eventName' => array(array('methodName1', $priority), array('methodName2'))
     *
     * @return array The event names to listen to
     *
     * @api
     */
    public static function getSubscribedEvents()
    {
        return array(
            KernelEvents::REQUEST => 'onSystemInit',
            KernelEvents::CONTROLLER => 'onControllerAccess',
            AuthEvents::LOGIN => 'onLogin',
            AuthEvents::LOGIN_SUCCESS => 'onLoginSuccess',
            AuthEvents::LOGOUT => 'onLogout',
            AuthEvents::REGISTER => 'onRegister',
            AuthEvents::REGISTER_CONFIRM => 'onRegisterConfirm',
            AuthEvents::RECOVER => 'onRecover'
        );
    }


}