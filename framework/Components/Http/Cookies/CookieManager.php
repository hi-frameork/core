<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 * @copyright ©2009-2015
 */
namespace Spiral\Components\Http\Cookies;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Spiral\Components\Encrypter\DecryptionException;
use Spiral\Components\Encrypter\Encrypter;
use Spiral\Components\Encrypter\EncrypterException;
use Spiral\Components\Http\CsrfProtector;
use Spiral\Components\Http\MiddlewareInterface;
use Spiral\Components\Http\Response;
use Spiral\Core\Component;

class CookieManager extends Component implements MiddlewareInterface
{
    /**
     * Required traits.
     */
    use Component\SingletonTrait;

    /**
     * Declares to IoC that component instance should be treated as singleton.
     */
    const SINGLETON = 'cookies';

    /**
     * Cookie names should never be encrypted or decrypted.
     *
     * @var array
     */
    protected $exclude = array(
        CsrfProtector::COOKIE
    );

    /**
     * Encrypter component.
     *
     * @var Encrypter
     */
    protected $encrypter = null;

    /**
     * Cookies has to be send (specified via global scope).
     *
     * @var CookieInterface[]
     */
    protected $scheduled = array();

    /**
     * Set custom encrypter.
     *
     * @param Encrypter $encrypter
     */
    public function setEncrypter(Encrypter $encrypter)
    {
        $this->encrypter = $encrypter;
    }

    /**
     * Get encrypter instance. Lazy loading method.
     *
     * @return Encrypter
     */
    protected function getEncrypter()
    {
        if (!empty($this->encrypter))
        {
            return $this->encrypter;
        }

        return $this->encrypter = Encrypter::make();
    }

    /**
     * Handle request generate response. Middleware used to alter incoming Request and/or Response
     * generated by inner pipeline layers.
     *
     * @param ServerRequestInterface $request Server request instance.
     * @param \Closure               $next    Next middleware/target.
     * @param object|null            $context Pipeline context, can be HttpDispatcher, Route or module.
     * @return Response
     */
    public function __invoke(ServerRequestInterface $request, \Closure $next = null, $context = null)
    {
        $request = $this->decryptCookies($request);

        /**
         * @var Response $response
         */
        $response = $next($request);

        return $this->encryptCookies($response);
    }

    /**
     * Unpack incoming cookies and decrypt their content.
     *
     * @param ServerRequestInterface $request
     * @return ServerRequestInterface
     */
    protected function decryptCookies(ServerRequestInterface $request)
    {
        $altered = false;
        $cookies = $request->getCookieParams();
        foreach ($cookies as $name => $cookie)
        {
            if (in_array($name, $this->exclude))
            {
                continue;
            }

            $altered = true;

            try
            {
                $cookies[$name] = $this->getEncrypter()->decrypt($cookie);
            }
            catch (DecryptionException $exception)
            {
                $cookies[$name] = null;
            }
        }

        return $altered ? $request->withCookieParams($cookies) : $request;
    }

    /**
     * Pack outcoming cookies with encrypted value.
     *
     * @param ResponseInterface $response
     * @return ResponseInterface|Response
     * @throws EncrypterException
     */
    protected function encryptCookies(ResponseInterface $response)
    {
        if (!$response instanceof Response)
        {
            //We may need to manually set headers in future
            return $response;
        }

        if (($cookies = $response->getCookies()) || !empty($this->scheduled))
        {
            /**
             * @var CookieInterface[] $cookies
             */
            $cookies = array_merge($cookies, $this->scheduled);
            foreach ($cookies as $cookie)
            {
                if (in_array($cookie->getName(), $this->exclude))
                {
                    continue;
                }

                $cookies[$cookie->getName()] = $cookie->withValue(
                    $this->getEncrypter()->encrypt($cookie->getValue())
                );
            }

            $this->scheduled = array();

            //Overwriting cookies
            return $response->withCookies($cookies);
        }

        return $response;
    }

    /**
     * Schedule new cookie. Cookie will be send while dispatching request.
     *
     * @link http://php.net/manual/en/function.setcookie.php
     * @param string $name     The name of the cookie.
     * @param string $value    The value of the cookie. This value is stored on the clients computer;
     *                         do not store sensitive information.
     * @param int    $lifetime Cookie lifetime. This value specified in seconds and declares period
     *                         of time in which cookie will expire relatively to current time() value.
     * @param string $path     The path on the server in which the cookie will be available on.
     *                         If set to '/', the cookie will be available within the entire domain.
     *                         If set to '/foo/', the cookie will only be available within the /foo/
     *                         directory and all sub-directories such as /foo/bar/ of domain. The
     *                         default value is the current directory that the cookie is being set in.
     * @param string $domain   The domain that the cookie is available. To make the cookie available
     *                         on all subdomains of example.com then you'd set it to '.example.com'.
     *                         The . is not required but makes it compatible with more browsers.
     *                         Setting it to www.example.com will make the cookie only available in
     *                         the www subdomain. Refer to tail matching in the spec for details.
     * @param bool   $secure   Indicates that the cookie should only be transmitted over a secure HTTPS
     *                         connection from the client. When set to true, the cookie will only be
     *                         set if a secure connection exists. On the server-side, it's on the
     *                         programmer to send this kind of cookie only on secure connection (e.g.
     *                         with respect to $_SERVER["HTTPS"]).
     * @param bool   $httpOnly When true the cookie will be made accessible only through the HTTP
     *                         protocol. This means that the cookie won't be accessible by scripting
     *                         languages, such as JavaScript. This setting can effectively help to
     *                         reduce identity theft through XSS attacks (although it is not supported
     *                         by all browsers).
     * @return Cookie
     */
    public function set(
        $name,
        $value = null,
        $lifetime = 0,
        $path = Cookie::DEPENDS,
        $domain = Cookie::DEPENDS,
        $secure = Cookie::DEPENDS,
        $httpOnly = true
    )
    {
        $cookie = new Cookie($name, $value, $lifetime, $path, $domain, $secure, $httpOnly);
        $this->scheduled[] = $cookie;

        return $cookie;
    }

    /**
     * Schedule new cookie instance to be send while dispatching request.
     *
     * @param CookieInterface $cookie
     * @return static
     */
    public function add(CookieInterface $cookie)
    {
        $this->scheduled[] = $cookie;

        return $this;
    }

    /**
     * Cookies has to be send (specified via global scope).
     *
     * @return CookieInterface[]
     */
    public function getScheduled()
    {
        return $this->scheduled;
    }
}