# QsMail POP3 Server
基于Swoole的POP3邮件服务器

青石 博客 http://www.qs5.org

# 文件使用说明
文件名|文件名功能|备注说明
------|----------|--------
init.php|执行入口文件|
pop3Server.class.php|POP3 Server 基类|核心基础功能
app.class.php|应用功能类|功能性类
run.sh|进程管理脚本|

# run.sh 参数说明
参数名|功能说明
------|--------
start|启动脚本，并后台运行
status|查看运行状态
reload|重新加载脚本 通过这个进行热更新
restart|重新启动 先stop然后start
stop|停止脚本 正常方式停止
kill|强制杀死进程
