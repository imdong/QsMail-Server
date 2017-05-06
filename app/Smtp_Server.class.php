<?php
/**
 * QsMail Mail Server
 * 基于Swoole的POP3邮件服务器
 *
 * @author      青石 <www@qs5.org>
 * @copyright   QsMail POP3 Server
 */

/**
 * SMTP 服务端 功能模块
 */
class Smtp_Server
{

    /**
     * 程序应用版本号
     * @var string
     */
    private $ver = '0.1.1718.s4b';

    /**
     * 邮件处理应用
     * @var Mail_App
     */
    private $app;

    /**
     * 创建时初始化
     * @param Mail_App &$app [description]
     */
    function __construct(Mail_App $app)
    {
        // 输出调试记录
        IS_DEBUG && printf("[Smtp_Server] Create Success\n");

        // 保存App对象
        $this->app = $app;
    }

    /**
     * 获取版本号
     * @return [type] [description]
     */
    public function ver()
    {
        return $this->ver;
    }

    /**
     * 解析命令行
     * @param  [type] $str [description]
     * @return [type]      [description]
     */
    private function exp_command($str)
    {
        $regEx = '#(?:(?<s>[\'"])?(?<v>.+?)?(?:(?<!\\\\)\k<s>)|(?<u>[^\'"\s]+))#';
        if(!preg_match_all($regEx, $str, $exp_list)) return false;
        $cmd = array();
        foreach ($exp_list['s'] as $id => $s) {
            $cmd[] = empty($s) ? $exp_list['u'][$id] : $exp_list['v'][$id];
        }
        return $cmd;
    }

    /**
     * 有客户端连接
     * @param  Mail_Server $mail [description]
     * @param  [type]      $fd   [description]
     * @return [type]            [description]
     */
    public function onConnect(Mail_Server $mail, $fd, &$user_data)
    {
        $retMsg = sprintf(
            "220 Hello %s, Welcome! - qs5.org\r\n",
            $user_data['username']
        );
        return $retMsg;
    }

    /**
     * 收到客户端消息
     * @param  string $value [description]
     * @return [type]        [description]
     */
    public function onReceive(Mail_Server $mail, $fd, $data)
    {
        printf('(%s) %s: %s', 'Pop3', $fd, $data);
    }


}
