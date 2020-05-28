<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://doc.hyperf.io
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf-cloud/hyperf/blob/master/LICENSE
 */
namespace Naixiaoxin\HyperfIdeHelper;

class Eloquent
{
    public static function make()
    {
        $alias = new Alias('\\Hyperf\\DbConnection\\Model\\Model', 'Eloquent');
        $alias->addClass('\\Hyperf\\Database\\Model\\Builder');
        $alias->addClass('\\Hyperf\\Database\\Query\\Builder');

        $block = "namespace { \r\n";
        $block .= "  class Eloquent extends \\Hyperf\\DbConnection\\Model\\Model { \r\n";
        foreach ($alias->getMethods() as $method) {
            $return = $method->shouldReturn() ? 'return ' : '';
            $block .= '    ' . trim($method->getDocComment('    ')) . "\r\n";
            $block .= "    public static function {$method->getName()}({$method->getParamsWithDefault()}){ \r\n";
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
