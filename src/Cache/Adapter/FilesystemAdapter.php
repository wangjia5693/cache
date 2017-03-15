<?php

/**
 * Author: wangjia <wangj01@lizi.com>
 * Created by Administrator on 2017/3/14.
 *
 */

namespace Cache\Adapter;

use Cache\Exception\CacheException;

/**
 * 文件系统缓存
 * 缓存元素所在的缓存主目录中的子目录
 * 0 代表把缓存元素地“未定义”地存储（如，只到文件被删除时为止）
 * 如果没有被指定，将在系统的临时目录中新建一个目录
 */
class FilesystemAdapter extends AbstractAdapter
{
    use FilesystemAdapterTrait;

    public function __construct($namespace = '', $defaultLifetime = 0, $directory = null)
    {
        parent::__construct('', $defaultLifetime);
        $this->init($namespace, $directory);
    }

    /**
     * {@inheritdoc}
     */
    protected function doFetch(array $ids)
    {
        $values = array();
        $now = time();

        foreach ($ids as $id) {
            $file = $this->getFile($id);
            if (!file_exists($file) || !$h = @fopen($file, 'rb')) {
                continue;
            }
            if ($now >= (int) $expiresAt = fgets($h)) {
                fclose($h);
                if (isset($expiresAt[0])) {
                    @unlink($file);
                }
            } else {
                $i = rawurldecode(rtrim(fgets($h)));
                $value = stream_get_contents($h);
                fclose($h);
                if ($i === $id) {
                    $values[$id] = parent::unserialize($value);
                }
            }
        }

        return $values;
    }

    /**
     * {@inheritdoc}
     */
    protected function doHave($id)
    {
        $file = $this->getFile($id);

        return file_exists($file) && (@filemtime($file) > time() || $this->doFetch(array($id)));
    }

    /**
     * {@inheritdoc}
     */
    protected function doSave(array $values, $lifetime)
    {
        $ok = true;
        $expiresAt = time() + ($lifetime ?: 31557600); // 31557600s = 1 year

        foreach ($values as $id => $value) {
            $ok = $this->write($this->getFile($id, true), $expiresAt."\n".rawurlencode($id)."\n".serialize($value), $expiresAt) && $ok;
        }

        if (!$ok && !is_writable($this->directory)) {
            throw new CacheException(sprintf('Cache directory is not writable (%s)', $this->directory));
        }

        return $ok;
    }
}
