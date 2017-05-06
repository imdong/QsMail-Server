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

        // 连接到数据库
        $this->db_connect();
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
    }

    /**
     * 执行一次sql查询
     * @param  string $sql 查询语句
     * @return [type]      [description]
     */
    public function db_query($sql)
    {
        // 执行sql查询
        $ret = $this->db->query($sql);

        // 如果出错是因为超时
        if(!$ret && in_array($this->db->errno, array(2006, 2013))){
            // 连接到数据库
            $this->mysqlConnect();
            // 插入到数据库
            $ret = $this->db->query($sql);
        }

        return $ret;
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
        $username = trim($user);
        $password = md5($pass);

        // 生成查询Sql
        $sql = sprintf(
            'SELECT * FROM `user_list` WHERE `username` = \'%s\' AND `password` = \'%s\';',
            $username,
            $password
        );
        // 执行查询
        $userList = $this->db_query($sql);

        // 判断是否有这个用户
        return $userList->num_rows >= 1;
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
        $sql_ret = $this->db_query($sql);

        // 取出数据
        $list = $sql_ret->fetch_all(MYSQLI_ASSOC);

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

}
