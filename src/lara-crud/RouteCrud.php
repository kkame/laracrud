<?php

namespace LaraCrud;

use Illuminate\Support\Facades\Route;

/**
 * Create Routes based on controller method and its parameters
 * We will use ReflectionClass to inspect Controller and its method to generate routes based on it
 *
 * @author Tuhin
 */
class RouteCrud extends LaraCrud
{
    /**
     * Save all register routes name. To avoid name conficts for new routes
     * @var array
     */
    public $routesName = [];

    /**
     * Save all methods name group by controller name which have route defined.
     * 
     * @var array
     */
    public $methodNames = [];

    /**
     * All registerd Laravel Routes will be stored here.
     * 
     * @var array
     * here is a single route example
     * [
     * 'name'=>'get.user.profile',
     * 'path'=>'',
     * 'controller'=>UserController,
     * 'action'=>'profile',
     * 'method'=>'GET'
     * ]
     */
    public $routes = [];

    /**
     * List of available controllers 
     * @var array
     */
    public $controllers = [];

    /**
     * Save all methods name group by controller name
     * To check which method have no route defined yet by comparing to $methodNames
     * 
     * @var array
     */
    public $controllerMethods = [];

    /**
     * It is possible to have Controller under Admin folder with the namespae of Admin.
     * @var string
     */
    public $subNameSpace = '';

    const PARENT_NAMESPACE = 'App\Http\Controllers\\';

    public function __construct($controller = '')
    {
         parent::__construct();
        if (!is_array($controller)) {
            $this->controllers[] = $controller;
        } else {
            $this->controllers = $controller;
        }
        $this->getRoute();
        $this->fetchControllerMethods();
    }

    /**
     * This will get all defined routes. 
     */
    public function getRoute()
    {
        $routes = Route::getRoutes();
        foreach ($routes as $route) {
            $controllerName = strstr($route->getActionName(), '@', true);
            $methodName     = str_replace("@", "",
                strstr($route->getActionName(), '@'));
            $this->routes[] = [
                'name' => $route->getName(),
                'path' => $route->getPath(),
                'controller' => $controllerName,
                'action' => $route->getActionName(),
                'method' => $methodName
            ];

            if (!empty($controllerName)) {
                $this->methodNames[$controllerName][] = $methodName;
            }

            if (!empty($route->getName())) {
                $this->routesName[] = $route->getName();
            }
        }
    }

    /**
     * Get all controller methods which is public
     */
    public function fetchControllerMethods()
    {
        foreach ($this->controllers as $controller) {
            $reflectionClass = new \ReflectionClass($controller);
            $methods         = $reflectionClass->getMethods(\ReflectionMethod::IS_PUBLIC);

            $this->controllerMethods[$controller] = array(
                'full_name' => $controller,
                'shortName' => $reflectionClass->getShortName(),
                'description' => $reflectionClass->getDocComment(),
                'methods' => $this->filterMethod($controller, $methods)
            );
        }
    }

    /**
     * Child class all the method of its parent. But we will accept only child class method.
     * 
     * @param string $controllerName
     * @param string $reflectionMethods
     * @return array
     */
    protected function filterMethod($controllerName, $reflectionMethods)
    {
        $retMethods = [];
        foreach ($reflectionMethods as $method) {
            if (substr_compare($method->name, '__', 0, 2) != 0 && $method->class
                == $controllerName) {
                $retMethods[] = $method->name;
            }
        }
        return $retMethods;
    }

    public function make()
    {
        $routesCode = $this->generateContent();
        $this->appendRoutes($routesCode);
    }

    /**
     * Append route to routes.php file
     * @param string $routesCode
     */
    public function appendRoutes($routesCode)
    {
        $routePath = base_path($this->getConfig('routeFile',
                'app/Http/routes.php'));
        if (file_exists($routePath)) {
            $splFile = new \SplFileObject($routePath, 'a');
            $splFile->fwrite($routesCode);
        }
    }

