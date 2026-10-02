<?php
if (!defined('IN_MYBB') || !defined('IN_ADMINCP')) { die('Direct access denied.'); }
if (!function_exists('ladytime_rules_is_installed') || !ladytime_rules_is_installed()) { error_no_permission(); }
$base = 'index.php?module=config-ladytime_rules';
$action = $mybb->get_input('action');
$id = $mybb->get_input('id', MyBB::INPUT_INT);
$errors = array();
$item = array('category'=>'Allgemein','title'=>'','icon'=>'','body'=>'','link'=>'','sortorder'=>0,'visible'=>1);
if ($id) {
    $item = $db->fetch_array($db->simple_select('ladytime_rules_sections','*','id='.$id));
    if (!$item) { flash_message('Dieser Regelbereich existiert nicht.', 'error'); admin_redirect($base); }
}
if ($mybb->request_method === 'post') {
    verify_post_check($mybb->get_input('my_post_key'));
    if ($action === 'settings') {
        foreach (array('title','intro','consent') as $key) {
            $value = trim($mybb->get_input($key));
            if ($value === '') { $errors[] = 'Bitte alle Textfelder ausfüllen.'; break; }
        }
        if (!$errors) {
            foreach (array('title','intro','consent','alert_hours') as $key) {
                $value = $key === 'alert_hours' ? max(0, min(168, $mybb->get_input($key, MyBB::INPUT_INT))) : trim($mybb->get_input($key));
                $db->update_query('settings', array('value'=>$db->escape_string((string)$value)), "name='ladytime_rules_".$key."'");
            }
            rebuild_settings();
            flash_message('Einstellungen gespeichert.', 'success'); admin_redirect($base.'&action=settings');
        }
    } elseif ($action === 'edit' || $action === 'delete') {
        if ($action === 'delete' && (!$id || $mybb->get_input('confirm', MyBB::INPUT_INT) !== 1)) {
            $errors[] = 'Bitte das Löschen ausdrücklich bestätigen.';
        }
        $summary = trim($mybb->get_input('summary'));
        if ($summary === '' || my_strlen($summary) > 255) { $errors[] = 'Bitte einen kurzen Änderungshinweis mit höchstens 255 Zeichen eingeben.'; }
        if ($action === 'edit') {
            foreach (array('category','title','icon','body','link') as $key) { $item[$key] = trim($mybb->get_input($key)); }
            $item['sortorder'] = $mybb->get_input('sortorder', MyBB::INPUT_INT);
            $item['visible'] = $mybb->get_input('visible', MyBB::INPUT_INT) === 1 ? 1 : 0;
            if ($item['category'] === '' || my_strlen($item['category']) > 120) { $errors[] = 'Kategorie: 1 bis 120 Zeichen.'; }
            if ($item['title'] === '' || my_strlen($item['title']) > 160) { $errors[] = 'Titel: 1 bis 160 Zeichen.'; }
            if (my_strlen($item['icon']) > 12) { $errors[] = 'Bitte nur ein kurzes Symbol verwenden (maximal 12 Zeichen).'; }
            if (strlen($item['link']) > 500 || !ladytime_rules_valid_link($item['link'])) { $errors[] = 'Links müssen vollständige http://- oder https://-Adressen sein.'; }
            if ($item['body'] === '' && $item['link'] === '') { $errors[] = 'Bitte einen Inhalt oder einen Link eintragen.'; }
        }
        if (!$errors) {
            if ($action === 'delete') { $db->delete_query('ladytime_rules_sections', 'id='.$id); }
            else {
                $data = $item;
                unset($data['id']);
                foreach (array('category','title','icon','body','link') as $key) { $data[$key] = $db->escape_string($data[$key]); }
                if ($id) { $db->update_query('ladytime_rules_sections', $data, 'id='.$id); }
                else { $db->insert_query('ladytime_rules_sections', $data); }
            }
            $hours = max(0, min(168, (int)ladytime_rules_setting('alert_hours')));
            $expires = $mybb->get_input('announce', MyBB::INPUT_INT) === 1 && $hours > 0 ? TIME_NOW + $hours * 3600 : 0;
            $db->insert_query('ladytime_rules_updates', array('summary'=>$db->escape_string($summary),'created_at'=>TIME_NOW,'expires_at'=>$expires));
            log_admin_action($id, $action);
            flash_message('Regelwerk gespeichert. Der Änderungshinweis ist auf der Regelseite sichtbar.', 'success'); admin_redirect($base);
        }
    }
}
$page->add_breadcrumb_item('RPG-Regelwerk', $base);
$page->output_header('RPG-Regelwerk – ladytime');
$tabs = array(
    'content'=>array('title'=>'Inhalte & Kategorien','link'=>$base,'description'=>'Eigene Inhaltsfelder oder Links erstellen. Gleiche Kategorienamen bilden eine Gruppe. Die Reihenfolge wird über die Sortierung festgelegt. Es werden keine fertigen Regeltexte mitgeliefert.'),
    'edit'=>array('title'=>'Bereich hinzufügen','link'=>$base.'&action=edit','description'=>'Inhalt, Kategorie und optionales Symbol bearbeiten. Ein Link ersetzt den Inhalt und öffnet sich in einem neuen Tab.'),
    'settings'=>array('title'=>'Einstellungen','link'=>$base.'&action=settings','description'=>'Titel, Einführung, Zustimmungstext und Dauer des kurzen Indexhinweises.'),
    'acceptances'=>array('title'=>'Zustimmungen','link'=>$base.'&action=acceptances','description'=>'Nur für berechtigte Administratoren: Zustimmung neuer Registrierungen mit Zeitpunkt und Regelwerksfassung. Kein Nachweis für bereits vorher registrierte Mitglieder. Keine automatische erneute Zustimmung bei Änderungen.')
);
$page->output_nav_tabs($tabs, isset($tabs[$action]) ? $action : 'content');
if ($errors) { $page->output_inline_error($errors); }
if ($action === 'edit' || $action === 'delete') {
    $form = new Form($base.'&action='.$action.'&id='.$id, 'post');
    $container = new FormContainer($action === 'delete' ? 'Regelbereich löschen' : 'Regelbereich bearbeiten');
    if ($action === 'delete') {
        $container->output_row('Bestätigung', 'Dieser Bereich wird dauerhaft entfernt.', $form->generate_check_box('confirm',1,'„'.ladytime_rules_e($item['title']).'“ löschen'));
    } else {
        $container->output_row('Kategorie', 'Freier Name, z. B. Allgemein oder Rollenspiel. Für eine neue Kategorie einfach einen neuen Namen verwenden.', $form->generate_text_box('category',$item['category']));
        $container->output_row('Titel', '', $form->generate_text_box('title',$item['title']));
        $container->output_row('Symbol (optional)', 'Kurzes Textsymbol, z. B. § oder ✦. Keine Icon-Bibliothek erforderlich.', $form->generate_text_box('icon',$item['icon']));
        $container->output_row('Inhalt', 'HTML ist erlaubt. Nur vertrauenswürdigen Administratoren diese Berechtigung geben. Kein PHP. Ohne HTML sind Zeilenumbrüche als &lt;br&gt; einzutragen.', $form->generate_text_area('body',$item['body'],array('rows'=>14,'cols'=>80)));
        $container->output_row('Link (optional)', 'Vollständige https://-Adresse. Bei einem Link wird der Inhalt nicht angezeigt.', $form->generate_text_box('link',$item['link']));
        $container->output_row('Sortierung', 'Kleine Zahl zuerst. Gleiche Kategorien in benachbarten Positionen anordnen.', $form->generate_numeric_field('sortorder',$item['sortorder']));
        $container->output_row('Sichtbarkeit', '', $form->generate_check_box('visible',1,'Auf der Regelseite anzeigen',array('checked'=>(bool)$item['visible'])));
    }
    $container->output_row('Änderungshinweis', 'Pflichtfeld; wird auf der Regelseite veröffentlicht. Keine privaten Angaben eintragen.', $form->generate_text_box('summary',$mybb->get_input('summary')));
    $container->output_row('Kurzer Indexhinweis', 'Zusätzlich für die eingestellte Anzahl Stunden auf der Startseite anzeigen.', $form->generate_check_box('announce',1,'Auf Änderung aufmerksam machen',array('checked'=>$mybb->request_method !== 'post' || $mybb->get_input('announce', MyBB::INPUT_INT) === 1)));
    $container->end();
    $form->output_submit_wrapper(array($form->generate_submit_button($action === 'delete' ? 'Bestätigt löschen' : 'Speichern')));
    $form->end();
} elseif ($action === 'settings') {
    $form = new Form($base.'&action=settings','post');
    $container = new FormContainer('Einstellungen');
    foreach (array('title'=>'Seitentitel','intro'=>'Einleitung','consent'=>'Text der Pflichtzustimmung','alert_hours'=>'Indexhinweis in Stunden (0–168)') as $key=>$title) {
        $value = $mybb->request_method === 'post' ? $mybb->get_input($key) : ladytime_rules_setting($key);
        $field = $key === 'alert_hours' ? $form->generate_numeric_field($key,$value) : ($key === 'title' ? $form->generate_text_box($key,$value) : $form->generate_text_area($key,$value));
        $container->output_row($title,'',$field);
    }
    $container->end(); $form->output_submit_wrapper(array($form->generate_submit_button('Speichern'))); $form->end();
} elseif ($action === 'acceptances') {
    $count = (int)$db->fetch_field($db->simple_select('ladytime_rules_acceptances','COUNT(*) AS total'),'total');
    $current = max(1, min(max(1, (int)ceil($count / 30)), $mybb->get_input('page',MyBB::INPUT_INT)));
    $offset = ($current - 1) * 30;
    $query = $db->query('SELECT a.*, u.username FROM '.TABLE_PREFIX.'ladytime_rules_acceptances a LEFT JOIN '.TABLE_PREFIX.'users u ON u.uid=a.uid ORDER BY a.accepted_at DESC, a.uid DESC LIMIT '.$offset.',30');
    $table = new Table;
    foreach (array('Mitglied','Zeitpunkt','Fassung') as $title) { $table->construct_header($title); }
    while ($row = $db->fetch_array($query)) {
        $table->construct_cell($row['username'] === null ? 'Gelöschtes Mitglied #'.(int)$row['uid'] : ladytime_rules_e($row['username']).' (#'.(int)$row['uid'].')');
        $table->construct_cell(my_date($mybb->settings['dateformat'].' '.$mybb->settings['timeformat'],$row['accepted_at']));
        $table->construct_cell((int)$row['revision']); $table->construct_row();
    }
    if (!$table->num_rows()) { $table->construct_cell('Noch keine Zustimmungen erfasst.',array('colspan'=>3)); $table->construct_row(); }
    $table->output('Zustimmungen bei der Registrierung');
    echo draw_admin_pagination($current,30,$count,$base.'&action=acceptances&page={page}');
} else {
    echo '<p><a href="'.ladytime_rules_e(ladytime_rules_url()).'" target="_blank" rel="noopener">Regelseite ansehen ↗</a></p>';
    $table = new Table;
    foreach (array('Kategorie / Titel','Typ','Sortierung','Aktionen') as $title) { $table->construct_header($title); }
    $query = $db->simple_select('ladytime_rules_sections','*','',array('order_by'=>'sortorder, id'));
    while ($row = $db->fetch_array($query)) {
        $table->construct_cell(ladytime_rules_e($row['category'].' / '.$row['title']).($row['visible'] ? '' : ' (ausgeblendet)'));
        $table->construct_cell($row['link'] !== '' ? 'Link' : 'Inhalt');
        $table->construct_cell((int)$row['sortorder']);
        $table->construct_cell('<a href="'.$base.'&amp;action=edit&amp;id='.(int)$row['id'].'">Bearbeiten</a> · <a href="'.$base.'&amp;action=delete&amp;id='.(int)$row['id'].'">Löschen</a>');
        $table->construct_row();
    }
    if (!$table->num_rows()) { $table->construct_cell('Noch keine Bereiche. Über „Bereich hinzufügen“ beginnen.',array('colspan'=>4)); $table->construct_row(); }
    $table->output('Regelbereiche');
}
$page->output_footer();
