<?php
/**
 * Created by PhpStorm.
 * User: Administrator
 * Date: 2017/3/14
 * Time: 16:41
 */
define('__ROOT__', str_replace("\\", "/", __DIR__) . '/');
require_once  "vendor/autoload.php";
use Cache\Adapter\FilesystemAdapter;
$cache = new FilesystemAdapter('app.cache',100,__ROOT__);
//$numProducts = $cache->getItems(array('stats.num_product','stats.num_products'));
//$a =  $numProducts->get();
//var_dump($numProducts->current()->get());exit;
//if (!$numProducts->isHit()) {
//    $numProducts->set(array(
//        'category1' => 4711,
//        'category2' => 2387,
//    ));
//    $cache->save($numProducts);
//}else{
//   $a =  $numProducts->get();
//    var_dump($a);
//}
$isDeleted = $cache->deleteItem('stats.num_products');
$numProducts = $cache->getItem('stats.num_products')->isHit();
var_dump($numProducts);
//var_dump($numProducts);exit;