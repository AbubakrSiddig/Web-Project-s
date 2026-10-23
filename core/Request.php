<?php
namespace Core;

/**
 * فئة التعامل مع طلبات HTTP (Request)
 */
class Request
{
    /** @var string */
    protected $method;

    /** @var string */
    protected $uri;

    /** @var string */
    protected $path;

    /** @var array */
    protected $queryParams = [];

    /** @var array */
    protected $bodyParams = [];

    /** @var array */
    protected $headers = [];

    /** @var array */
    protected $routeParams = [];

    /** @var array */
    protected $files = [];

    /** @var mixed */
    protected $user = null;

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->uri = $_SERVER['REQUEST_URI'] ?? '/';
        $this->path = $this->parsePath($this->uri);
        $this->queryParams = $_GET ?? [];
        $this->headers = $this->parseHeaders();
        $this->files = $_FILES ?? [];
        $this->parseBody();
    }

    /**
     * استخراج المسار الصافي من URI بدون query string
     */
    protected function parsePath($uri)
    {
        $path = parse_url($uri, PHP_URL_PATH);
        
        // إزالة بادئة المجلد الفرعي إذا كان المشروع يعمل داخل مسار فرعي في XAMPP
        $scriptName = dirname($_SERVER['SCRIPT_NAME'] ?? '');
        if ($scriptName !== '/' && $scriptName !== '\\' && !empty($scriptName)) {
            if (strpos($path, $scriptName) === 0) {
                $path = substr($path, strlen($scriptName));
            }
        }

        $path = '/' . trim($path, '/');
        return $path === '' ? '/' : $path;
    }

    /**
     * قراءة وترميز ترويسات HTTP
     */
    protected function parseHeaders()
    {
        $headers = [];
        if (function_exists('getallheaders')) {
            $all = getallheaders();
            foreach ($all as $k => $v) {
                $headers[strtolower($k)] = $v;
            }
        } else {
            foreach ($_SERVER as $k => $v) {
                if (substr($k, 0, 5) === 'HTTP_') {
                    $header = strtolower(str_replace('_', '-', substr($k, 5)));
                    $headers[$header] = $v;
                } elseif (in_array($k, ['CONTENT_TYPE', 'CONTENT_LENGTH'])) {
                    $header = strtolower(str_replace('_', '-', $k));
                    $headers[$header] = $v;
                }
            }
        }
        return $headers;
    }

    /**
     * قراءة مدخلات الـ Body (سواء كانت Form-Data أو JSON)
     */
    protected function parseBody()
    {
        $contentType = $this->getHeader('content-type', '');

        if (strpos($contentType, 'application/json') !== false) {
            $rawInput = file_get_contents('php://input');
            $decoded = json_decode($rawInput, true);
            $this->bodyParams = is_array($decoded) ? $decoded : [];
        } else {
            $this->bodyParams = $_POST ?? [];
            if ($this->method === 'PUT' || $this->method === 'DELETE') {
                $rawInput = file_get_contents('php://input');
                parse_str($rawInput, $parsed);
                $this->bodyParams = is_array($parsed) ? array_merge($this->bodyParams, $parsed) : $this->bodyParams;
            }
        }
    }

    public function getMethod() { return $this->method; }
    public function getUri() { return $this->uri; }
    public function getPath() { return $this->path; }

    /**
     * الحصول على قيمة مدخل معين (من Body أو Query أو Route)
     */
    public function input($key = null, $default = null)
    {
        $merged = array_merge($this->queryParams, $this->bodyParams, $this->routeParams);
        if ($key === null) {
            return $merged;
        }
        return $merged[$key] ?? $default;
    }

    /**
     * الحصول على كافة المدخلات
     */
    public function all()
    {
        return array_merge($this->queryParams, $this->bodyParams, $this->routeParams);
    }

    /**
     * الحصول على مدخلات محددة فقط
     */
    public function only(array $keys)
    {
        $data = $this->all();
        $result = [];
        foreach ($keys as $k) {
            if (array_key_exists($k, $data)) {
                $result[$k] = $data[$k];
            }
        }
        return $result;
    }

    public function query($key = null, $default = null)
    {
        if ($key === null) return $this->queryParams;
        return $this->queryParams[$key] ?? $default;
    }

    public function getHeader($key, $default = null)
    {
        $key = strtolower($key);
        return $this->headers[$key] ?? $default;
    }

    public function getBearerToken()
    {
        $auth = $this->getHeader('authorization');
        if ($auth && preg_match('/Bearer\s+(.*)$/i', $auth, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }

    public function setRouteParams(array $params)
    {
        $this->routeParams = $params;
    }

    public function routeParam($key, $default = null)
    {
        return $this->routeParams[$key] ?? $default;
    }

    public function getIp()
    {
        return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    public function getUserAgent()
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    }

    public function setUser($user) { $this->user = $user; }
    public function getUser() { return $this->user; }
}
