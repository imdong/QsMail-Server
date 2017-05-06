-- Adminer 4.2.5 MySQL dump

SET NAMES utf8;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;
SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';

DROP TABLE IF EXISTS `mail_list`;
CREATE TABLE `mail_list` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `mail_id` varchar(64) NOT NULL COMMENT '邮件唯一ID',
  `mail_from` varchar(128) NOT NULL COMMENT '发件人地址',
  `from_ip` varchar(15) NOT NULL COMMENT '发件服务器IP',
  `from_mark` varchar(32) NOT NULL COMMENT '发信服务器标识',
  `receive_mail` varchar(128) NOT NULL COMMENT '收件人地址',
  `receive_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '接收邮件的时间',
  `owner` varchar(32) NOT NULL COMMENT '邮件所有者',
  `body` text NOT NULL COMMENT '邮件正文',
  `size` int(11) NOT NULL COMMENT '邮件大小',
  `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '邮件状态 0 正常 1 删除',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='邮件列表';


DROP TABLE IF EXISTS `user_list`;
CREATE TABLE `user_list` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `username` varchar(32) NOT NULL COMMENT '用户名账号',
  `password` varchar(32) NOT NULL COMMENT '用户密码',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='用户数据表';


-- 2017-05-06 09:30:37
