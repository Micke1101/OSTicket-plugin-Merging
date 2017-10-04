<?php
require_once (INCLUDE_DIR . 'class.signal.php');
require_once (INCLUDE_DIR . 'class.ticket.php');
require_once ('config.php');

define('TICKET_RELATION_TABLE', TABLE_PREFIX . 'ticket_relation');

class MergingPlugin extends Plugin {
    
    const DEBUG = FALSE;
    /**
     * Which config to use (in config.php)
     *
     * @var string
     */
    public $config_class = 'MergingPluginConfig';
    
    /**
     * Run on every instantiation of osTicket..
     * needs to be concise
     *
     * {@inheritdoc}
     *
     * @see Plugin::bootstrap()
     */
    function bootstrap() {
        if($this->firstRun()){
            if (! $this->configureFirstRun ()) {
                return false;
            }
        }
        ob_start();
        register_shutdown_function(function () {
            global $thisstaff;
            $html = ob_get_clean();
            $ticket = null;
            if($thisstaff && $_REQUEST['id'] && ($ticket=Ticket::lookup($_REQUEST['id']))){
                if(($this->isChild($ticket) || $this->isMaster($ticket))
                    && preg_match('/<ul.*?class="tabs"(.|\n)*?<\/ul>/', $html, $tabs)
                    && preg_match('/(<\/div>(\s|\n)*){3}<div.*id="print-options">/', $html, $lastdiv)
                    && preg_match('/<a.*?href="#post-reply"(.|\n)*?<\/a>/', $html, $postreply)){
                    $newbutton = file_get_contents(__DIR__ . '/templates/merge-view.php');
                    $newbuttonoptions = '';
                    $tickets = TicketModel::objects();

                    // -- Open and assigned to me
                    $assigned = Q::any(array(
                        'staff_id' => $thisstaff->getId(),
                    ));
                    // -- Open and assigned to a team of mine
                    if ($teams = array_filter($thisstaff->getTeams()))
                        $assigned->add(array('team_id__in' => $teams));
                    $visibility = Q::any(new Q(array('status__state'=>'open', $assigned)));
                    // -- Routed to a department of mine
                    if (!$thisstaff->showAssignedOnly() && ($depts=$thisstaff->getDepts()))
                        $visibility->add(array('dept_id__in' => $depts));
                    $tickets->filter(Q::any($visibility));

                    $tickets->filter(array('status__state'=>'open'));
                    $tickets->values('ticket_id', 'number', 'cdata__subject');

                    foreach ($tickets as $T)
                        $newbuttonoptions = $newbuttonoptions . "<option value='" . $T['ticket_id'] . "'>" . $T['number'] . " | " . $T['cdata__subject'] . "</option>";
                    $newbutton = str_replace("{MERGING_OPTIONS}", $newbuttonoptions, $newbutton);
                    $newbutton = str_replace("{MERGING_TOOLTIP}", __('Merge'), $newbutton);
                    $newbutton = str_replace("{MERGING_PLACEHOLDER}", __('Select a ticket'), $newbutton);
                    $newbutton = str_replace("{MERGING_TICKET_ID}", $ticket->getId(), $newbutton);
                    $html = str_replace($postreply[0], $newbutton . $postreply[0], $html);

                    $newTabs = str_replace('</ul>', '<li><a id="ticket-thread-tab" href="#relations">' . __('Relations') . '</a></li></ul>', $tabs[0]);
                    $relationContent = '';
                    if($this->isMaster($ticket)){
                        $relationContent = file_get_contents(__DIR__ . '/templates/relationstab-master.php');
                        $relationContentChildren = '';
                        $children = $this->getChildren($ticket);
                        foreach ($children as $T) {
                            $relationContentChildren = $relationContentChildren . '<tr id="' . $T->getId() . '">
                                    <td nowrap>
                                        <a class="Icon ' . strtolower($T->getSource()) . 'Ticket preview"
                                        title="Preview Ticket"
                                        href="tickets.php?id=' . $T->getId() . '"
                                        data-preview="#tickets/' . $T->getId() . '/preview"
                                        >' . $T->getNumber() . '</a>
                                    </td>
                                    <td align="center" nowrap>' . (Format::datetime($this->getDateMerged($T)) ?: $date_fallback) . '</td>
                                    <td>
                                        <div style="max-width: 279px; max-height: 1.2em"
                                        class="link truncate"
                                        href="tickets.php?id=' . $T->getId() . '">' . $T->getSubject() . '</div>
                                    </td>
                                    <td nowrap>
                                        <div>
                                            <span class="truncate">' . Format::htmlchars($T->getDeptName()) . '</span>
                                        </div>
                                    </td>
                                    <td nowrap>
                                        <div>
                                            <span class="truncate">' . (Format::htmlchars($T->getStaff() ? $T->getStaff()->getName() : __('Unknown'))) . '</span>
                                        </div>
                                    </td>
                                    <td nowrap>
                                        <div data-toggle="tooltip" title="' . __('Split') . '" style="height: 26px;">
                                            <button type="submit" class="action-button" onclick="$.ajax({
                                                type: \'POST\',
                                                url: \'../include/plugins/Merging/ajax.php\',
                                                data: ({master: ' . $ticket->getId() . ',ticket: ' . $T->getId() . ',a: \'split\'}),
                                                success: function(data) {
                                                location.reload();
                                                }
                                                });">
                                                <i class="icon-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>';
                        }
                        $relationContent = str_replace("{MERGING_CHILD_TICKETS}", $relationContentChildren, $relationContent);
                        $relationContent = str_replace("{MERGING_TRANSLATE_CHILD_TICKETS}", __('Child tickets'), $relationContent);
                    } else {
                        $relationContent = file_get_contents(__DIR__ . '/templates/relationstab-child.php');
                        $master = $this->getMaster($ticket);
                        $relationContent = str_replace("{MERGING_ID}", $master->getId(), $relationContent);
                        $relationContent = str_replace("{MERGING_SOURCE}", strtolower($master->getSource()), $relationContent);
                        $relationContent = str_replace("{MERGING_NUMBER}", $master->getNumber(), $relationContent);
                        $relationContent = str_replace("{MERGING_PRIORITY}", $master->getPriority(), $relationContent);
                        $relationContent = str_replace("{MERGING_DEPARTMENT}", $master->getDeptName(), $relationContent);
                        $relationContent = str_replace("{MERGING_SUBJECT}", $master->getSubject(), $relationContent);
                        $relationContent = str_replace("{MERGING_DATE_MERGED}", $this->getDateMerged($ticket), $relationContent);
                        $relationContent = str_replace("{MERGING_TRANSLATE_MASTER_TICKET}", __('Master ticket'), $relationContent);
                    }
                    $relationContent = str_replace("{MERGING_TRANSLATE_NUMBER}", __('Number'), $relationContent);
                    $relationContent = str_replace("{MERGING_TRANSLATE_PRIORITY}", __('Priority'), $relationContent);
                    $relationContent = str_replace("{MERGING_TRANSLATE_DEPARTMENT}", __('Department'), $relationContent);
                    $relationContent = str_replace("{MERGING_TRANSLATE_SUBJECT}", __('Subject'), $relationContent);
                    $relationContent = str_replace("{MERGING_TRANSLATE_DATE_MERGED}", __('Date merged'), $relationContent);
                    $relationContent = str_replace("{MERGING_TRANSLATE_FROM}", __('From'), $relationContent);
                    $relationContent = str_replace("{MERGING_TRANSLATE_CLOSED_BY}", __('Closed by'), $relationContent);
                    
                    $html = str_replace($tabs[0], $newTabs, $html);
                    $html = str_replace($lastdiv[0], $relationContent . $lastdiv[0], $html);
                }
                if($this->isChild($ticket))
                    $html = preg_replace('/(?<=' . $ticket->getSubject() . ').*?(?=<\/h3>)/',
                        ' - ' . __('CHILD'), $html);
                elseif($this->isMaster($ticket))
                    $html = preg_replace('/(?<=' . $ticket->getSubject() . ').*?(?=<\/h3>)/',
                        ' - ' . __('MASTER'), $html);
            }
            print $html;
        });
    }
    
