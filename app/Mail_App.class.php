<?php
/**
 * QsMail Server
 * 基于Swoole的邮件服务器
 *
 * @author      青石 <www@qs5.org>
 * @copyright   QsMail Server
 */

/**
 * 应用功能类 这里的代码支持热更新
 */
class Mail_App
{
    /**
     * Swoole服务对象
     * @var swoole_server
     */
    private $serv;

    /**
     * 配置信息
     * @var array
     */
    private $config;

    /**
     * 数据库连接
     * @var MysqliDb
     */
    private $db;

    /**
     * 数据库操作锁
     * @var [type]
     */
    private $db_look;

    /**
     * 禁止转换类
     * @var JinZhi
     */
    private $jinzhi;

    /**
     * 程序应用版本号
     * @var string
     */
    private $ver = '0.1.1718.a1c';

    /**
     * 初始化函数
     */
    function __construct($serv)
    {
        // 保存服务对象
        $this->serv = $serv;

        // 引入配置文件
        $this->config = require ROOT_PATH . 'config.php';

        // 重新设置自动加载类
        spl_autoload_register(function($class_name)
        {
            // 定义可以加载的文件列表
            $file_list = array(
                APP_ROOT . "class/{$class_name}.class.php",
                APP_ROOT . "lib/{$class_name}.class.php",
                APP_ROOT . "class/{$class_name}.php",
                APP_ROOT . "lib/{$class_name}.php",
            );
            // 挨个测试是否可以加载
            foreach ($file_list as $file_name) {
                if(file_exists($file_name)){
                    include $file_name;
                    return true;
                }
            }
            return false;
        });

        // 连接到数据库
        $this->db_connect();

        // 创建数据库操作锁
        $this->db_look = new swoole_lock(SWOOLE_SPINLOCK);

        // 创建进制转换
        $this->jinzhi = new JinZhi();
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
     * 连接到数据库
     * @return [type] [description]
     */
    public function db_connect()
    {
        // 连接到数据库
        $this->db = new MySqli(
            $this->config['DB_CONFIG']['host'],
            $this->config['DB_CONFIG']['username'],
            $this->config['DB_CONFIG']['password'],
            $this->config['DB_CONFIG']['db']
        );

        // 连接失败则报错
        if($this->db->connect_error){
            printf("MySQLi Connect Error(%s): %s\n",
                $this->db->connect_errno,
                $this->db->connect_error
            );
            // 退出线程
            $this->serv->shutdown();
        }

        return true;
    }

    /**
     *
     * @param  string $sql 查询语句
     * @return [type]      [description]
     */
    /**
     * 执行一次sql查询
     * @param  string $sql  查询语句
     * @param  string $type 查询类型 如果设置就尝试处理结果
     * @return [type]       [description]
     */
    public function db_query($sql, $type = false)
    {
        // 操作加锁
        $this->db_look->lock();

        // 创建事务

        // 执行sql查询
        $result = $this->db->query($sql);

        // 如果出错是因为超时
        if(!$result && in_array($this->db->errno, array(2006, 2013))){

            // 连接到数据库
            $this->db_connect();

            // 执行查询
            $result = $this->db->query($sql);
        }

        // 查询失败或没设置类型就直接返回
        if(!$result || !$type) {
            // 取消锁
            $this->db_look->unlock();

            // 取消事务

            // 返回结果
            return $result;
        }

        // 判断查询类型
        switch (strtoupper($type)) {
            case 'EXPLAIN': // 不知道
            case 'DESCRIBE':// 不知道
            case 'SHOW':    // 显示所有表信息
            case 'SELECT':  // 从数据库表中获取数据
                // 获取所有数据
                $ret_data = $result->fetch_all(MYSQLI_ASSOC);
                break;
            case 'INSERT':  // 向数据库表中插入数据
                // 返回插入ID
                $ret_data = $this->db->insert_id;
                break;
            case 'UPDATE':  // 更新数据库表中的数据
            case 'DELETE':  // 从数据库表中删除数据
                // 返回操作影响行数
                $ret_data = $this->db->affected_rows;
                break;
            default:
                $ret_data = $result;
                break;
        }

        // 取消锁
        $this->db_look->unlock();

        // 取消事务

        // 返回数据
        return $ret_data;
    }

    /**
     * 用户登录鉴定
     * @param  string $user [description]
     * @param  string $pass [description]
     * @return booled       [description]
     */
    public function login($user, $pass)
    {
        // 校验输入
        $username = explode('@', trim($user))['0'];
        $password = md5($pass);

        // 生成查询Sql
        $sql = sprintf(
            'SELECT * FROM `user_list` WHERE `username` = \'%s\' AND `password` = \'%s\';',
            $username,
            $password
        );
        // 执行查询
        $user_list = $this->db_query($sql);

        // 判断是否有这个用户
        return $user_list->num_rows >= 1;
    }

    /**
     * 解析邮件所有者
     * @param  [type] $email [description]
     * @return [type]        [description]
     */
    public function exp_username($email)
    {
        // 预设返回信息
        $ret_data = array(
            'status' => false,
            'msg'    => 'error'
        );

        // 解析邮件地址
        $regex = '#^(?<user_b>[a-z0-9]{6,})?(?:[^@]+)?@(?<domain>(?:(?<user_a>[a-z0-9]{6,})\.)?(?<domain_main>[a-z0-9\.\-]+\.[a-z]+))$#';
        if(!preg_match($regex, $email, $email_info)){
            $ret_data['msg'] = 'Email addr error';
            return $ret_data;
        }

        // 判断主域名是否为自有
        if(in_array($email_info['domain_main'], $this->config['MY_DOMAIN']) ||
            in_array($email_info['domain'], $this->config['MY_DOMAIN']) )
        {
            // 设置用户名
            $mail_user = empty($email_info['user_a']) ? $email_info['user_b'] : $email_info['user_a'];
        }
        // 非自有则查数据域名归属
        else {
            // 查询是否有绑定这个域名
            $sql = sprintf(
                'SELECT `username` FROM `user_domain` WHERE `domain` = \'%s\';',
                $email_info['domain']
            );

            // 执行查询
            $list = $this->db_query($sql, 'SELECT');

            // 判断是否有结果
            if(isset($list['0'])){
                $mail_user = $list['0']['username'];
            } else {
                $ret_data['msg'] = 'No such domain here';
                return $ret_data;
            }
        }

        // 判断用户是否存在
        $sql = sprintf(
            'SELECT `username` FROM `user_list` WHERE `username` = \'%s\';',
            $mail_user
        );

        // 执行查询
        $list = $this->db_query($sql, 'SELECT');

        // 判断是否有结果
        if(isset($list['0'])){
            $mail_user = $list['0']['username'];
        } else {
            $ret_data['msg'] = 'No such user here';
            return $ret_data;
        }

        return array(
            'status'   => true,
            'username' => $mail_user
        );
    }

    /**
     * 获取邮件列表
     * @param  [type] $username [description]
     * @return [type]           [description]
     */
    public function get_mail_list($username)
    {
        // 生成查询Sql
        $sql = sprintf(
            'SELECT `mail_id`, `size` FROM `mail_list` WHERE `owner` = \'%s\';',
            $username
        );

        // 执行查询
        $list = $this->db_query($sql, 'SELECT');

        // 重新组合数据
        $mail_list = array();
        foreach ($list as $id => $value) {
            $mid = $id + 1;
            $mail_list[$mid] = array(
                'mid'     => $mid,
                'mail_id' => $value['mail_id'],
                'size'    => $value['size']
            );
        }

        return array(
            'mail_list'  => $mail_list,
        );
    }

    /**
     * 获取邮件正文
     * @param  [type] $mail_id [description]
     * @return [type]          [description]
     */
    public function get_mail_body($mail_id)
    {
        // 生成查询Sql
        $sql = sprintf(
            'SELECT `body` FROM `mail_list` WHERE `mail_id` = \'%s\';',
            $mail_id
        );

        // 执行查询
        if(!$sql_ret = $this->db_query($sql)){
            return false;
        }

        // 取出数据
        if(!$mail_info = $sql_ret->fetch_assoc()){
            return false;
        }

        return $mail_info['body'];
    }

    /**
     * 删除邮件
     * @param  array $dele_list 需要删除的邮件ID列表
     * @return integer          成功删除的邮件数量
     */
    public function delete($dele_list)
    {
        // 生成查询Sql
        $sql = sprintf(
            'DELETE FROM `mail_list` WHERE `mail_id` IN (\'%s\')',
            implode('\', \'', $dele_list)
        );

        // 执行并返回结果
        return $this->db_query($sql, 'DELETE');
    }

    /**
     * 保存邮件信息
     * @param  string $mail_from   发件人
     * @param  array  $mail_rect   收件人列表
     * @param  string $mail_data   邮件正文
     * @param  string $client_ip   发信服务器IP
     * @param  string $client_from 发信服务器标记
     * @return [type]              [description]
     */
    public function mailSave($mail_from, $mail_rect, $mail_data, $client_ip, $client_from)
    {
        // 字符串收件人转换为数组
        if(is_string($mail_rect)) $mail_rect = array($mail_rect);

        // 邮件内容编码
        $mail_data   = $this->db->real_escape_string($mail_data);
        $client_from = $this->db->real_escape_string($client_from);

        // SQL语句
        $sql = "INSERT INTO `mail_list` (`mail_id`, `mail_from`, `from_ip`, `from_mark`, `receive_mail`, `body`, `size`, `owner`) VALUES \n";

        // 循环每个收件人
        $sql_value = array();
        foreach ($mail_rect as $rect) {

            // 根据邮件地址解析所有者
            $mail_user = $this->exp_username($rect);

            // 解析是否成功
            if(!$mail_user['status']){
                continue;
            }

            // 邮件ID
            $mail_id = sprintf(
                'D%sF%sM%sZ',
                $this->jinzhi->hex10to64(time()),
                $this->jinzhi->hex16to64(substr(md5("{$mail_from}_{$rect}"), 6, 16)),
                $this->jinzhi->hex16to64(substr(md5("{$mail_data}_" . uniqid(mt_rand(), true)), 6, 16))
            );

            // 转义邮件信息
            $mail_from = $this->db->real_escape_string($mail_from);
            $rect      = $this->db->real_escape_string($rect);

            // 值数组
            $sql_value[] = sprintf(
                "('%s', '%s', '%s','%s', '%s', '%s', '%s', '%s')",
                $mail_id,
                $mail_from,
                $client_ip,
                $client_from,
                $rect,
                $mail_data,
                strlen($mail_data),
                $mail_user['username']
            );
        }

        // // 挂钩事件处理回调
        // if(preg_grep('#@reddit\.com#', $mail_from)){
        //     file_get_contents('http://www.qs5.org/tools/AutoPxls/redditMail.php?mail_id=' . $mail_id);
        // } else if(preg_grep('#@mailgun\.discordapp\.com#', $mail_from)){
        //     file_get_contents('http://www.qs5.org/tools/AutoPxls/discordMail.php?mail_id=' . $mail_id);
        // }

        // 拼接数组
        $sql_value_str = implode(",\n", $sql_value);

        // 拼接 sql语句
        $sql.= $sql_value_str . ';';

        // 插入到数据库
        $ret = $this->db_query($sql, 'UPDATE');

        // 返回状态
        return $ret;
    }

}
