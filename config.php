<?php
/**
 * QsMail POP3 Server
 * 基于Swoole的POP3邮件服务器
 *
 * @author      青石 <www@qs5.org>
 * @copyright   QsMail POP3 Server
 */

/**
 * 配置信息文件
 */
return array(

    //**** 数据库信息配置 ****//
    'DB_CONFIG' => array(
        'host'     => 'localhost',
        'username' => 'mail',
        'password' => '123456',
        'db'       => 'qsmail',
        'charset'  => 'utf8'
    ),
);
