<?php
/**
 * Swoole 热更新功能 简单示例
 *
 * @author      青石 <www@qs5.org>
 * @copyright   Swoole Reload Demo 2017-4-30 09:47:10
 */

/**
 * 应用功能类 这里的代码支持热更新
 */
class App
{
    /**
     * 程序版本号
     * @var string
     */
    static private $ver = '1.0.c170430.b';

    /**
     * 获取版本号
     * @return [type] [description]
     */
    static public function ver()
    {
        return self::$ver;
    }

    /**
     * 获取版本号
     * @return [type] [description]
     */
    static public function cmd_ver()
    {
        return self::$ver . "\r\n";
    }
}