    function firstRun() {
        $sql = 'SHOW TABLES LIKE \'' . TICKET_RELATION_TABLE . '\'';
        $res = db_query ( $sql );
        return (db_num_rows ( $res ) == 0);
    }
    
    function configureFirstRun() {
        if (! $this->insertSql ()) {
            echo "First run configuration error.  " . "Unable to create database tables!";
            return false;
        }
        return true;
    }
    
    function insertSql(){
        $result = false;
        $sql = "CREATE TABLE IF NOT EXISTS `" . TICKET_RELATION_TABLE . "` (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `agent_id` int(11) NOT NULL,
            `master_id` int(11) NOT NULL,
            `ticket_id` int(11) NOT NULL,
            `date_merged` datetime NOT NULL,
			PRIMARY KEY (`id`)
        );";
        $result = db_query($sql);
        if(!$result)
            return $result;
        $sql = "ALTER TABLE `" . THREAD_EVENT_TABLE . "` CHANGE `state` `state` ENUM('created','closed','reopened',
            'assigned','transferred','overdue','edited','viewed','error','collab','resent','merged',
            'split') CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL;";
        return db_query($sql);
    }
    
    function merge($master, $tids){
        global $thisstaff;
        $config = new PluginConfig($config_class);
        
        if(!MergingPlugin::canBeMaster($master)){
            Messages::error(__('Ticket selected for master cannot be one.'));
            return false;
        }
        
        $tickets = array();
        foreach($tids as $key => $tid){
            //Master ticket can't be child ticket aswell.
            if($tid == $master->getId())
                continue;
            //Proper ID?
            $temp = Ticket::lookup($tid);
            if(!$temp)
                continue;
            if(!MergingPlugin::canBeChild($temp)){
                Messages::warning(sprintf(__('Ticket #%s cannot be a child.'), $temp->getNumber()));
                continue;
            }
            if(!$temp->isCloseable()){
                Messages::warning(sprintf(__('Ticket #%s cannot be closed.'), $temp->getNumber()));
                continue;
            }
            $tickets[] = $temp;
        }
        
        unset($temp);
        
        if(empty($tickets)){
            Messages::error(__('Select at least one viable ticket'));
            return false;
        }
        
        foreach($tickets as $temp){
            $temp->setStatus(TicketStatus::lookup($config->get('childstatus')));
            
            if($config->get('copyrecipients')){
                $master->addCollaborator($temp->getUser(), array(), $error, true);
                if ($collabs = $temp->getThread()->getParticipants()) {
                    foreach ($collabs as $c)
                        $master->addCollaborator($c->getUser(), array(), $error, true);
                }
            }
            
            $sql='INSERT INTO '.TICKET_RELATION_TABLE.' (`id`, `agent_id`, `master_id`, `ticket_id`, `date_merged`)
                VALUES(NULL, '.$thisstaff->getId().', '.$master->getId().', '.$temp->getId().', NOW())';
            db_query($sql);
            MergingPlugin::setChild($temp, true);
            $master->logEvent('merged', array('child' => $temp->getSubject(), 'id' => $temp->getId()));
        }
        MergingPlugin::setMaster($master, true);
        return true;
    }
    
    function split($master, $tid){
        if($tid == $master->getId())
            return false;
        
        $ticket = Ticket::lookup($tid);
        if(!$ticket || !MergingPlugin::isChild($ticket))
            return false;
        
        $sql='DELETE FROM '.TICKET_RELATION_TABLE.' WHERE master_id = ' . $master->getId() . ' AND ticket_id = ' . $tid;
        db_query($sql);
        MergingPlugin::setChild($ticket, false);
        
        if(!MergingPlugin::getChildren($master))
            MergingPlugin::setMaster($master, false);
        
        $master->logEvent('split', array('child' => $ticket->getSubject(), 'id' => $tid));
        return true;
    }
    
    function massSplit($tids){
        global $thisstaff;
        
        foreach($tids as $key => $tid){
            if(!($temp = Ticket::lookup($tid)))
                continue;
            if(MergingPlugin::isMaster($temp))
                foreach(MergingPlugin::getChildren($temp) as $ticket)
                    MergingPlugin::split($temp, $ticket->getId());
            else if(MergingPlugin::isChild($temp))
                MergingPlugin::split(MergingPlugin::getMaster($temp), $tid);
            else
                Messages::warning(sprintf(__('Ticket #%s is not merged.'), $temp->getNumber()));
        }
        return true;
    }
    
    function isMaster($ticket){
        if(!isset($ticket->master)){
            $sql='SELECT ticket_id FROM '.TICKET_RELATION_TABLE.' WHERE master_id = ' . $ticket->getId();
            if($res=db_query($sql))
                MergingPlugin::setMaster($ticket, db_num_rows($res));
        }
        return $ticket->master;
    }
    
    function canBeMaster($ticket){
        if(MergingPlugin::isMaster($ticket))
            return true;
        if(MergingPlugin::isChild($ticket))
            return false;
        return true;
    }
    
    function isChild($ticket){
        if(!isset($ticket->child)){
            $sql='SELECT ticket_id, date_merged FROM '.TICKET_RELATION_TABLE.' WHERE ticket_id = ' . $ticket->getId();
            
            if($res=db_query($sql)){
                $nr = db_num_rows($res);
                MergingPlugin::setChild($ticket, $nr);
                if($nr){
                    list($tid, $datem) = db_fetch_row($res);
                    MergingPlugin::setDateMerged($ticket, $datem);
                }
            }
        }
        return $ticket->child;
    }
    
    function canBeChild($ticket){
        return !MergingPlugin::isMaster($ticket) && !MergingPlugin::isChild($ticket);
    }
    
    function setMaster($ticket, $var){
        $ticket->master = (boolean)$var;
    }
    
    function setChild($ticket, $var){
        $ticket->child = (boolean)$var;
    }
    
    function getDateMerged($ticket){
        return (!MergingPlugin::isChild($ticket) || !isset($ticket->dateMerged)) ? false : $ticket->dateMerged;
    }
    
    function setDateMerged($ticket, $date){
        $ticket->dateMerged = $date;
    }
    
    function getChildren($ticket){
        if(!MergingPlugin::isMaster($ticket))
            return array();
        
        $sql='SELECT ticket_id, date_merged FROM '.TICKET_RELATION_TABLE.' WHERE master_id = ' . $ticket->getId();
        
        $ret = array();
        if(($res=db_query($sql)) && db_num_rows($res))
            while(list($id, $tmpdate)=db_fetch_row($res))
                if($temp=Ticket::lookup($id)){
                    MergingPlugin::setDateMerged($temp, $tmpdate);
                    MergingPlugin::setChild($temp, true);
                    $ret[] = $temp;
                }
        return $ret;
    }
    
    function getMaster($ticket){
        if(!MergingPlugin::isChild($ticket))
            return array();
        
        $sql='SELECT master_id FROM '.TICKET_RELATION_TABLE.' WHERE ticket_id = ' . $ticket->getId();
        if(($res=db_query($sql)) && db_num_rows($res)) {
            list($id)=db_fetch_row($res);
            if ($temp=Ticket::lookup($id)) {
                MergingPlugin::setMaster($temp, true);
                return $temp;
            }
        }
        return false;
    }
    
    /**
     * Required stub.
     *
     * {@inheritdoc}
     *
     * @see Plugin::uninstall()
     */
    function uninstall() {
        $errors = array ();
        $result = false;
        $sql = "DROP TABLE IF EXISTS `" . TICKET_RELATION_TABLE . "`;";
        db_query($sql);
        $sql = "ALTER TABLE `" . THREAD_EVENT_TABLE . "` CHANGE `state` `state` ENUM('created','closed','reopened',
            'assigned','transferred','overdue','edited','viewed','error','collab','resent'
            ) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL;";
        db_query($sql);
        parent::uninstall ( $errors );
    }
    
    /**
     * Plugins seem to want this.
     */
    public function getForm() {
        return array ();
    }
}

class MergedEvent extends ThreadEvent {
    static $icon = 'code-fork';
    static $state = 'merged';
    function getDescription($mode=self::MODE_STAFF) {
        return sprintf($this->template(__('<b>{somebody}</b> merged this ticket with %s{data.id}%s<b>{data.child}</b>%s {timestamp}')),
                '<a href="/scp/tickets.php?id=', '">', '</a>');
    }
}
class SplitEvent extends ThreadEvent {
    static $icon = 'share-alt';
    static $state = 'split';
    function getDescription($mode=self::MODE_STAFF) {
        return sprintf($this->template(__('<b>{somebody}</b> split this ticket from %s{data.id}%s<b>{data.child}</b>%s {timestamp}')),
                '<a href="/scp/tickets.php?id=', '">', '</a>');
    }
}