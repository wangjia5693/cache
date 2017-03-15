<?php

/**
 * Author: wangjia <wangj01@lizi.com>
 * Created by Administrator on 2017/3/14.
 *
 */

namespace Cache\Adapter;

/**
 * Interface for invalidating cached items using tags.
 */
interface TagAwareAdapterInterface extends AdapterInterface
{
    /**
     * Invalidates cached items using tags.
     *
     * @param string[] $tags An array of tags to invalidate
     *
     * @return bool True on success
     */
    public function invalidateTags(array $tags);
}
