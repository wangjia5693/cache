<?php

/**
 * Author: wangjia <wangj01@lizi.com>
 * Created by Administrator on 2017/3/14.
 *
 */

namespace Cache\Adapter;

use Cache\CacheItem;
use Cache\Exception\InvalidArgumentException;

/**
 * 适配器抽象类；所有实例类需继承此类
*/
abstract class AbstractAdapter implements AdapterInterface
{

    private static $phpFilesSupported;

    private $namespace;
    private $deferred = array();
    private $createCacheItem;
    private $mergeByLifetime;
    protected $logger = null;
    protected $maxIdLength;

    /**
     * 设置日志操作实例；logger必须要实现warning方法
     */
    public function setLogger( $logger)
    {
        $this->logger = $logger;
    }


    protected function __construct($namespace = '', $defaultLifetime = 0)
    {
        $this->namespace = '' === $namespace ? '' : $this->getId($namespace).':';
        if (null !== $this->maxIdLength && strlen($namespace) > $this->maxIdLength - 24) {
            throw new InvalidArgumentException(sprintf('Namespace must be %d chars max, %d given ("%s")', $this->maxIdLength - 24, strlen($namespace), $namespace));
        }
        #创建缓存，具体的
        $this->createCacheItem = \Closure::bind(//把闭包当成对象的成员方法或者静态成员方法
            function ($key, $value, $isHit) use ($defaultLifetime) {
                $item = new CacheItem();
                $item->key = $key;
                $item->value = $value;
                $item->isHit = $isHit;
                $item->defaultLifetime = $defaultLifetime;

                return $item;
            },
            null,
            CacheItem::class
        );
        #生命周期mergeByLifetime;延迟缓存使用
        $this->mergeByLifetime = \Closure::bind(
            function ($deferred, $namespace, &$expiredIds) {
                $byLifetime = array();
                $now = time();
                $expiredIds = array();

                foreach ($deferred as $key => $item) {
                    if (null === $item->expiry) {
                        $byLifetime[0 < $item->defaultLifetime ? $item->defaultLifetime : 0][$namespace.$key] = $item->value;
                    } elseif ($item->expiry > $now) {
                        $byLifetime[$item->expiry - $now][$namespace.$key] = $item->value;
                    } else {
                        $expiredIds[] = $namespace.$key;
                    }
                }

                return $byLifetime;
            },
            null,
            CacheItem::class
        );
    }



    /**
     * 通过标识获取缓存
     */
    abstract protected function doFetch(array $ids);

    /**
     * 确认是有已经存在
     */
    abstract protected function doHave($id);

    /**
     * 删除所有
     */
    abstract protected function doClear($namespace);

    /**
     * 删除多个
     */
    abstract protected function doDelete(array $ids);

    /**
     * 保存
     */
    abstract protected function doSave(array $values, $lifetime);

    /**
     * 获取单个键值
     */
    public function getItem($key)
    {
        if ($this->deferred) {
            $this->commit();
        }
        $id = $this->getId($key);

        $f = $this->createCacheItem;
        $isHit = false;
        $value = null;

        try {
            foreach ($this->doFetch(array($id)) as $value) {
                $isHit = true;
            }
        } catch (\Exception $e) {
            CacheItem::log($this->logger, 'Failed to fetch key "{key}"', array('key' => $key, 'exception' => $e));
        }

        return $f($key, $value, $isHit);
    }

    /**
     * 获取多个键值
     */
    public function getItems(array $keys = array())
    {
        if ($this->deferred) {
            $this->commit();
        }
        $ids = array();

        foreach ($keys as $key) {
            $ids[] = $this->getId($key);
        }

        try {
            $items = $this->doFetch($ids);
        } catch (\Exception $e) {
            CacheItem::log($this->logger, 'Failed to fetch requested items', array('keys' => $keys, 'exception' => $e));
            $items = array();
        }

        $ids = array_combine($ids, $keys);

        return $this->generateItems($items, $ids);
    }

    /**
     * {@inheritdoc}
     */
    public function hasItem($key)
    {
        $id = $this->getId($key);

        if (isset($this->deferred[$key])) {
            $this->commit();
        }

        try {
            return $this->doHave($id);
        } catch (\Exception $e) {
            CacheItem::log($this->logger, 'Failed to check if key "{key}" is cached', array('key' => $key, 'exception' => $e));

            return false;
        }
    }

    /**
     * 清除
     */
    public function clear()
    {
        $this->deferred = array();

        try {
            return $this->doClear($this->namespace);
        } catch (\Exception $e) {
            CacheItem::log($this->logger, 'Failed to clear the cache', array('exception' => $e));

            return false;
        }
    }

    /**
     * 清除
     */
    public function deleteItem($key)
    {
        return $this->deleteItems(array($key));
    }

