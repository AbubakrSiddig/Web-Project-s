<?php
namespace Core;

use Core\Middleware\MiddlewareInterface;
use Exception;

/**
 * موجّه المسارات الذكي (Router)
 */
class Router
{
    /** @var array */
    protected $routes = [];

    /** @var array */
    protected $groupStack = [];

    /**
     * إضافة مسار جديد
     */
    public function addRoute($method, $path, $action, array $middlewares = [])
    {
        $prefix = '';
        $groupMiddlewares = [];

        foreach ($this->groupStack as $group) {
            if (isset($group['prefix'])) {
                $prefix .= '/' . trim($group['prefix'], '/');
            }
            if (isset($group['middleware'])) {
                $groupMiddlewares = array_merge($groupMiddlewares, (array)$group['middleware']);
            }
        }

        $fullPath = '/' . trim($prefix . '/' . trim($path, '/'), '/');
        if ($fullPath !== '/') {
            $fullPath = rtrim($fullPath, '/');
        }

        $allMiddlewares = array_merge($groupMiddlewares, $middlewares);

        $this->routes[] = [
            'method'      => strtoupper($method),
            'path'        => $fullPath,
            'action'      => $action,
            'middlewares' => $allMiddlewares,
        ];
    }

    public function get($path, $action, array $middlewares = []) { $this->addRoute('GET', $path, $action, $middlewares); }
    public function post($path, $action, array $middlewares = []) { $this->addRoute('POST', $path, $action, $middlewares); }
    public function put($path, $action, array $middlewares = []) { $this->addRoute('PUT', $path, $action, $middlewares); }
    public function delete($path, $action, array $middlewares = []) { $this->addRoute('DELETE', $path, $action, $middlewares); }

    /**
     * مجموعة مسارات تشترك في البادئة أو الوسيطات
     */
    public function group(array $attributes, callable $callback)
    {
        $this->groupStack[] = $attributes;
        call_user_func($callback, $this);
        array_pop($this->groupStack);
    }

    /**
     * توجيه وتشغيل الطلب الحالي
     */
    public function dispatch(Request $request)
    {
        $requestMethod = $request->getMethod();
        $requestPath = $request->getPath();

        // معالجة طلبات OPTIONS تلقائياً لـ CORS
        if ($requestMethod === 'OPTIONS') {
            Response::sendCorsHeaders();
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $requestMethod) {
                continue;
            }

            $pattern = $this->compilePattern($route['path'], $paramNames);

            if (preg_match($pattern, $requestPath, $matches)) {
                $params = [];
                foreach ($paramNames as $index => $name) {
                    $params[$name] = $matches[$index + 1] ?? null;
                }
                $request->setRouteParams($params);

                return $this->runRoute($route, $request);
            }
        }

        // مسار غير موجود
        Response::notFound("المسار المطلوب [{$requestMethod} {$requestPath}] غير موجود.");
    }

    /**
     * تحويل المسار إلى تعبير نمطي (Regex)
     */
    protected function compilePattern($routePath, &$paramNames)
    {
        $paramNames = [];
        $pattern = preg_replace_callback('/\{([a-zA-Z0-9_]+)\}/', function ($matches) use (&$paramNames) {
            $paramNames[] = $matches[1];
            return '([^/]+)';
        }, $routePath);

        return '#^' . $pattern . '$#';
    }

    /**
     * تنفيذ مسار محدد مع سلسلة الوسيطات
     */
    protected function runRoute(array $route, Request $request)
    {
        $pipeline = function (Request $req) use ($route) {
            return $this->callAction($route['action'], $req);
        };

        $middlewares = array_reverse($route['middlewares']);
        foreach ($middlewares as $mw) {
            $prevPipeline = $pipeline;
            $pipeline = function (Request $req) use ($mw, $prevPipeline) {
                $instance = is_string($mw) ? new $mw() : $mw;
                if ($instance instanceof MiddlewareInterface) {
                    return $instance->handle($req, $prevPipeline);
                }
                return $prevPipeline($req);
            };
        }

        return $pipeline($request);
    }

    /**
     * استدعاء المتحكم أو الدالة المجهولة
     */
    protected function callAction($action, Request $request)
    {
        if (is_callable($action)) {
            return call_user_func($action, $request);
        }

        if (is_string($action) && strpos($action, '@') !== false) {
            list($controllerName, $method) = explode('@', $action);

            if (!class_exists($controllerName)) {
                Response::error("فئة المتحكم غير موجودة: {$controllerName}", null, 500);
            }

            $controller = new $controllerName();
            if (!method_exists($controller, $method)) {
                Response::error("الدالة غير موجودة في المتحكم: {$controllerName}@{$method}", null, 500);
            }

            return $controller->$method($request);
        }

        Response::error("تعريف المسار غير صالح.", null, 500);
    }
}
