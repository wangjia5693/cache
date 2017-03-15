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
 * 允许把任何缓存适配器转换成一个tag-aware adapter
 * 接收两个参数：第一个是用于存储元素的cache adapter，
 * 第二个可选参数是用于缓存tags的adapter。
 * 这可以令你，比如，使用文件系统或数据库来存储巨大元素，
 * 以及使用一个用于tags的Redis缓存，以实现超高速的失效检查：
 *
 * 缓存了的评论，被关联到三个不同的标签，标签可以用于使相关的个体（评论）失效
 * // 商品评价的HTML结构改变，全部评论失效：
$cache->invalidateTags('reviews');

// a special sale is enabled in the store:
// invalidate anything related to products
// 开启了商城中的一个特卖活动，令相关商品的所有信息失效：
$cache->invalidateTags('products');

// the data of the product #123 has changed:
// invalidate anything related to that product
// id为123的产品，其数据发生改变，令其所有关联信息失效：
$cache->invalidateTags('product-123');

// a major store update is being deployed:
// invalidate all the information related to products and reviews
// 商城进行升级部署，商品以及评论，全部信息统统失效：
$cache->invalidateTags(['products', 'reviews']);

// after invalidating any of the previous tags, the item is no longer
// available in the cache:
// 在无效化前述任何一个标签时，相关item在缓存系统中将不再可用：
$cache->getItem('reviews-'.$reviewId)->isHit();  // returns false
 */
class TagAwareAdapter implements TagAwareAdapterInterface
{
    const TAGS_PREFIX = "\0tags\0";

    private $itemsAdapter;
    private $deferred = array();
    private $createCacheItem;
    private $getTagsByKey;
    private $invalidateTags;
    private $tagsAdapter;

    public function __construct(AdapterInterface $itemsAdapter, AdapterInterface $tagsAdapter = null)
    {
        $this->itemsAdapter = $itemsAdapter;
        $this->tagsAdapter = $tagsAdapter ?: $itemsAdapter;
        $this->createCacheItem = \Closure::bind(
            function ($key, $value, CacheItem $protoItem) {
                $item = new CacheItem();
                $item->key = $key;
                $item->value = $value;
                $item->isHit = false;
                $item->defaultLifetime = $protoItem->defaultLifetime;
                $item->expiry = $protoItem->expiry;
                $item->innerItem = $protoItem->innerItem;
                $item->poolHash = $protoItem->poolHash;

                return $item;
            },
            null,
            CacheItem::class
        );
        $this->getTagsByKey = \Closure::bind(
            function ($deferred) {
                $tagsByKey = array();
                foreach ($deferred as $key => $item) {
                    $tagsByKey[$key] = $item->tags;
                }

                return $tagsByKey;
            },
            null,
            CacheItem::class
        );
        $this->invalidateTags = \Closure::bind(
            function (AdapterInterface $tagsAdapter, array $tags) {
                foreach ($tagsAdapter->getItems($tags) as $v) {
                    $v->set(1 + (int) $v->get());
                    $v->defaultLifetime = 0;
                    $v->expiry = null;
                    $tagsAdapter->saveDeferred($v);
                }

                return $tagsAdapter->commit();
            },
            null,
            CacheItem::class
        );
    }

    /**
     * {@inheritdoc}
     */
    public function invalidateTags(array $tags)
    {
        foreach ($tags as $k => $tag) {
            if ('' !== $tag && is_string($tag)) {
                $tags[$k] = $tag.static::TAGS_PREFIX;
            }
        }
        $f = $this->invalidateTags;

        return $f($this->tagsAdapter, $tags);
    }

