<?php

/**
 * Author: wangjia <wangj01@lizi.com>
 * Created by Administrator on 2017/3/14.
 *
 */

namespace Cache;

use Cache\Exception\InvalidArgumentException;

/**
 *最终类;适配器最终获取数据方法来源于此
*/
final class CacheItem
{
    public $key;
    public $value;
    public $isHit;
    public $expiry;
    public $defaultLifetime;
    public $tags = array();
    public $innerItem;
    public $poolHash;

    /**
     * 获取键
     */
    public function getKey()
    {
        return $this->key;
    }

    /**
     * 获取值
     */
    public function get()
    {
        return $this->value;
    }

    /**
     * 是否存在
     */
    public function isHit()
    {
        return $this->isHit;
    }

    /**
     * 设置值
     */
    public function set($value)
    {
        $this->value = $value;

        return $this;
    }

    /**
     * 设置失效
     */
    public function expiresAt($expiration)
    {
        if (null === $expiration) {
            $this->expiry = $this->defaultLifetime > 0 ? time() + $this->defaultLifetime : null;
        } elseif ($expiration instanceof \DateTimeInterface) {
            $this->expiry = (int) $expiration->format('U');
        } else {
            throw new InvalidArgumentException(sprintf('Expiration date must implement DateTimeInterface or be null, "%s" given', is_object($expiration) ? get_class($expiration) : gettype($expiration)));
        }

        return $this;
    }

    /**
     * 设置失效
     */
    public function expiresAfter($time)
    {
        if (null === $time) {
            $this->expiry = $this->defaultLifetime > 0 ? time() + $this->defaultLifetime : null;
        } elseif ($time instanceof \DateInterval) {
            $this->expiry = (int) \DateTime::createFromFormat('U', time())->add($time)->format('U');
        } elseif (is_int($time)) {
            $this->expiry = $time + time();
        } else {
            throw new InvalidArgumentException(sprintf('Expiration date must be an integer, a DateInterval or null, "%s" given', is_object($time) ? get_class($time) : gettype($time)));
        }

        return $this;
    }

    /**
     * 为缓存打标签
     * 当业务涉及多方，任意一方变化，缓存失效；
     *
     */
    public function tag($tags)
    {
        if (!is_array($tags)) {
            $tags = array($tags);
        }
        foreach ($tags as $tag) {
            if (!is_string($tag)) {
                throw new InvalidArgumentException(sprintf('Cache tag must be string, "%s" given', is_object($tag) ? get_class($tag) : gettype($tag)));
            }
            if (isset($this->tags[$tag])) {
                continue;
            }
            if (!isset($tag[0])) {
                throw new InvalidArgumentException('Cache tag length must be greater than zero');
            }
            if (false !== strpbrk($tag, '{}()/\@:')) {
                throw new InvalidArgumentException(sprintf('Cache tag "%s" contains reserved characters {}()/\@:', $tag));
            }
            $this->tags[$tag] = $tag;
        }

        return $this;
    }

    /**
     * 验证键，必须为字符串，不能为空，不能含有特殊字符
     */
    public static function validateKey($key)
    {
        if (!is_string($key)) {
            throw new InvalidArgumentException(sprintf('Cache key must be string, "%s" given', is_object($key) ? get_class($key) : gettype($key)));
        }
        if (!isset($key[0])) {
            throw new InvalidArgumentException('Cache key length must be greater than zero');
        }
        if (false !== strpbrk($key, '{}()/\@:')) {
            throw new InvalidArgumentException(sprintf('Cache key "%s" contains reserved characters {}()/\@:', $key));
        }
    }

    /**
     * 日志服务
     */
    public static function log( $logger = null, $message, $context = array())
    {
        if ($logger) {
            $logger->warning($message, $context);
        } else {
            $replace = array();
            foreach ($context as $k => $v) {
                if (is_scalar($v)) {
                    $replace['{'.$k.'}'] = $v;
                }
            }
            @trigger_error(strtr($message, $replace), E_USER_WARNING);
        }
    }
}
