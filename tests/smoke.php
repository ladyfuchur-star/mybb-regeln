<?php
/** Isolated integration test. Never include the production config or global.php. */
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) { return false; }
    throw new RuntimeException($message.' at '.$file.':'.$line);
});
define('IN_MYBB', 1);
define('TIME_NOW', time());
define('TABLE_PREFIX', 'test_');
function get_execution_time() { return microtime(true); }
function format_time_duration($time) { return (string)$time; }
function my_strtoupper($text) { return strtoupper($text); }
function validate_utf8_string($text, $encode=true, $allow4=true) { if (!mb_check_encoding($text,'UTF-8')) { throw new RuntimeException('Invalid UTF-8 test input'); } return $text; }
$core = getenv('MYBB_CORE');
if (!$core || !is_file($core.'/inc/db_base.php')) { die("Set MYBB_CORE to a MyBB 1.8 source directory.\n"); }
$database = getenv('RULES_TEST_DB');
if (!$database || !preg_match('/^codex_rules_test_[a-z0-9_]+$/', $database)) { die("Use a dedicated codex_rules_test_* database.\n"); }
require $core.'/inc/db_base.php';
require $core.'/inc/db_mysqli.php';
$mybb = new MyBB;
$db = new DB_MySQLi;
$db->connect(array('hostname'=>'localhost','username'=>getenv('RULES_TEST_USER') ?: 'root','password'=>getenv('RULES_TEST_PASSWORD') ?: '', 'database'=>$database, 'encoding'=>'utf8mb4'));
$db->set_table_prefix(TABLE_PREFIX);
$db->write_query('CREATE TABLE IF NOT EXISTS test_settinggroups (gid int AUTO_INCREMENT PRIMARY KEY, name varchar(100), title varchar(255), description text, disporder int, isdefault int)');
$db->write_query('CREATE TABLE IF NOT EXISTS test_settings (sid int AUTO_INCREMENT PRIMARY KEY, name varchar(100), title varchar(255), description text, optionscode text, value text, disporder int, gid int)');
$db->write_query('CREATE TABLE IF NOT EXISTS test_templates (tid int AUTO_INCREMENT PRIMARY KEY, title varchar(100), template mediumtext, sid int, version varchar(20), dateline int)');
class MyBB {
    const INPUT_INT = 1;
    public $debug_mode = false;
    public $settings = array('bburl'=>'https://example.org');
    public $input = array();
    public $request_method = 'post';
    function get_input($key, $type=0) { $value = isset($this->input[$key]) ? $this->input[$key] : ''; return $type === 1 ? (int)$value : (string)$value; }
}
class TestPlugins { function add_hook($hook,$callback) {} }
class TestTemplates {
    public $cache = array('member_register'=>'<table>{$hiddencaptcha}</table>','index'=>'{$header}<main>Index</main>');
    function get($name,$one=0,$two=0) { return $this->cache[$name]; }
}
class TestHandler {
    public $language_prefix = 'user';
    public $errors = array();
    function set_error($error) { $this->errors[]=$error; }
}
function htmlspecialchars_uni($value) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function rebuild_settings() {
    global $db,$mybb;
    $q=$db->simple_select('settings','name,value');
    while($row=$db->fetch_array($q)) { $mybb->settings[$row['name']]=$row['value']; }
}
function check($condition,$message) { if(!$condition) { throw new RuntimeException('FAIL: '.$message); } echo 'PASS: '.$message."\n"; }
$mybb=new MyBB; $plugins=new TestPlugins; $templates=new TestTemplates; $lang=new stdClass;
require __DIR__.'/../upload/inc/plugins/ladytime_rules.php';
if (ladytime_rules_is_installed()) { ladytime_rules_uninstall(); }
ladytime_rules_install();
check(ladytime_rules_is_installed(),'schema installation');
check((int)$db->fetch_field($db->simple_select('ladytime_rules_sections','COUNT(*) AS n'),'n')===2,'neutral default sections');
check(ladytime_rules_revision()===0,'initial revision');
ladytime_rules_register();
check(strpos($templates->cache['member_register'],'{$ladytime_rules_agreement}')!==false,'register template integration');
ladytime_rules_register();
check(substr_count($templates->cache['member_register'],'{$ladytime_rules_agreement}')===1,'no duplicated agreement');
check(strpos($ladytime_rules_agreement,'type="checkbox"')!==false && strpos($ladytime_rules_agreement,'\\"')===false,'valid unescaped checkbox markup');
$mybb->input=array('action'=>'do_register'); $handler=new TestHandler;
ladytime_rules_validate($handler); check(count($handler->errors)===1,'registration rejects missing consent');
$mybb->input['ladytime_rules_accept']=1; $handler=new TestHandler;
ladytime_rules_validate($handler); check(count($handler->errors)===1,'registration rejects missing revision');
$mybb->input['ladytime_rules_revision']=0; $handler=new TestHandler;
ladytime_rules_validate($handler); check(!$handler->errors,'current consent accepted');
$user_info=array('uid'=>123); ladytime_rules_record();
check((int)$db->fetch_field($db->simple_select('ladytime_rules_acceptances','revision','uid=123'),'revision')===0,'consent recorded');
$db->insert_query('ladytime_rules_updates',array('summary'=>'Test <update>','created_at'=>TIME_NOW,'expires_at'=>TIME_NOW+3600));
$handler=new TestHandler; ladytime_rules_validate($handler); check(count($handler->errors)===1,'stale consent rejected after update');
ladytime_rules_register(); check(strpos($ladytime_rules_agreement,'checked="checked"')===false,'stale checkbox is unchecked');
ladytime_rules_index(); check(strpos($ladytime_rules_alert,'&lt;update&gt;')!==false,'alert text escaped');
check(substr_count($templates->cache['index'],'{$ladytime_rules_alert}')===1,'index integration');
$db->update_query('ladytime_rules_updates',array('expires_at'=>TIME_NOW-1));
ladytime_rules_index(); check($ladytime_rules_alert==='','expired index notice hidden');
check(!ladytime_rules_valid_link('javascript:alert(1)') && !ladytime_rules_valid_link('//example.org') && ladytime_rules_valid_link('https://example.org/rules'),'link protocol validation');
$source=ladytime_rules_page_template();
$headerinclude=$header=$footer=$rules_title=$rules_intro=$rules_navigation=$rules_section_title=$rules_content=$rules_updates='demo';
$theme=array('borderwidth'=>1,'tablespace'=>6); $mybb->settings['bbname']='Test';
$source=str_replace(array('\\', '"', "\0"),array('\\\\','\\"',''),$source);
eval('$rendered="'.$source.'";');
check(strpos($rendered,'<main')!==false,'page template evaluates');
ladytime_rules_deactivate(); check(ladytime_rules_is_installed(),'deactivation preserves data');
ladytime_rules_activate(); check(ladytime_rules_is_installed(),'reactivation preserves data');
ladytime_rules_uninstall(); check(!ladytime_rules_is_installed(),'uninstall removes plugin tables');
check((int)$db->fetch_field($db->simple_select('settings','COUNT(*) AS n'),'n')===0,'uninstall removes settings');
echo "ALL TESTS PASSED\n";
