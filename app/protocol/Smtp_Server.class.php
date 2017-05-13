<?php
/**
 * QsMail Server
 * 基于Swoole的邮件服务器
 *
 * @author      青石 <www@qs5.org>
 * @copyright   QsMail Server
 */

/**
 * SMTP协议支持类
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
    private $mail_app;

    /**
     * 创建时初始化
     * @param Mail_App &$mail_app [description]
     */
    function __construct(Mail_App $mail_app)
    {
        // 保存App对象
        $this->mail_app = $mail_app;
    }

    /**
     * 获取版本号
     * @return [type] [description]
     */
    public function ver()
    {
        return $this->ver;
    }

    //**** 基础功能 ****//

    /**
     * 解析命令行
     * @param  [type] $str [description]
     * @return [type]      [description]
     */
    private function exp_command($str)
    {
        $regex = '#^(?<cmd>[a-zA-Z]{4})(\s(?<msg>[^$]+)?)?$#';
        if(!preg_match($regex, $str, $exp_list)) return false;
        return array(
            'cmd' => $exp_list['cmd'],
            'msg' => empty($exp_list['msg']) ? '' : $exp_list['msg']
        );
    }

    /**
     * 生成返回的字符串数据
     * @param  string $value [description]
     * @return [type]        [description]
     */
    private function asData($status = false, $msg = null)
    {
        // 数组就拆分出来
        is_array($status) && extract($status);

        // 状态为失败且没有错误原因
        $ret_msg = empty($msg) ? "{$status}\r\n" : "{$status} {$msg}\r\n";

        return $ret_msg;
    }

    //**** 事件回调 ****//

    /**
     * 有客户端连接
     * @param  Mail_Server $mail [description]
     * @param  [type]      $fd   [description]
     * @return [type]            [description]
     */
    public function onConnect(Mail_Server $mail, $fd, &$user_data)
    {
        // 初始化用户信息
        $user_data['multi_mode'] = false;   // 是否多行模式
        $user_data['buffer']     = '';      // 初始化缓冲区

        // 设置返回消息
        $ret_data = array(
            'status' => 220,
            'msg'    => sprintf(
                "Hello [%s], Welcome To QsMail SMTP Server!",
                $user_data['username']
            )
        );

        return $this->asData($ret_data);
    }

    /**
     * 客户端断开连接 暂时用不到 所以把名字改掉 免得被调用
     * @param  swoole_server $serv      [description]
     * @param  int           $fd        [description]
     * @param  int           $reactorId [description]
     * @return [type]                   [description]
     */
    public function onClose_(Mail_Server $mail, $fd, &$user_data)
    {
        return false;
    }

    /**
     * 收到客户端消息
     * @param  string $value [description]
     * @return [type]        [description]
     */
    public function onReceive(Mail_Server $mail, $fd, $datas, &$user_data)
    {
        // 始终假设存在粘包问题 拆开每一行单独处理
        $data_rows = explode("\r\n", rtrim($datas));

        // 设置返回消息
        $ret_msg_arr = array();

        // 对每一行依次处理
        foreach ($data_rows as $data) {

            // 判断是否在接收多行数据
            if($user_data['multi_mode']){

                // 先把收到的消息保存的缓冲区
                $user_data['buffer'].= $data . "\r\n";

                // 判断是否收到结束标记
                if(substr($user_data['buffer'], -5) == "\r\n.\r\n"){

                    // 返回单行模式
                    $user_data['multi_mode'] = false;

                    // 删除末尾的结束符号
                    $user_data['buffer'] = substr($user_data['buffer'], 0, strlen($user_data['buffer']) - 5);

                    // 重新调用这个方法
                    $ret_data = $this->{$user_data['method_name']}('', $user_data, $user_data['buffer']);

                    // 清空缓存区
                    $user_data['buffer'] = '';

                    // 保存返回结果
                    $ret_msg_arr[] = $ret_data;
                }
            } else {
                // 空行跳过 \r\n 分割后末尾会有空行
                if($data == '') continue;

                // 解析消息格式
                $command = $this->exp_command($data);

                // 解析失败设置错误
                $command ?: $ret_msg_arr[] = array(
                    'status' => 500,
                    'msg'    => 'Unrecognized command'
                );

                // 生成命令处理方法名
                $com_name = strtolower($command['cmd']);
                $method_name = "cmd_{$com_name}";

                // 方法存在就调用
                $ret_data = array(
                    'status' => 502,
                    'msg' => "Error: command \"{$command['cmd']}\" not implemented"
                );
                if(method_exists($this, $method_name)){
                    // 记住当前方法
                    $user_data['method_name'] = $method_name;
                    // 调用方法
                    $ret_data = $this->{$method_name}($command['msg'], $user_data);
                }

                // 保存返回结果
                $ret_msg_arr[] = $ret_data;
            }
        }

        // 遍历每个返回结果
        $ret_msg = '';
        $is_close = false;
        foreach ($ret_msg_arr as $ret_data) {
            $ret_msg.= $this->asData($ret_data);
            // 设置是否关闭
            if(is_array($ret_data) && !empty($ret_data['close']) && $ret_data['close']){
                $is_close = true;
            }
        }

        return array(
            'msg'   => $ret_msg,
            'close' => $is_close
        );
    }

    //**** 处理方法 ****//

    /**
     * 与客户端确认连接
     * @param  [type] $param    [description]
     * @param  [type] &$user_data [description]
     * @return [type]             [description]
     */
    private function cmd_noop($msg, &$user_data)
    {
        // 返回消息
        return array(
            'status' => 250,
            'msg'    => 'Ok'
        );
    }

    /**
     * 处理 握手
     * 返回一个肯定的消息
     * @param  [type] $param    [description]
     * @param  [type] &$user_data [description]
     * @return [type]             [description]
     */
    private function cmd_helo($msg, &$user_data)
    {
        // 消息为空则返回
        if(empty($msg)){
            return array(
                'status' => 500,
                'msg'    => 'Error: bad syntax'
            );
        }

        // 保存客户端标识
        $user_data['client_from'] = $msg;

        return array(
            'status' => 250,
            'msg'    => "Ok {$user_data['username']}@{$msg}"
        );
    }

    /**
     * 处理 设置发件人信息
     * 开始新的发送请求
     * @param  [type] $param    [description]
     * @param  [type] &$user_data [description]
     * @return [type]             [description]
     */
    private function cmd_mail($msg, &$user_data)
    {
        // 消息为空则返回
        if(empty($msg)){
            return array(
                'status' => 500,
                'msg'    => 'Error: bad syntax'
            );
        }

        // 获取发件人地址
        if(!preg_match('#^FROM:\s*<(?<mail>[^>]+)>#i', $msg, $mail_addr)){
            // 获取发件人地址失败
             $ret_info = array(
                'status' => 501,
                'msg'    => 'Error: mail addr error'
            );
        } else {
            // 保存发件人信息
            $user_data['mail_from'] = trim($mail_addr['mail']);
            // 重置收件人列表
            $user_data['mail_rect'] = array();
            // 回复客户端
            $ret_info = array(
                'status' => 250,
                'msg'    => 'Ok'
            );
        }

        return $ret_info;
    }

    /**
     * 处理 设置收件人信息
     * @param  [type] $param    [description]
     * @param  [type] &$user_data [description]
     * @return [type]             [description]
     */
    private function cmd_rcpt($msg, &$user_data)
    {
        // 消息为空则返回
        if(empty($msg)){
            return array(
                'status' => 500,
                'msg'    => 'Error: bad syntax'
            );
        }

        // 获取收件人地址
        if(!preg_match('#^TO:\s*<(?<mail>[^>]+)>#i', $msg, $mail_addr)){
            // 获取收件人地址失败
            $ret_info = array(
                'status' => 501,
                'msg'    => 'Error'
            );
        } else {
            // 检查收件人归属
            $mail_user = $this->mail_app->exp_username($mail_addr['mail']);
            if(!$mail_user['status']){
                $ret_info = array(
                    'status' => 550,
                    'msg'    => $mail_user['msg']
                );
            } else {
                // 保存收件人信息
                $user_data['mail_rect'][] = trim($mail_addr['mail']);

                // 回复客户端
                $ret_info = array(
                    'status' => 250,
                    'msg'    => 'Ok'
                );
            }
        }
        return $ret_info;
    }

    /**
     * 处理 获取邮件正文
     * @param  [type] $param    [description]
     * @param  [type] &$user_data [description]
     * @return [type]             [description]
     */
    private function cmd_data($msg, &$user_data, $data = null)
    {
        // 没传递 数据则进入 获取状态
        if(empty($data)){

            // 设置接受多行数据
            $user_data['multi_mode'] = true;

            // 清空缓冲区
            $user_data['buffer'] = '';

            // 设置返回信息
            $ret_info = array(
                'status' => 354,
                'msg'    => 'End data with <CR><LF>.<CR><LF>'
            );
        } else {
            $save_ret = true;

            // 走保存邮件流程
            $save_ret = $this->mail_app->mailSave(
                $user_data['mail_from'],
                $user_data['mail_rect'],
                $data,
                $user_data['client_ip'],
                $user_data['client_from']
            );

            // 返回处理结果
            $ret_info = array(
                'status'   => $save_ret ? 250 : 554,
                'msg'    => $save_ret ? 'Ok' : 'Error',
            );
        }

        return $ret_info;
    }

    /**
     * 处理 退出
     * @param  [type] $msg        [description]
     * @param  [type] &$user_data [description]
     * @return [type]             [description]
     */
    private function cmd_quit($msg, &$user_data)
    {
        return array(
            'status' => 221,
            'close'  => true,
            'msg'    => 'Bye'
        );
    }
}