    /**
     * {@inheritdoc}
     */
    public function hasItem($key)
    {
        if ($this->deferred) {
            $this->commit();
        }
        if (!$this->itemsAdapter->hasItem($key)) {
            return false;
        }
        if (!$itemTags = $this->itemsAdapter->getItem(static::TAGS_PREFIX.$key)->get()) {
            return true;
        }

        foreach ($this->getTagVersions(array($itemTags)) as $tag => $version) {
            if ($itemTags[$tag] !== $version) {
                return false;
            }
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getItem($key)
    {
        foreach ($this->getItems(array($key)) as $item) {
            return $item;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getItems(array $keys = array())
    {
        if ($this->deferred) {
            $this->commit();
        }
        $tagKeys = array();

        foreach ($keys as $key) {
            if ('' !== $key && is_string($key)) {
                $key = static::TAGS_PREFIX.$key;
                $tagKeys[$key] = $key;
            }
        }

        try {
            $items = $this->itemsAdapter->getItems($tagKeys + $keys);
        } catch (\InvalidArgumentException $e) {
            $this->itemsAdapter->getItems($keys); // Should throw an exception

            throw $e;
        }

        return $this->generateItems($items, $tagKeys);
    }

    /**
     * {@inheritdoc}
     */
    public function clear()
    {
        $this->deferred = array();

        return $this->itemsAdapter->clear();
    }

    /**
     * {@inheritdoc}
     */
    public function deleteItem($key)
    {
        return $this->deleteItems(array($key));
    }

    /**
     * {@inheritdoc}
     */
    public function deleteItems(array $keys)
    {
        foreach ($keys as $key) {
            if ('' !== $key && is_string($key)) {
                $keys[] = static::TAGS_PREFIX.$key;
            }
        }

        return $this->itemsAdapter->deleteItems($keys);
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
     * {@inheritdoc}
     */
    public function commit()
    {
        $ok = true;

        if ($this->deferred) {
            $items = $this->deferred;
            foreach ($items as $key => $item) {
                if (!$this->itemsAdapter->saveDeferred($item)) {
                    unset($this->deferred[$key]);
                    $ok = false;
                }
            }

            $f = $this->getTagsByKey;
            $tagsByKey = $f($items);
            $deletedTags = $this->deferred = array();
            $tagVersions = $this->getTagVersions($tagsByKey);
            $f = $this->createCacheItem;

            foreach ($tagsByKey as $key => $tags) {
                if ($tags) {
                    $this->itemsAdapter->saveDeferred($f(static::TAGS_PREFIX.$key, array_intersect_key($tagVersions, $tags), $items[$key]));
                } else {
                    $deletedTags[] = static::TAGS_PREFIX.$key;
                }
            }
            if ($deletedTags) {
                $this->itemsAdapter->deleteItems($deletedTags);
            }
        }

        return $this->itemsAdapter->commit() && $ok;
    }

    public function __destruct()
    {
        $this->commit();
    }

    private function generateItems($items, array $tagKeys)
    {
        $bufferedItems = $itemTags = $invalidKeys = array();
        $f = $this->createCacheItem;

        foreach ($items as $key => $item) {
            if (!$tagKeys) {
                yield $key => isset($invalidKeys[self::TAGS_PREFIX.$key]) ? $f($key, null, $item) : $item;
                continue;
            }
            if (!isset($tagKeys[$key])) {
                $bufferedItems[$key] = $item;
                continue;
            }

            unset($tagKeys[$key]);
            if ($tags = $item->get()) {
                $itemTags[$key] = $tags;
            }
            if (!$tagKeys) {
                $tagVersions = $this->getTagVersions($itemTags);

                foreach ($itemTags as $key => $tags) {
                    foreach ($tags as $tag => $version) {
                        if ($tagVersions[$tag] !== $version) {
                            $invalidKeys[$key] = true;
                            continue 2;
                        }
                    }
                }
                $itemTags = $tagVersions = $tagKeys = null;

                foreach ($bufferedItems as $key => $item) {
                    yield $key => isset($invalidKeys[self::TAGS_PREFIX.$key]) ? $f($key, null, $item) : $item;
                }
                $bufferedItems = null;
            }
        }
    }

    private function getTagVersions(array $tagsByKey)
    {
        $tagVersions = array();

        foreach ($tagsByKey as $tags) {
            $tagVersions += $tags;
        }

        if ($tagVersions) {
            $tags = array();
            foreach ($tagVersions as $tag => $version) {
                $tagVersions[$tag] = $tag.static::TAGS_PREFIX;
                $tags[$tag.static::TAGS_PREFIX] = $tag;
            }
            foreach ($this->tagsAdapter->getItems($tagVersions) as $tag => $version) {
                $tagVersions[$tags[$tag]] = $version->get() ?: 0;
            }
        }

        return $tagVersions;
    }
}
