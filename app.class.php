<?php
/**
 * QsMail POP3 Server
 * 基于Swoole的POP3邮件服务器
 *
 * @author      青石 <www@qs5.org>
 * @copyright   QsMail POP3 Server
 */

/**
 * 应用功能类 这里的代码支持热更新
 */
class App extends POP3_Server
{
    /**
     * 程序版本号
     * @var string
     */
    static private $ver = '0.1.1718.1a';

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
