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

use Cache\Exception\CacheException;
use Cache\Exception\InvalidArgumentException;


/**
 * 与已经存在的 FilesystemAdapter 非常相似，但是它在当服务器使用Opcache时会有更好的性能。
 * 技巧点在于，当元素被存储时，新的适配器会创建一个在 fetch() 操作过程中被包容的PHP文件。这能让OPcache把那些文件缓存到内存中
 * 但在写入上略慢，因此最适于用在文件不怎么被改变的情况下。
 */
class PhpFilesAdapter extends AbstractAdapter
{
    use FilesystemAdapterTrait;

    private $includeHandler;

    //判定是否开启opcache
    public static function isSupported()
    {
        return function_exists('opcache_compile_file') && ini_get('opcache.enable');
    }

    public function __construct($namespace = '', $defaultLifetime = 0, $directory = null)
    {
        if (!static::isSupported()) {
            throw new CacheException('OPcache is not enabled');
        }
        parent::__construct('', $defaultLifetime);
        $this->init($namespace, $directory);

        $e = new \Exception();
        $this->includeHandler = function () use ($e) { throw $e; };
    }

    /**
     * {@inheritdoc}
     */
    protected function doFetch(array $ids)
    {
        $values = array();
        $now = time();

        set_error_handler($this->includeHandler);
        try {
            foreach ($ids as $id) {
                try {
                    $file = $this->getFile($id);
                    list($expiresAt, $values[$id]) = include $file;
                    if ($now >= $expiresAt) {
                        unset($values[$id]);
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }
        } finally {
            restore_error_handler();
        }

        foreach ($values as $id => $value) {
            if ('N;' === $value) {
                $values[$id] = null;
            } elseif (is_string($value) && isset($value[2]) && ':' === $value[1]) {
                $values[$id] = parent::unserialize($value);
            }
        }

        return $values;
    }

    /**
     * {@inheritdoc}
     */
    protected function doHave($id)
    {
        return (bool) $this->doFetch(array($id));
    }

    /**
     * {@inheritdoc}
     */
    protected function doSave(array $values, $lifetime)
    {
        $ok = true;
        $data = array($lifetime ? time() + $lifetime : PHP_INT_MAX, '');
        $allowCompile = 'cli' !== PHP_SAPI || ini_get('opcache.enable_cli');

        foreach ($values as $key => $value) {
            if (null === $value || is_object($value)) {
                $value = serialize($value);
            } elseif (is_array($value)) {
                $serialized = serialize($value);
                $unserialized = parent::unserialize($serialized);
                // Store arrays serialized if they contain any objects or references
                if ($unserialized !== $value || (false !== strpos($serialized, ';R:') && preg_match('/;R:[1-9]/', $serialized))) {
                    $value = $serialized;
                }
            } elseif (is_string($value)) {
                // Serialize strings if they could be confused with serialized objects or arrays
                if ('N;' === $value || (isset($value[2]) && ':' === $value[1])) {
                    $value = serialize($value);
                }
            } elseif (!is_scalar($value)) {
                throw new InvalidArgumentException(sprintf('Cache key "%s" has non-serializable %s value.', $key, gettype($value)));
            }

            $data[1] = $value;
            $file = $this->getFile($key, true);
            $ok = $this->write($file, '<?php return '.var_export($data, true).';') && $ok;

            if ($allowCompile) {
                @opcache_compile_file($file);
            }
        }

        if (!$ok && !is_writable($this->directory)) {
            throw new CacheException(sprintf('Cache directory is not writable (%s)', $this->directory));
        }

        return $ok;
    }
}
