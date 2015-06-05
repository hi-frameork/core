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
use Spiral\Components\Http\HttpDispatcher;
use Spiral\Components\Http\Middlewares\CsrfFilter;
use Spiral\Components\Http\MiddlewareInterface;
use Spiral\Components\Session\Http\SessionStarter;
use Spiral\Core\Component;
use Spiral\Core\Container;
use Spiral\Facades\Cookies;

class CookieManager extends Component implements MiddlewareInterface
{
    /**
     * Required traits.
     */
    use Component\ConfigurableTrait;


    /**
     * Algorithm used to sign cookies.
     */
    const HMAC_ALGORITHM = 'sha256';

    /**
     * Generated MAC length, has to be stripped from cookie.
     */
    const MAC_LENGTH = 64;

    /**
     * Cookie protection modes.
     */
    const NONE    = 'none';
    const ENCRYPT = 'encrypt';
    const MAC     = 'mac';

    /**
     * Container instance is required to resolve encrypter when required.
     *
     * @invisible
     * @var Container
     */
    protected $container = null;

    /**
     * Http request.
     *
     * @invisible
     * @var ServerRequestInterface
     */
    protected $request = null;

    /**
     * Cookie names should never be encrypted or decrypted.
     *
     * @var array
     */
    protected $exclude = array(CsrfFilter::COOKIE, SessionStarter::COOKIE);

    /**
     * Encrypter component.
     *
     * @var Encrypter
     */
    protected $encrypter = null;

    /**
     * Cookies has to be send (specified via global scope).
     *
     * @var Cookie[]
     */
    protected $scheduled = array();

    /**
     * Middleware constructing.
     *
     * @param Container      $container
     * @param HttpDispatcher $dispatcher
     */
    public function __construct(Container $container, HttpDispatcher $dispatcher)
    {
        $this->container = $container;
        $this->config = $dispatcher->getConfig()['cookies'];
    }

    /**
     * Do not encrypt/decrypt cookie.
     *
     * @param string $name
     */
    public function excludeCookie($name)
    {
        $this->exclude[] = $name;
    }

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

        return $this->encrypter = Encrypter::getInstance($this->container);
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
        //Opening scope
        $outerManager = $this->container->getBinding(__CLASS__);
        $this->container->bind(__CLASS__, $this);

        $this->request = $request;
        $request = $this->decodeCookies($request);

        /**
         * @var ResponseInterface $response
         */
        $response = $next($request->withAttribute('cookieDomain', $this->getDomain()));
                $response = $this->mountCookies($response);

        //Restoring scope
        $this->container->removeBinding(__CLASS__);
        !empty($outerManager) && $this->container->bind(__CLASS__, $outerManager);

