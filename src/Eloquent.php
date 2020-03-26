<?php

declare(strict_types=1);
/**
 *
 *
 * @author    耐小心 <i@naixiaoixn.com>
 * @time      2020/3/27 2:30 上午
 *
 * @copyright 2020 耐小心
 */

namespace Naixiaoxin\HyperfIdeHelper;

class Eloquent{

    public static function make()
    {
        $alias = new Alias("\Hyperf\DbConnection\Model\Model", "Eloquent");
        $alias->addClass("\Hyperf\Database\Model\Builder");
        $alias->addClass("\Hyperf\Database\Query\Builder");

        $block = "namespace { \r\n";
        $block .= "  class Eloquent extends \Hyperf\DbConnection\Model\Model { \r\n";
        foreach ($alias->getMethods() as $method) {
            $return = $method->shouldReturn() ? 'return ' : '';
            $block  .= "    " . trim($method->getDocComment('    ')) . "\r\n";
            $block  .= "    public static function {$method->getName()}({$method->getParamsWithDefault()}){ \r\n";
            if ($method->isInstanceCall()) {
                $block .= "        /** @var {$method->getRoot()} \$instance */ \r\n";
            }
            $block .= "        {$return} {$method->getRootMethodCall()}; \r\n";
            $block .= "     } \r\n";
        }
        $block .= "  } \r\n}";
        return $block;
    }
}