<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://hyperf.io
 * @document https://doc.hyperf.io
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf-cloud/hyperf/blob/master/LICENSE
 */

namespace Hyperf\Di;

use Hyperf\Di\Aop\Ast;
use Hyperf\Di\Definition\FactoryDefinition;
use Hyperf\Di\Definition\ObjectDefinition;
use Hyperf\Utils\Coroutine\Locker as CoLocker;

class ProxyFactory
{
    /**
     * @var array
     */
    private static $map = [];

    /**
     * @var Ast
     */
    private $ast;

    public function __construct()
    {
        $this->ast = new Ast();
    }

    public function createProxyDefinition(ObjectDefinition $definition): ObjectDefinition
    {
        $identifier = $definition->getName();
        if (isset(static::$map[$identifier])) {
            return static::$map[$identifier];
        }
        $proxyIdentifier = null;
        if ($definition instanceof FactoryDefinition) {
            $proxyIdentifier = $definition->getFactory() . '_' . md5($definition->getFactory());
            $proxyIdentifier && $definition->setTarget($proxyIdentifier);
            $this->loadProxy($definition->getName(), $definition->getFactory());
        } elseif ($definition instanceof ObjectDefinition) {
            $proxyIdentifier = $definition->getClassName() . '_' . md5($definition->getClassName());
            $definition->setProxyClassName($proxyIdentifier);
            $this->loadProxy($definition->getClassName(), $definition->getProxyClassName());
        }
        static::$map[$identifier] = $definition;
        return static::$map[$identifier];
    }

    private function loadProxy(string $className, string $proxyClassName): void
    {
        $dir = BASE_PATH . '/runtime/container/proxy/';
        if (! file_exists($dir)) {
            mkdir($dir, 0755, true);
        }
        $proxyFileName = str_replace('\\', '_', $proxyClassName);
        $path = $dir . $proxyFileName . '.proxy.php';

        $key = md5($path);
        // If the proxy file does not exist, then try to acquire the coroutine lock.
        if (! file_exists($path) && CoLocker::lock($key)) {
            // Try to acquire the file lock via the worker who acquired the coroutine lock.
            $tp = $path . '.' . uniqid();
            $resource = fopen($tp, 'a+');
            if (flock($resource, LOCK_EX)) {
                ftruncate($resource, 0);
                $code = $this->ast->proxy($className, $proxyClassName);
                fwrite($resource, $code);
                fflush($resource);
                copy($tp, $path);
                flock($resource, LOCK_UN);
                CoLocker::unlock($key);
            }
            fclose($resource);
            unlink($tp);
        }
        include_once $path;
    }
}