    /**
     * Generate route string
     * @return string
     */
    public function generateContent()
    {
        $retRoutes = '';
        foreach ($this->controllerMethods as $controllerName => $ctr) {
            $controllerRoutes = '';
            $subNameSpace     = '';

            $path                = str_replace([static::PARENT_NAMESPACE, $ctr['shortName']],
                "", $ctr['full_name']);
            $path                = trim($path, "\\");
            $controllerShortName = strtolower(str_replace("Controller", "",
                    $ctr['shortName']));

            if (!empty($path)) {
                $subNameSpace        = ','."'namespace'=>'".$path."'";
                $controllerShortName = strtolower($path)."/".$controllerShortName;
            }

            $routesMethods     = isset($this->methodNames[$controllerName]) ? $this->methodNames[$controllerName]
                    : [];
            $controllerMethods = isset($ctr['methods']) ? $ctr['methods'] : [];
            $newRouteMethods   = array_diff($controllerMethods, $routesMethods);
            foreach ($newRouteMethods as $newMethod) {
                $controllerRoutes.=$this->generateRoute($ctr['shortName'],
                    $newMethod, $controllerName, $path);
            }
            if (empty($controllerRoutes)) {
                continue;
            }



            $reflectionClass = new \ReflectionClass($ctr['full_name']);
            $routeGroupTemp  = $this->getTempFile('route_group.txt');
            $routeGroupTemp  = str_replace('@@namespace@@', $subNameSpace,
                $routeGroupTemp);
            $routeGroupTemp  = str_replace('@@routes@@', $controllerRoutes,
                $routeGroupTemp);
            $routeGroupTemp  = str_replace('@@prefix@@', $controllerShortName,
                $routeGroupTemp);
            $retRoutes.=$routeGroupTemp;
        }
        return $retRoutes;
    }

    /**
     * Generate an idividual routes
     * 
     * @param string $controllerName e.g. UserController
     * @param string $method    e.g. GET,PUT,POST,DELETE based on the prefix of method name.
     * If a controller method name is postSave then its method will be post
     * @param string $fullClassName
     * @param string $subNameSpace
     * @return string
     */
    public function generateRoute($controllerName, $method, $fullClassName = '',
                                  $subNameSpace = '')
    {
        $template  = $this->getTempFile('route.txt');
        $matches   = [];
        $path      = '';
        $routeName = '';
        preg_match('/^(get|post|put|delete)[A-Z]{1}/', $method, $matches);

        $routeMethodName = 'get';

        if (!empty($subNameSpace)) {
            $routeName = strtolower($subNameSpace).".";
        }

        $path.=strtolower($method);
        if (count($matches) > 0) {
            $routeMethodName = array_pop($matches);
            $path            = substr_replace($path, '', 0,
                strlen($routeMethodName));
        }

        $path.=$this->addParams($fullClassName, $method);

        $controllerShortName = str_replace("Controller", "", $controllerName);


        $actionName = $controllerName.'@'.$method;
        $routeName.=strtolower($controllerShortName).'.'.strtolower($method);

        $template = str_replace('@method@', $routeMethodName, $template);
        $template = str_replace('@@path@@', '/'.$path, $template);
        $template = str_replace('@@routeName@@', $routeName, $template);
        $template = str_replace('@@action@@', $actionName, $template);
        return $template;
    }

    /**
     * One method may have several params are some may have default values and some may not have.
     * we will inspect this params and define in routes respectively
     * 
     * @param string $controller
     * @param string $method
     * @return string
     */
    public function addParams($controller, $method)
    {
        $params           = '';
        $reflectionMethod = new \ReflectionMethod($controller, $method);

        foreach ($reflectionMethod->getParameters() as $param) {
            // print_r(get_class_methods($param));
            if ($param->getClass()) {
                continue;
            }
            $optional = $param->isOptional() == TRUE ? '?' : "";
            $params.='/{'.$param->getName().$optional.'}';
        }
        return $params;
    }
}