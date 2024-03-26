<?php

declare(strict_types=1);

namespace mon\thinkORM;

use Closure;
use RuntimeException;
use mon\http\Response;
use think\facade\Db as ThinkDb;
use think\DbManager as ThinkDBM;
use think\Container as ThinkContainer;
use mon\http\interfaces\RequestInterface;
use mon\http\interfaces\MiddlewareInterface;

/**
 * mon-http中间件支持，处理数据库日志及事务安全验证
 * 
 * @author Mon <985558837@qq.com>
 * @version 1.0.0
 */
class ORMMiddleware implements MiddlewareInterface
{
    /**
     * 中间件实现接口
     *
     * @param RequestInterface $request  请求实例
     * @param Closure $next 执行下一个中间件回调方法
     * @return Response
     */
    public function process(RequestInterface $request, Closure $next): Response
    {
        // 执行响应
        $response = $next($request);
        // 清除sql日志
        ThinkDb::getDbLog(true);
        // 校验事务是否已全部提交
        $this->checkTpUncommittedTransaction();

        return $response;
    }

    /**
     * 检查think-orm是否有未提交的事务
     *
     * @return array
     */
    protected function checkTpUncommittedTransaction()
    {
        static $property, $manager_instance;
        if (!$property) {
            if (class_exists(ThinkContainer::class, false)) {
                $manager_instance = ThinkContainer::getInstance()->make(ThinkDBM::class);
            } else {
                $reflect = new \ReflectionClass(ThinkDb::class);
                $property = $reflect->getProperty('instance');
                $property->setAccessible(true);
                $manager_instance = $property->getValue();
            }
            $reflect = new \ReflectionClass($manager_instance);
            $property = $reflect->getProperty('instance');
            $property->setAccessible(true);
        }

        $instances = $property->getValue($manager_instance);
        /** @var \think\db\connector\Mysql $connection */
        foreach ($instances as $connection) {
            if (method_exists($connection, 'getPdo')) {
                $pdo = $connection->getPdo();
                // 存在未提交的事务，事务回滚，抛出异常
                if ($pdo && $pdo->inTransaction()) {
                    $connection->rollBack();
                    throw new RuntimeException('Uncommitted transaction found and try to rollback');
                }
            }
        }
    }
}
