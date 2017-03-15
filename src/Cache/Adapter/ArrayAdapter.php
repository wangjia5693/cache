<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cache\Adapter;

use Cache\CacheItem;

/**
 * 0 代表把缓存元素地“未定义”地存储（如，只到当前PHP进程结束为止）
 * 如果是true，存入缓存的值将先被序列化再存入
 */
class ArrayAdapter implements AdapterInterface
{

    private $storeSerialized;
    private $values = array();
    private $expiries = array();
    private $createCacheItem;

    protected $logger;

    /**
     * Sets a logger.
     */
    public function setLogger( $logger)
    {
        $this->logger = $logger;
    }
    /**
     * @param int  $defaultLifetime
     * @param bool $storeSerialized Disabling serialization can lead to cache corruptions when storing mutable values but increases performance otherwise
     */
    public function __construct($defaultLifetime = 0, $storeSerialized = true)
    {
        $this->storeSerialized = $storeSerialized;
        $this->createCacheItem = \Closure::bind(
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
    }

    /**
     * {@inheritdoc}
     */
    public function getItem($key)
    {
        $isHit = $this->hasItem($key);
        try {
            if (!$isHit) {
                $this->values[$key] = $value = null;
            } elseif (!$this->storeSerialized) {
                $value = $this->values[$key];
            } elseif ('b:0;' === $value = $this->values[$key]) {
                $value = false;
            } elseif (false === $value = unserialize($value)) {
                $this->values[$key] = $value = null;
                $isHit = false;
            }
        } catch (\Exception $e) {
            CacheItem::log($this->logger, 'Failed to unserialize key "{key}"', array('key' => $key, 'exception' => $e));
            $this->values[$key] = $value = null;
            $isHit = false;
        }
        $f = $this->createCacheItem;

        return $f($key, $value, $isHit);
    }

    /**
     * {@inheritdoc}
     */
    public function getItems(array $keys = array())
    {
        foreach ($keys as $key) {
            CacheItem::validateKey($key);
        }

        return $this->generateItems($keys, time());
    }

    /**
     * Returns all cached values, with cache miss as null.
     *
     * @return array
     */
    public function getValues()
    {
        return $this->values;
    }

    /**
     * {@inheritdoc}
     */
    public function hasItem($key)
    {
        CacheItem::validateKey($key);

        return isset($this->expiries[$key]) && ($this->expiries[$key] >= time() || !$this->deleteItem($key));
    }

    /**
     * {@inheritdoc}
     */
    public function clear()
    {
        $this->values = $this->expiries = array();

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteItem($key)
    {
        CacheItem::validateKey($key);

        unset($this->values[$key], $this->expiries[$key]);

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteItems(array $keys)
    {
        foreach ($keys as $key) {
            $this->deleteItem($key);
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function save( $item)
    {
        if (!$item instanceof CacheItem) {
            return false;
        }
        $item = (array) $item;
        $key = $item["\0*\0key"];
        $value = $item["\0*\0value"];
        $expiry = $item["\0*\0expiry"];

        if (null !== $expiry && $expiry <= time()) {
            $this->deleteItem($key);

            return true;
        }
        if ($this->storeSerialized) {
            try {
                $value = serialize($value);
            } catch (\Exception $e) {
                $type = is_object($value) ? get_class($value) : gettype($value);
                CacheItem::log($this->logger, 'Failed to save key "{key}" ({type})', array('key' => $key, 'type' => $type, 'exception' => $e));

                return false;
            }
        }
        if (null === $expiry && 0 < $item["\0*\0defaultLifetime"]) {
            $expiry = time() + $item["\0*\0defaultLifetime"];
        }

        $this->values[$key] = $value;
        $this->expiries[$key] = null !== $expiry ? $expiry : PHP_INT_MAX;

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function saveDeferred( $item)
    {
        return $this->save($item);
    }

    /**
     * {@inheritdoc}
     */
    public function commit()
    {
        return true;
    }

    private function generateItems(array $keys, $now)
    {
        $f = $this->createCacheItem;

        foreach ($keys as $i => $key) {
            try {
                if (!$isHit = isset($this->expiries[$key]) && ($this->expiries[$key] >= $now || !$this->deleteItem($key))) {
                    $this->values[$key] = $value = null;
                } elseif (!$this->storeSerialized) {
                    $value = $this->values[$key];
                } elseif ('b:0;' === $value = $this->values[$key]) {
                    $value = false;
                } elseif (false === $value = unserialize($value)) {
                    $this->values[$key] = $value = null;
                    $isHit = false;
                }
            } catch (\Exception $e) {
                CacheItem::log($this->logger, 'Failed to unserialize key "{key}"', array('key' => $key, 'exception' => $e));
                $this->values[$key] = $value = null;
                $isHit = false;
            }
            unset($keys[$i]);

            yield $key => $f($key, $value, $isHit);
        }

        foreach ($keys as $key) {
            yield $key => $f($key, null, false);
        }
    }
}