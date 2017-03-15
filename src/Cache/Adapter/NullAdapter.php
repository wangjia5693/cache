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
 * 是一个特殊的 cache adapter 用于关闭缓存（它扔掉存在它上面的所有元素，并且对所有读取和存储动作返回 false）。
 * 这个适配器主要是对测试有用
*/

class NullAdapter implements AdapterInterface
{
    private $createCacheItem;

    public function __construct()
    {
        $this->createCacheItem = \Closure::bind(
            function ($key) {
                $item = new CacheItem();
                $item->key = $key;
                $item->isHit = false;

                return $item;
            },
            $this,
            CacheItem::class
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getItem($key)
    {
        $f = $this->createCacheItem;

        return $f($key);
    }

    /**
     * {@inheritdoc}
     */
    public function getItems(array $keys = array())
    {
        return $this->generateItems($keys);
    }

    /**
     * {@inheritdoc}
     */
    public function hasItem($key)
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function clear()
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteItem($key)
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteItems(array $keys)
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function save( $item)
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function saveDeferred( $item)
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function commit()
    {
        return false;
    }

    private function generateItems(array $keys)
    {
        $f = $this->createCacheItem;

        foreach ($keys as $key) {
            yield $key => $f($key);
        }
    }
}