        return $response;
    }

    /**
     * Unpack incoming cookies and decrypt their content.
     *
     * @param ServerRequestInterface $request
     * @return ServerRequestInterface
     */
    protected function decodeCookies(ServerRequestInterface $request)
    {
        $altered = false;
        $cookies = $request->getCookieParams();

        foreach ($cookies as $name => $cookie)
        {
            if (in_array($name, $this->exclude) || $this->config['method'] == self::NONE)
            {
                continue;
            }

            $altered = true;
            $cookies[$name] = $this->decodeCookie($cookie);
        }

        return $altered ? $request->withCookieParams($cookies) : $request;
    }

    /**
     * Pack outcoming cookies with encrypted value.
     *
     * @param ResponseInterface $response
     * @return ResponseInterface
     * @throws EncrypterException
     */
    protected function mountCookies(ResponseInterface $response)
    {
        if (empty($this->scheduled))
        {
            return $response;
        }

        $cookies = $response->getHeader('Set-Cookie');

        //Merging cookies
        foreach ($this->scheduled as $cookie)
        {
            if (in_array($cookie->getName(), $this->exclude) || $this->config['method'] == self::NONE)
            {
                $cookies[] = $cookie->packHeader();
                continue;
            }

            $cookies[] = $this->encodeCookie($cookie)->packHeader();
        }

        $this->scheduled = array();

        return $response->withHeader('Set-Cookie', $cookies);
    }

    /**
     * Helper method used to decrypt cookie value or values.
     *
     * @param string|array $cookie
     * @return array|mixed|null
     */
    protected function decodeCookie($cookie)
    {
        if ($this->config['method'] == 'encrypt')
        {
            try
            {
                if (is_array($cookie))
                {
                    return array_map(array($this, 'decodeCookie'), $cookie);
                }

                return $this->getEncrypter()->decrypt($cookie);
            }
            catch (DecryptionException $exception)
            {
                return null;
            }
        }

        //MAC
        $mac = substr($cookie, -1 * self::MAC_LENGTH);
        $value = substr($cookie, 0, strlen($cookie) - strlen($mac));

        if ($this->getSignature($value) != $mac)
        {
            return null;
        }

        return $value;
    }

    /**
     * Encode cookie to be sent to client
     *
     * @param Cookie $cookie
     * @return Cookie
     */
    protected function encodeCookie(Cookie $cookie)
    {
        if ($this->config['method'] == 'encrypt')
        {
            return $cookie->withValue(
                $this->getEncrypter()->encrypt($cookie->getValue())
            );
        }

        //MAC
        return $cookie->withValue(
            $cookie->getValue() . $this->getSignature($cookie->getValue())
        );
    }

    /**
     * Create cookie signature.
     *
     * @param string $value
     * @return string
     */
    protected function getSignature($value)
    {
        return hash_hmac(self::HMAC_ALGORITHM, $value, $this->getEncrypter()->getKey());
    }

    /**
     * Create new cookie instance without adding it to scheduled list.
     *
     * Domain, path, and secure values can be left in null state, in this case cookie manager will
     * populate them automatically.
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
    public function create(
        $name,
        $value = null,
        $lifetime = null,
        $path = null,
        $domain = null,
        $secure = null,
        $httpOnly = true
    )
    {
        if (is_null($path))
        {
            $path = $this->config['path'];
        }

        if (is_null($domain))
        {
            $domain = $this->getDomain();
        }

        if (is_null($secure))
        {
            $secure = $this->request->getMethod() == 'https';
        }

        return new Cookie($name, $value, $lifetime, $path, $domain, $secure, $httpOnly);
    }

    /**
     * Change domain pattern. Domain pattern specified in cookie config is presented as valid sprintf
     * expression.
     *
     * Example:
     * mydomain.com //Forced domain value
     * %s           //Cookies will be mounted on current domain
     * .%s          //Cookies will be mounted on current domain and sub domains
     *
     * @param string $domain
     */
    public function setDomain($domain)
    {
        $this->config['domain'] = $domain;
    }

    /**
     * Default domain to set cookie for. Domain pattern specified in cookie config is presented as
     * valid sprintf expression.
     *
     * Example:
     * mydomain.com //Forced domain value
     * %s           //Cookies will be mounted on current domain
     * .%s          //Cookies will be mounted on current domain and sub domains
     *
     * @return string
     */
    public function getDomain()
    {
        $host = $this->request->getUri()->getHost();

        $pattern = $this->config['domain'];
        if (filter_var($host, FILTER_VALIDATE_IP))
        {
            //We can't use sub domains
            $pattern = ltrim($pattern, '.');
        }

        if ($port = $this->request->getUri()->getPort())
        {
            $host = $host . ':' . $port;
        }

        if (strpos($pattern, '%s') === false)
        {
            //Forced domain
            return $pattern;
        }

        return sprintf($pattern, $host);
    }

    /**
     * Schedule new cookie. Cookie will be send while dispatching request.
     *
     * Domain, path, and secure values can be left in null state, in this case cookie manager will
     * populate them automatically.
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
        $lifetime = null,
        $path = null,
        $domain = null,
        $secure = null,
        $httpOnly = true
    )
    {
        $cookie = $this->create($name, $value, $lifetime, $path, $domain, $secure, $httpOnly);
        $this->scheduled[] = $cookie;

        return $cookie;
    }

    /**
     * Schedule cookie removal.
     *
     * @param string $name The name of the cookie.
     */
    public function delete($name)
    {
        foreach ($this->scheduled as $index => $cookie)
        {
            if ($cookie->getName() == $name)
            {
                unset($this->scheduled[$index]);
            }
        }

        $this->scheduled[] = new Cookie($name, null, -86400);
    }

    /**
     * Schedule new cookie instance to be send while dispatching request.
     *
     * @param Cookie $cookie
     * @return static
     */
    public function add(Cookie $cookie)
    {
        $this->scheduled[] = $cookie;

        return $this;
    }

    /**
     * Cookies has to be send (specified via global scope).
     *
     * @return Cookie[]
     */
    public function getScheduled()
    {
        return $this->scheduled;
    }
}