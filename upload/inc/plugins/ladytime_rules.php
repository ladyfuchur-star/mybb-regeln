<?php
/** ladytime – RPG-Regelwerk für MyBB 1.8. */
if (!defined('IN_MYBB')) { die('Direct access denied.'); }

$plugins->add_hook('admin_config_menu', 'ladytime_rules_menu');
$plugins->add_hook('admin_config_action_handler', 'ladytime_rules_actions');
$plugins->add_hook('admin_config_permissions', 'ladytime_rules_permissions');
$plugins->add_hook('member_register_start', 'ladytime_rules_register');
$plugins->add_hook('datahandler_user_validate', 'ladytime_rules_validate');
$plugins->add_hook('member_do_register_end', 'ladytime_rules_record');
$plugins->add_hook('index_start', 'ladytime_rules_index');

function ladytime_rules_info()
{
    return array('name'=>'ladytime – RPG-Regelwerk für MyBB', 'description'=>'Eigene Regelkategorien, Inhalte und Links; Zustimmung bei der Registrierung und Hinweise auf Änderungen. Verwaltung: Konfiguration → RPG-Regelwerk.', 'website'=>'', 'author'=>'ladytime', 'authorsite'=>'', 'version'=>'1.0.0-beta.1', 'compatibility'=>'18*');
}
function ladytime_rules_is_installed()
{
    global $db;
    return $db->table_exists('ladytime_rules_sections');
}
function ladytime_rules_install()
{
    global $db;
    if (ladytime_rules_is_installed()) { return; }
    $collation = $db->build_create_table_collation();
    $db->write_query('CREATE TABLE '.TABLE_PREFIX.'ladytime_rules_sections (
        id int unsigned NOT NULL AUTO_INCREMENT,
        category varchar(120) NOT NULL DEFAULT \'Allgemein\',
        title varchar(160) NOT NULL,
        icon varchar(12) NOT NULL DEFAULT \'\',
        body mediumtext NOT NULL,
        link varchar(500) NOT NULL DEFAULT \'\',
        sortorder int NOT NULL DEFAULT 0,
        visible tinyint NOT NULL DEFAULT 1,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB '.$collation);
    $db->write_query('CREATE TABLE '.TABLE_PREFIX.'ladytime_rules_updates (
        id int unsigned NOT NULL AUTO_INCREMENT,
        summary varchar(255) NOT NULL,
        created_at int unsigned NOT NULL,
        expires_at int unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB '.$collation);
    $db->write_query('CREATE TABLE '.TABLE_PREFIX.'ladytime_rules_acceptances (
        uid int unsigned NOT NULL,
        revision int unsigned NOT NULL DEFAULT 0,
        accepted_at int unsigned NOT NULL,
        PRIMARY KEY (uid)
    ) ENGINE=InnoDB '.$collation);
    $gid = $db->insert_query('settinggroups', array('name'=>'ladytime_rules', 'title'=>'RPG-Regelwerk – ladytime', 'description'=>'Inhalte und Zustimmungen: Konfiguration → RPG-Regelwerk.', 'disporder'=>60, 'isdefault'=>0));
    $settings = array(
        'title'=>array('Seitentitel','text','Regeln'),
        'intro'=>array('Einleitung (reiner Text)','textarea','Hier findest du die Regeln unseres Rollenspielforums.'),
        'consent'=>array('Text der Pflichtzustimmung','textarea','Ich habe die verlinkten Forumsregeln gelesen und akzeptiere sie.'),
        'alert_hours'=>array('Indexhinweis: Dauer in Stunden (0 = aus)','numeric','6')
    );
    $order = 0;
    foreach ($settings as $key=>$item) {
        $db->insert_query('settings', array('name'=>'ladytime_rules_'.$key, 'title'=>$db->escape_string($item[0]), 'description'=>'', 'optionscode'=>$item[1], 'value'=>$db->escape_string($item[2]), 'disporder'=>++$order, 'gid'=>$gid));
    }
    foreach (array('Allgemein'=>'Forumsregeln', 'Rollenspiel'=>'Schreibregeln') as $category=>$title) {
        $db->insert_query('ladytime_rules_sections', array('category'=>$category, 'title'=>$title, 'body'=>'Hier eigenen Regeltext eintragen.', 'icon'=>'', 'link'=>'', 'sortorder'=>++$order, 'visible'=>1));
    }
    $db->insert_query('templates', array('title'=>'ladytime_rules_page', 'template'=>$db->escape_string(ladytime_rules_page_template()), 'sid'=>-2, 'version'=>1800, 'dateline'=>TIME_NOW));
    rebuild_settings();
}
function ladytime_rules_activate() { /* Hooks use runtime template insertion; no theme is overwritten. */ }
function ladytime_rules_deactivate() { /* Data and templates remain available for reactivation. */ }
function ladytime_rules_uninstall()
{
    global $db;
    foreach (array('sections','updates','acceptances') as $table) {
        if ($db->table_exists('ladytime_rules_'.$table)) { $db->drop_table('ladytime_rules_'.$table); }
    }
    $db->delete_query('settings', "name LIKE 'ladytime\\_rules\\_%'");
    $db->delete_query('settinggroups', "name='ladytime_rules'");
    $db->delete_query('templates', "title='ladytime_rules_page'");
    rebuild_settings();
}
function ladytime_rules_menu(&$items)
{
    $items[] = array('id'=>'ladytime_rules', 'title'=>'RPG-Regelwerk', 'link'=>'index.php?module=config-ladytime_rules');
}
function ladytime_rules_actions(&$actions)
{
    $actions['ladytime_rules'] = array('active'=>'ladytime_rules','file'=>'ladytime_rules.php');
}
function ladytime_rules_permissions(&$permissions)
{
    $permissions['ladytime_rules'] = 'Kann das RPG-Regelwerk und Zustimmungen verwalten?';
}
function ladytime_rules_e($text) { return htmlspecialchars_uni((string)$text); }
function ladytime_rules_setting($key)
{
    global $mybb;
    return isset($mybb->settings['ladytime_rules_'.$key]) ? $mybb->settings['ladytime_rules_'.$key] : '';
}
function ladytime_rules_revision()
{
    global $db;
    return (int)$db->fetch_field($db->simple_select('ladytime_rules_updates','MAX(id) AS revision'), 'revision');
}
function ladytime_rules_url()
{
    global $mybb;
    return rtrim($mybb->settings['bburl'], '/').'/rpg_rules.php';
}
function ladytime_rules_register()
{
    global $templates, $mybb, $ladytime_rules_agreement;
    $revision = ladytime_rules_revision();
    $checked = $mybb->get_input('ladytime_rules_accept', MyBB::INPUT_INT) === 1 && $mybb->get_input('ladytime_rules_revision', MyBB::INPUT_INT) === $revision ? ' checked="checked"' : '';
    $ladytime_rules_agreement = '<tr><td colspan="2" class="trow2"><fieldset><legend>Forumsregeln</legend><p><a href="'.ladytime_rules_e(ladytime_rules_url()).'" target="_blank" rel="noopener">Regeln lesen (neuer Tab)</a></p><label><input type="checkbox" class="checkbox" name="ladytime_rules_accept" value="1" required="required"'.$checked.' /> '.ladytime_rules_e(ladytime_rules_setting('consent')).'</label><input type="hidden" name="ladytime_rules_revision" value="'.$revision.'" /></fieldset></td></tr>';
    $source = $templates->get('member_register', 0, 0);
    if (strpos($source, '{$ladytime_rules_agreement}') === false) {
        $templates->cache['member_register'] = str_replace('{$hiddencaptcha}', '{$ladytime_rules_agreement}{$hiddencaptcha}', $source);
    }
}
function ladytime_rules_validate(&$handler)
{
    global $mybb, $lang;
    if ($mybb->request_method !== 'post' || $mybb->get_input('action') !== 'do_register') { return; }
    $message = '';
    if ($mybb->get_input('ladytime_rules_accept', MyBB::INPUT_INT) !== 1) {
        $message = 'Bitte lies und akzeptiere die verlinkten Forumsregeln.';
    } elseif (!isset($mybb->input['ladytime_rules_revision']) || $mybb->get_input('ladytime_rules_revision', MyBB::INPUT_INT) !== ladytime_rules_revision()) {
        $message = 'Die Regeln wurden inzwischen geändert. Bitte lies sie erneut und bestätige die aktuelle Fassung.';
    }
    if ($message !== '') {
        $key = $handler->language_prefix.'_ladytime_rules_consent';
        $lang->$key = $message;
        $handler->set_error('ladytime_rules_consent');
    }
}
function ladytime_rules_record()
{
    global $db, $mybb, $user_info;
    if (empty($user_info['uid']) || $mybb->get_input('ladytime_rules_accept', MyBB::INPUT_INT) !== 1) { return; }
    $db->replace_query('ladytime_rules_acceptances', array('uid'=>(int)$user_info['uid'], 'revision'=>$mybb->get_input('ladytime_rules_revision', MyBB::INPUT_INT), 'accepted_at'=>TIME_NOW), 'uid');
}
function ladytime_rules_index()
{
    global $db, $templates, $ladytime_rules_alert;
    $ladytime_rules_alert = '';
    $update = $db->fetch_array($db->simple_select('ladytime_rules_updates','*','expires_at > '.TIME_NOW, array('order_by'=>'id','order_dir'=>'DESC','limit'=>1)));
    if (!$update) { return; }
    $ladytime_rules_alert = '<div class="pm_alert" role="status"><a href="'.ladytime_rules_e(ladytime_rules_url()).'#aenderungen"><strong>Neues im Regelwerk:</strong> '.ladytime_rules_e($update['summary']).'</a></div><br />';
    $source = $templates->get('index', 0, 0);
    if (strpos($source, '{$ladytime_rules_alert}') === false) {
        $templates->cache['index'] = str_replace('{$header}', '{$header}{$ladytime_rules_alert}', $source);
    }
}
function ladytime_rules_valid_link($url)
{
    return $url === '' || (filter_var($url, FILTER_VALIDATE_URL) && in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), array('https','http'), true));
}
function ladytime_rules_page_template()
{
    return '<html><head><title>{$rules_title} - {$mybb->settings[\'bbname\']}</title>{$headerinclude}<style>.ladytime-rules{display:flex;gap:16px;align-items:flex-start}.ladytime-rules-nav{flex:0 0 220px}.ladytime-rules-main{flex:1;min-width:0}.ladytime-rules-text{overflow-wrap:anywhere}.ladytime-rules-text img{max-width:100%;height:auto}.ladytime-rules-nav a{display:block;padding:5px 0}@media(max-width:700px){.ladytime-rules{display:block}.ladytime-rules-nav{margin-bottom:16px}}</style></head><body>{$header}<div class="ladytime-rules"><nav class="ladytime-rules-nav" aria-label="Regelbereiche">{$rules_navigation}</nav><main class="ladytime-rules-main"><table class="tborder" border="0" cellspacing="{$theme[\'borderwidth\']}" cellpadding="{$theme[\'tablespace\']}"><tr><td class="thead"><strong>{$rules_title}</strong></td></tr><tr><td class="trow1">{$rules_intro}</td></tr><tr><td class="tcat"><strong>{$rules_section_title}</strong></td></tr><tr><td class="trow1 ladytime-rules-text">{$rules_content}</td></tr></table><br />{$rules_updates}</main></div>{$footer}</body></html>';
}