    /**
     * 清除
     */
    public function deleteItems(array $keys)
    {
        $ids = array();

        foreach ($keys as $key) {
            $ids[$key] = $this->getId($key);
            unset($this->deferred[$key]);
        }

        try {
            if ($this->doDelete($ids)) {
                return true;
            }
        } catch (\Exception $e) {
        }

        $ok = true;

        // 整体删除失败后采用单独删除
        foreach ($ids as $key => $id) {
            try {
                $e = null;
                if ($this->doDelete(array($id))) {
                    continue;
                }
            } catch (\Exception $e) {
            }
            CacheItem::log($this->logger, 'Failed to delete key "{key}"', array('key' => $key, 'exception' => $e));
            $ok = false;
        }

        return $ok;
    }

    /**
     * {@inheritdoc}
     */
    public function save( $item)
    {
        if (!$item instanceof CacheItem) {
            return false;
        }
        $this->deferred[$item->getKey()] = $item;

        return $this->commit();
    }

    /**
     * {@inheritdoc}
     */
    public function saveDeferred( $item)
    {
        if (!$item instanceof CacheItem) {
            return false;
        }
        $this->deferred[$item->getKey()] = $item;

        return true;
    }

    /**
     * 延迟缓存执行
     */
    public function commit()
    {
        $ok = true;
        $byLifetime = $this->mergeByLifetime;
        $expiredIds = null;
        $byLifetime = $byLifetime($this->deferred, $this->namespace, $expiredIds);
        $retry = $this->deferred = array();

        if ($expiredIds) {
            $this->doDelete($expiredIds);
        }
        foreach ($byLifetime as $lifetime => $values) {
            try {
                $e = $this->doSave($values, $lifetime);
            } catch (\Exception $e) {
            }
            if (true === $e || array() === $e) {
                continue;
            }
            if (is_array($e) || 1 === count($values)) {
                foreach (is_array($e) ? $e : array_keys($values) as $id) {
                    $ok = false;
                    $v = $values[$id];
                    $type = is_object($v) ? get_class($v) : gettype($v);
                    CacheItem::log($this->logger, 'Failed to save key "{key}" ({type})', array('key' => substr($id, strlen($this->namespace)), 'type' => $type, 'exception' => $e instanceof \Exception ? $e : null));
                }
            } else {
                foreach ($values as $id => $v) {
                    $retry[$lifetime][] = $id;
                }
            }
        }

        // 当整体保存失败，采用单个循环保存
        foreach ($retry as $lifetime => $ids) {
            foreach ($ids as $id) {
                try {
                    $v = $byLifetime[$lifetime][$id];
                    $e = $this->doSave(array($id => $v), $lifetime);
                } catch (\Exception $e) {
                }
                if (true === $e || array() === $e) {
                    continue;
                }
                $ok = false;
                $type = is_object($v) ? get_class($v) : gettype($v);
                CacheItem::log($this->logger, 'Failed to save key "{key}" ({type})', array('key' => substr($id, strlen($this->namespace)), 'type' => $type, 'exception' => $e instanceof \Exception ? $e : null));
            }
        }

        return $ok;
    }

    public function __destruct()
    {
        if ($this->deferred) {
            $this->commit();
        }
    }

    /**
     * 反序列化，主要是为了异常处理
     */
    protected static function unserialize($value)
    {
        if ('b:0;' === $value) {
            return false;
        }
        $unserializeCallbackHandler = ini_set('unserialize_callback_func', __CLASS__.'::handleUnserializeCallback');
        try {
            if (false !== $value = unserialize($value)) {
                return $value;
            }
            throw new \DomainException('Failed to unserialize cached value');
        } catch (\Error $e) {
            throw new \ErrorException($e->getMessage(), $e->getCode(), E_ERROR, $e->getFile(), $e->getLine());
        } finally {
            ini_set('unserialize_callback_func', $unserializeCallbackHandler);
        }
    }

    private function getId($key)
    {
        //验证键是否合规
        CacheItem::validateKey($key);

        if (null === $this->maxIdLength) {
            return $this->namespace.$key;
        }
        if (strlen($id = $this->namespace.$key) > $this->maxIdLength) {
            $id = $this->namespace.substr_replace(base64_encode(hash('sha256', $key, true)), ':', -22);
        }

        return $id;
    }

    //yield生成器；一次一个占用内存；生成器是一个iterator ;循环获取每一个值
    private function generateItems($items, &$keys)
    {
        $f = $this->createCacheItem;

        try {
            foreach ($items as $id => $value) {
                $key = $keys[$id];
                unset($keys[$id]);
                yield $key => $f($key, $value, true);
            }
        } catch (\Exception $e) {
            CacheItem::log($this->logger, 'Failed to fetch requested items', array('keys' => array_values($keys), 'exception' => $e));
        }

        foreach ($keys as $key) {
            yield $key => $f($key, null, false);
        }
    }

    /**
     * 设置反序列化失败回调函数
     */
    public static function handleUnserializeCallback($class)
    {
        throw new \DomainException('Class not found: '.$class);
    }
}
