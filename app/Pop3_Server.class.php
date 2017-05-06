<?php
/**
 * QsMail Mail Server
 * 基于Swoole的POP3邮件服务器
 *
 * @author      青石 <www@qs5.org>
 * @copyright   QsMail POP3 Server
 */

/**
 * POP3 服务端 功能模块
 */
class Pop3_Server
{
    /**
     * 程序应用版本号
     * @var string
     */
    private $ver = '0.1.1718.p4b';

    /**
     * 邮件处理应用
     * @var Mail_App
     */
    private $App;

    /**
     * 创建时初始化
     * @param Mail_App $App [description]
     */
    function __construct(Mail_App $App)
    {
        // 输出调试记录
        IS_DEBUG && printf("[Pop3_Server] Create Success\n");

        // 保存App对象
        $this->App = $App;
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
        $regEx = '#(?:(?<s>[\'"])?(?<v>.+?)?(?:(?<!\\\\)\k<s>)|(?<u>[^\'"\s]+))#';
        if(!preg_match_all($regEx, $str, $exp_list)) return false;
        $cmd = array();
        foreach ($exp_list['s'] as $id => $s) {
            $cmd[] = empty($s) ? $exp_list['u'][$id] : $exp_list['v'][$id];
        }
        return $cmd;
    }

    /**
     * 生成返回的字符串数据
     * @param  string $value [description]
     * @return [type]        [description]
     */
    private function asData($status = false, $msg = null, $close = false)
    {
        // 数组就拆分出来
        is_array($status) && extract($status);

        // 先处理状态头
        $ret_msg = $status ? '+OK' : '-ERR';

        // 状态为失败且没有错误原因
        $ret_msg.= empty($msg) ? "\r\n" : " {$msg}\r\n";

        return $close ? array('msg' => $ret_msg, 'close' => $close) : $ret_msg;
    }

