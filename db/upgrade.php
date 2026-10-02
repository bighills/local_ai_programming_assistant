<?php
/**
 * AI 编程助手 - 数据库升级脚本
 *
 * 当前版本为插件的初始发行版本（2026080100），数据库结构完全由
 * db/install.xml 定义，不提供从任何旧版本升级到当前版本的路径。
 * 将来若需要新增数据库变更，请在 version.php 中递增版本号，
 * 并在此文件添加对应的升级块。
 *
 * @package    local_ai_programming_assistant
 * @author     zfl
 */

defined('MOODLE_INTERNAL') || die();

function xmldb_local_ai_programming_assistant_upgrade($oldversion) {
    // 初始发行版本，无任何升级步骤。
    return true;
}
