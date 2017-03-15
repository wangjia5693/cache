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
 * 适配器接口；
*/
interface AdapterInterface
{
    /**
     *获取单个键值
     */
    public function getItem($key);

    /**
     *获取多个键值
     */
    public function getItems(array $keys = array());


    /**
     * 确认是否有指定的键值.
     */
    public function hasItem($key);

    /**
     * 清除缓存
     */
    public function clear();

    /**
     * 清除指定键值
     */
    public function deleteItem($key);

    /**
     * 清除多个键值
     */
    public function deleteItems(array $keys);

    /**
     * 立即保存
     */
    public function save( $item);

    /**
     * 延迟保存
     */
    public function saveDeferred( $item);

    /**
     * 延迟保存缓存执行
     */
    public function commit();
}