    /**
     * 更新邮件列表
     * @param  [type] &$user_data [description]
     * @return [type]             [description]
     */
    private function upMailList(&$user_data)
    {
        // 重新整理邮件列表
        $mail_list = array();
        $i = 1;
        $size_count = 0;
        foreach ($user_data['mail_list'] as $mid => $mail_info) {
            // 标记为删除的邮件不在加入
            if(empty($mail_info['delete'])){
                $mail_list[$i++] = $mail_info;
                $size_count+= intval($mail_info['size']);
            }
        }

        // 更新信息
        $user_data['mail_list'] = $mail_list;
        $user_data['size_count'] = $size_count;
        $user_data['mail_count'] = count($mail_list);

        return $user_data;
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
        // 设置用户状态
        $user_data['status'] = 'AUTHORIZATION';    // 用户状态 TRANSACTION UPDATE

        // 设置返回消息
        $ret_data = array(
            'status' => true,
            'msg'    => sprintf(
                "Hello [%s], Welcome To QsMail POP3 Server!",
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
    public function onReceive(Mail_Server $mail, $fd, $data, &$user_data)
    {
        // 只取出第一条命令处理
        $dataRow = explode("\r\n", $data)['0'];

        // 解析命令行
        $command = $this->exp_command($dataRow);

        // 解析是否成功
        if(!$command || !isset($command['0'])) return false;

        // 生成命令处理方法名
        $com_name = strtolower(array_shift($command));
        $method_name = "cmd_{$com_name}";

        // 方法存在就调用
        $ret_data = array('status' => false, 'msg' => 'Unrecognized command');
        if(method_exists($this, $method_name)){
            $ret_data = $this->$method_name($command, $user_data);
        }

        return $this->asData($ret_data);
    }

    //**** 确认状态 ****//

    /**
     * 处理 退出功能
     * @param  [type] $param    [description]
     * @param  [type] &$user_data [description]
     * @return [type]             [description]
     */
    private function cmd_quit($param, &$user_data)
    {
        // 如果为操作状态则执行删除
        if( 'TRANSACTION' == $user_data['status'] ){
            // 删除的邮件列表
            $dele_list = array();
            foreach ($user_data['dele_list'] as $mail_info) {
                $dele_list[] = $mail_info['mail_id'];
            }
            // 走删除方法
            $del_ret = $this->App->delete($dele_list);
        }

        return array('status' => true, 'msg' => 'Bye!', 'close' => true);
    }

    /**
     * 处理 用户账号
     * @param  [type] $param    [description]
     * @param  [type] &$user_data [description]
     * @return [type]             [description]
     */
    private function cmd_user($param, &$user_data)
    {
        // 非认证状态则返回错误
        if( 'AUTHORIZATION' != $user_data['status'] ){
            return array('status' => false, 'msg' => 'Unrecognized command');
        }

        // 没设置用户名
        if(!isset($param['0'])){
            return array('status' => false, 'msg' => 'Missing argument');
        }

        // 保存用户名
        $user_data['user_name'] = $param['0'];

        return array('status' => true, 'msg' => '[AUTH] Core mail');
    }

    /**
     * 处理 用户密码
     * @param  [type] $param    [description]
     * @param  [type] &$user_data [description]
     * @return [type]             [description]
     */
    private function cmd_pass($param, &$user_data)
    {
        // // 调试模式特殊处理
        // if(IS_DEBUG){
        //     $user_data['user_name'] = 'imdong';
        //     $param['0'] = '123456';
        //     $user_data['status'] = 'AUTHORIZATION';
        // }

        // 非认证状态则返回错误
        if( 'AUTHORIZATION' != $user_data['status'] ){
            return array('status' => false, 'msg' => 'Unrecognized command');
        }

        // 没设置密码
        if(!isset($param['0'])){
            return array('status' => false, 'msg' => 'Missing argument');
        }

        // 没设置用户名
        if(!isset($user_data['user_name'])){
            return array('status' => false, 'msg' => '[AUTH] Invalid login');
        }

        // 保存登录密码
        $user_data['user_pass'] = $param['0'];

        // 验证账号密码信息
        $login_ret = $this->App->login($user_data['user_name'], $user_data['user_pass']);
        if($login_ret){
            // 保存登录时间
            $user_data['login_time'] = date('Y-m-d H:i:s');
            // 修改状态
            $user_data['status'] = 'TRANSACTION';
            // 获取邮件列表
            $mail_info = $this->App->get_mail_list($user_data['user_name']);
            // 组织保存数据
            $user_data['mail_list']  = $mail_info['mail_list'];
            $user_data['dele_list']  = array(); // 待删除邮件列表
            // 更新邮件列表
            $user_data = $this->upMailList($user_data);
            // 准备发送到客户端的消息
            $ret_data = array(
                'status' => true,
                'msg'    => sprintf(
                    '%s message(s) [%s byte(s)]',
                    $user_data['mail_count'],
                    $user_data['size_count']
                )
            );
        } else {
            // 发送到客户端的消息
            $ret_data = array('status' => false, 'msg' => '[AUTH] Invalid login');
        }

        return $ret_data;
    }

    //**** 操作状态 ****//

    /**
     * 与客户端确认连接
     * @param  [type] $param    [description]
     * @param  [type] &$user_data [description]
     * @return [type]             [description]
     */
    private function cmd_noop($param, &$user_data)
    {
        // 非操作状态则返回错误
        if( 'TRANSACTION' != $user_data['status'] ){
            return array('status' => false, 'msg' => 'Unrecognized command');
        }

        // 返回消息
        return array(
            'status' => true,
            'msg'    => 'Success'
        );
    }

    /**
     * 处理 状态消息
     * @param  [type] $param    [description]
     * @param  [type] &$user_data [description]
     * @return [type]             [description]
     */
    private function cmd_stat($param, &$user_data)
    {
        // 非操作状态则返回错误
        if( 'TRANSACTION' != $user_data['status'] ){
            return array('status' => false, 'msg' => 'Unrecognized command');
        }

        // 更新邮件列表
        $user_data = $this->upMailList($user_data);

        // 返回消息
        return array(
            'status' => true,
            'msg'    => sprintf(
                '%s %s',
                $user_data['mail_count'],
                $user_data['size_count']
            )
        );
    }

    /**
     * 处理 获取邮件列表
     * @param  [type] $param    [description]
     * @param  [type] &$user_data [description]
     * @return [type]             [description]
     */
    private function cmd_list($param, &$user_data)
    {
        // 非操作状态则返回错误
        if( 'TRANSACTION' != $user_data['status'] ){
            return array('status' => false, 'msg' => 'Unrecognized command');
        }

        // 更新邮件列表
        $user_data = $this->upMailList($user_data);

        // 未传递参数 则获取列表
        if(!isset($param['0'])){
            $msg_body = '';
            foreach ($user_data['mail_list'] as $mid => $mail_info) {
                $msg_body.= sprintf("%s %s\r\n", $mid, $mail_info['size']);
            }
            $ret_data = array(
                'status' => true,
                'msg'    => sprintf(
                    "%s message(s) [%s byte(s)]\r\n%s.",
                    $user_data['mail_count'],
                    $user_data['size_count'],
                    $msg_body
                )
            );
        } else
        // 传递的参数是整数 且大于0
        if(is_numeric($param['0']) && $param['0'] > 0){
            // 生成邮件mid
            $mid = $param['0'];
            // 判断这个邮件是否存在
            if(isset($user_data['mail_list'][$mid])){
                $ret_data = array(
                    'status' => true,
                    'msg' => sprintf(
                        '%s %s',
                        $mid,
                        $user_data['mail_list'][$mid]['size']
                    )
                );
            } else {
                // 邮件不存在
                $ret_data = array(
                    'status' => false,
                    'msg' => sprintf(
                        'no such message, only %s messages in mailbox',
                        $user_data['mail_count']
                    )
                );
            }
        } else {
            // 参数不正确
            $ret_data = array('status' => false, 'msg' => 'Syntax error');
        }

        // 返回消息
        return $ret_data;
    }

    /**
     * 处理 获取邮件ID列表
     * @param  [type] $param    [description]
     * @param  [type] &$user_data [description]
     * @return [type]             [description]
     */
    private function cmd_uidl($param, &$user_data)
    {
        // 非操作状态则返回错误
        if( 'TRANSACTION' != $user_data['status'] ){
            return array('status' => false, 'msg' => 'Unrecognized command');
        }

        // 更新邮件列表
        $user_data = $this->upMailList($user_data);

        // 未传递参数 则获取列表
        if(!isset($param['0'])){
            $msg_body = '';
            foreach ($user_data['mail_list'] as $mid => $mail_info) {
                $msg_body.= sprintf("%s %s\r\n", $mid, $mail_info['mail_id']);
            }
            $ret_data = array(
                'status' => true,
                'msg'    => sprintf(
                    "%s message(s) [%s byte(s)]\r\n%s.",
                    $user_data['mail_count'],
                    $user_data['size_count'],
                    $msg_body
                )
            );
        } else
        // 传递的参数是整数 且大于0
        if(is_numeric($param['0']) && $param['0'] > 0){
            // 生成邮件mid
            $mid = $param['0'];
            // 判断这个邮件是否存在
            if(isset($user_data['mail_list'][$mid])){
                $ret_data = array(
                    'status' => true,
                    'msg' => sprintf(
                        '%s %s',
                        $mid,
                        $user_data['mail_list'][$mid]['mail_id']
                    )
                );
            } else {
                // 邮件不存在
                $ret_data = array(
                    'status' => false,
                    'msg' => sprintf(
                        'no such message, only %s messages in mailbox',
                        $user_data['mail_count']
                    )
                );
            }
        } else {
            // 参数不正确
            $ret_data = array('status' => false, 'msg' => 'Syntax error');
        }

        // 返回消息
        return $ret_data;
    }

    /**
     * 处理 标记删除邮件
     * @param  [type] $param    [description]
     * @param  [type] &$user_data [description]
     * @return [type]             [description]
     */
    private function cmd_dele($param, &$user_data)
    {
        // 非操作状态则返回错误
        if( 'TRANSACTION' != $user_data['status'] ){
            return array('status' => false, 'msg' => 'Unrecognized command');
        }

        // 传递的参数是整数 且大于0
        if(isset($param['0']) && is_numeric($param['0']) && $param['0'] > 0){
            // 生成邮件mid
            $mid = $param['0'];
            // 判断这个邮件是否存在
            if(isset($user_data['mail_list'][$mid])){
                // 将邮件加入待删除列表
                $user_data['dele_list'][$mid] = $user_data['mail_list'][$mid];
                // 将邮件标记为删除
                $user_data['mail_list'][$mid]['delete'] = true;
                // 拼接返回消息
                $ret_data = array(
                    'status' => true,
                    'msg'    => sprintf(
                        "message %s deleted",
                        count($user_data['dele_list'])
                    )
                );
            } else {
                // 邮件不存在
                $ret_data = array(
                    'status' => false,
                    'msg' => sprintf(
                        'no such message, only %s messages in mailbox',
                        $user_data['mail_count']
                    )
                );
            }
        } else {
            // 参数不正确
            $ret_data = array('status' => false, 'msg' => 'Syntax error');
        }

        return $ret_data;
    }

    /**
     * 处理 复位操作
     * @param  [type] $param    [description]
     * @param  [type] &$user_data [description]
     * @return [type]             [description]
     */
    private function cmd_rset($param, &$user_data)
    {
        // 非操作状态则返回错误
        if( 'TRANSACTION' != $user_data['status'] ){
            return array('status' => false, 'msg' => 'Unrecognized command');
        }

        // 重新生成邮件列表
        $mail_list = array();
        // 正常的邮件列表
        foreach ($user_data['mail_list'] as $mail_info) {
            $mail_list[$mail_info['mid']] = $mail_info;
        }
        // 删除的邮件列表
        foreach ($user_data['dele_list'] as $mail_info) {
            // 去掉删除标记
            // unset($mail_info['delete']);
            $mail_list[$mail_info['mid']] = $mail_info;
        }
        // 写回列表
        $user_data['mail_list'] = $mail_list;

        // 更新邮件列表
        $user_data = $this->upMailList($user_data);

        // 返回消息
        return array(
            'status' => true,
            'msg'    => sprintf(
                '%s %s',
                $user_data['mail_count'],
                $user_data['size_count']
            )
        );
    }

    /**
     * 处理 获取邮件正文
     * @param  [type] $param    [description]
     * @param  [type] &$user_data [description]
     * @return [type]             [description]
     */
    private function cmd_retr($param, &$user_data)
    {
        // 非操作状态则返回错误
        if( 'TRANSACTION' != $user_data['status'] ){
            return array('status' => false, 'msg' => 'Unrecognized command');
        }

        // 更新邮件列表
        $user_data = $this->upMailList($user_data);

        // 传递的参数是整数 且大于0
        if(isset($param['0']) && is_numeric($param['0']) && $param['0'] > 0){
            // 生成邮件mid
            $mid = $param['0'];
            // 判断这个邮件是否存在
            if(isset($user_data['mail_list'][$mid])){
                // 获取邮件正文
                $mail_body = $this->App->get_mail_body($user_data['mail_list'][$mid]['mail_id']);
                // 拼接返回消息
                $ret_data = array(
                    'status' => true,
                    'msg'    => sprintf(
                        "%s octets\r\n%s\r\n.",
                        $user_data['mail_list'][$mid]['size'],
                        $mail_body
                    )
                );
            } else {
                // 邮件不存在
                $ret_data = array(
                    'status' => false,
                    'msg' => sprintf(
                        'no such message, only %s messages in mailbox',
                        $user_data['mail_count']
                    )
                );
            }
        } else {
            // 参数不正确
            $ret_data = array('status' => false, 'msg' => 'Syntax error');
        }

        return $ret_data;
    }

    /**
     * 处理 获取邮件前几行
     * @param  [type] $param    [description]
     * @param  [type] &$user_data [description]
     * @return [type]             [description]
     */
    private function cmd_top($param, &$user_data)
    {
        // 非操作状态则返回错误
        if( 'TRANSACTION' != $user_data['status'] ){
            return array('status' => false, 'msg' => 'Unrecognized command');
        }

        // 更新邮件列表
        $user_data = $this->upMailList($user_data);

        // 传递的参数是整数 且大于0
        if(isset($param['0']) && is_numeric($param['0']) && $param['0'] > 0
            && isset($param['1']) && is_numeric($param['1']))
        {
            // 生成邮件mid
            $mid = $param['0'];
            $num = $param['1'];

            // 判断这个邮件是否存在
            if(isset($user_data['mail_list'][$mid])){
                // 获取邮件正文
                $mail_body = $this->App->get_mail_body($user_data['mail_list'][$mid]['mail_id']);

                // 获取邮件前几行 从头信息后面找
                $posi = stripos($mail_body, "\r\n\r\n") + 4;

                // 取出前几行
                for ($i=0; $i < $num; $i++) {
                    $posi = stripos($mail_body, "\r\n", $posi) + 2;
                    // 如果没找到 说明到结尾了
                    if($posi === false) break;
                }

                // 截取字符串
                $posi && $mail_body = substr($mail_body, 0, $posi);

                // 拼接返回消息
                $ret_data = array(
                    'status' => true,
                    'msg'    => sprintf(
                        "%s octets\r\n%s.",
                        $user_data['mail_list'][$mid]['size'],
                        $mail_body
                    )
                );
            } else {
                // 邮件不存在
                $ret_data = array(
                    'status' => false,
                    'msg' => sprintf(
                        'no such message, only %s messages in mailbox',
                        $user_data['mail_count']
                    )
                );
            }
        } else {
            // 参数不正确
            $ret_data = array('status' => false, 'msg' => 'Syntax error');
        }

        return $ret_data;
    }
}
