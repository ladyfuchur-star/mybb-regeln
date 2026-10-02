<?php
define('IN_MYBB', 1);
define('THIS_SCRIPT', 'rpg_rules.php');
$templatelist = 'ladytime_rules_page';
require_once __DIR__.'/global.php';
if (!function_exists('ladytime_rules_is_installed') || !ladytime_rules_is_installed()) { error_no_permission(); }
$rules_title = ladytime_rules_e(ladytime_rules_setting('title'));
add_breadcrumb($rules_title, 'rpg_rules.php');
$rules_intro = nl2br(ladytime_rules_e(ladytime_rules_setting('intro')));
$rules_navigation = '<table class="tborder" border="0" cellspacing="'.(int)$theme['borderwidth'].'" cellpadding="'.(int)$theme['tablespace'].'"><tr><td class="thead"><strong>Regelbereiche</strong></td></tr>';
$query = $db->simple_select('ladytime_rules_sections','*','visible=1',array('order_by'=>'sortorder, id'));
$sections = array();
while ($section = $db->fetch_array($query)) { $sections[(int)$section['id']] = $section; }
$selected = $mybb->get_input('id', MyBB::INPUT_INT);
if (!isset($sections[$selected]) || $sections[$selected]['link'] !== '') {
    $selected = 0;
    foreach ($sections as $id=>$section) { if ($section['link'] === '') { $selected = $id; break; } }
}
$category = null;
foreach ($sections as $id=>$section) {
    if ($category !== $section['category']) {
        $category = $section['category'];
        $rules_navigation .= '<tr><td class="tcat"><strong>'.ladytime_rules_e($category).'</strong></td></tr>';
    }
    $external = $section['link'] !== '' && ladytime_rules_valid_link($section['link']);
    $href = $external ? $section['link'] : 'rpg_rules.php?id='.$id;
    $extra = $external ? ' target="_blank" rel="noopener noreferrer"' : ($id === $selected ? ' aria-current="page"' : '');
    $rules_navigation .= '<tr><td class="trow1"><a href="'.ladytime_rules_e($href).'"'.$extra.'>'.ladytime_rules_e($section['icon'].' '.$section['title']).($external ? ' ↗' : '').'</a></td></tr>';
}
$rules_navigation .= '</table>';
$rules_section_title = $selected ? ladytime_rules_e($sections[$selected]['title']) : 'Noch keine Regeltexte';
// Only ACP users with explicit permission can write this trusted HTML.
$rules_content = $selected ? $sections[$selected]['body'] : 'Das Team bereitet die Regeln noch vor.';
$rules_updates = '<table id="aenderungen" class="tborder" border="0" cellspacing="'.(int)$theme['borderwidth'].'" cellpadding="'.(int)$theme['tablespace'].'"><tr><td class="thead"><strong>Änderungen am Regelwerk</strong></td></tr>';
$query = $db->simple_select('ladytime_rules_updates','*','',array('order_by'=>'id','order_dir'=>'DESC','limit'=>5));
if (!$db->num_rows($query)) { $rules_updates .= '<tr><td class="trow1">Noch keine Änderungen veröffentlicht.</td></tr>'; }
while ($update = $db->fetch_array($query)) {
    $rules_updates .= '<tr><td class="trow1"><span class="smalltext">'.my_date($mybb->settings['dateformat'], $update['created_at']).' · Fassung '.(int)$update['id'].'</span><br />'.ladytime_rules_e($update['summary']).'</td></tr>';
}
$rules_updates .= '</table>';
eval('$page = "'.$templates->get('ladytime_rules_page').'";');
output_page($page);
