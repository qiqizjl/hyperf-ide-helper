<?php

declare(strict_types=1);
/**
 *
 *
 * @author    耐小心 <i@naixiaoixn.com>
 * @time      2020/3/27 1:51 上午
 *
 * @copyright 2020 耐小心
 */

namespace Naixiaoxin\HyperfIdeHelper\Command;

use Hyperf\Command\Command;
use Hyperf\Utils\ApplicationContext;
use Hyperf\Utils\Filesystem\Filesystem;
use Barryvdh\Reflection\DocBlock;
use Barryvdh\Reflection\DocBlock\Context;
use Barryvdh\Reflection\DocBlock\Serializer as DocBlockSerializer;
use Barryvdh\Reflection\DocBlock\Tag;
use Naixiaoxin\HyperfIdeHelper\Alias;

class Eloquent extends Command
{

    protected $name = "ide-helper:eloquent";

    public function handle()
    {

        //
    }


}