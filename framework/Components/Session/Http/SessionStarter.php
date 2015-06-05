<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 * @copyright ©2009-2015
 */
namespace Spiral\Components\Session\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Spiral\Components\Http\Cookies\Cookie;
use Spiral\Components\Http\Cookies\CookieManager;
use Spiral\Components\Http\MiddlewareInterface;
use Spiral\Components\Session\SessionStore;
use Spiral\Core\Container;

class SessionStarter implements MiddlewareInterface
{
    /**
     * Cookie to store session ID in.
     */
    const COOKIE = 'session';

    /**
     * Container used to resolve session store.
     *
     * @var Container
     */
    protected $container = null;

    /**
     * Session store instance.
     *
     * @var SessionStore
     */
    protected $store = null;

    /**
     * Middleware constructing.
     *
     * @param Container $container
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Get session store instance.
     *
     * @return SessionStore
     */
    public function getStore()
    {
        if (!empty($this->store))
        {
            return $this->store;
        }

        return $this->store = SessionStore::getInstance($this->container);
    }

    /**
     * Handle request generate response. Middleware used to alter incoming Request and/or Response
     * generated by inner pipeline layers.
     *
     * @param ServerRequestInterface $request Server request instance.
     * @param \Closure               $next    Next middleware/target.
     * @return ResponseInterface
     */
    public function __invoke(ServerRequestInterface $request, \Closure $next = null)
    {
        $cookies = $request->getCookieParams();

        $outerID = null;
        if (isset($cookies[self::COOKIE]))
        {
            if ($this->getStore()->isStarted())
            {
                $outerID = $this->getStore()->getID();
            }

            //Mounting ID retrieved from cookies
            $this->store->setID($cookies[self::COOKIE]);
        }

        /**
         * @var ResponseInterface $response
         */
        $response = $next($request);

        if (empty($this->store) && is_object($this->container->getBinding(SessionStore::getAlias())))
        {
            //Store were started by itself
            $this->store = $this->container->get(SessionStore::getAlias());
        }

        if (!empty($this->store) && ($this->store->isStarted() || $this->store->isDestroyed()))
        {
            $response = $this->setCookie($request, $response, $this->store, $cookies);
        }

        //Restoring original session, not super efficient operation
        !empty($outerID) && $this->store->setID($outerID);

        return $response;
    }

    /**
     * Mount session id or remove session cookie.
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface      $response
     * @param SessionStore           $store
     * @param array                  $cookies
     * @return ResponseInterface
     */
    protected function setCookie(
        ServerRequestInterface $request,
        ResponseInterface $response,
        SessionStore $store,
        array $cookies
    )
    {
        $store->isStarted() && $store->commit();

        if (!isset($cookies[self::COOKIE]) || $cookies[self::COOKIE] != $store->getID())
        {
            if ($response instanceof ResponseInterface)
            {
                return $response->withAddedHeader(
                    'Set-Cookie',
                    Cookie::create(
                        self::COOKIE,
                        $store->getID(),
                        $store->getConfig()['lifetime'],
                        null,
                        $request->getAttribute('cookieDomain', null)
                    )->packHeader()
                );
            }
        }

        return $response;
    }
}