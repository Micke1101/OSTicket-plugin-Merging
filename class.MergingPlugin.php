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
            if($_REQUEST['id'] && ($ticket=Ticket::lookup($_REQUEST['id']))) {
				if($this->isChild($ticket))
					$html = preg_replace('/(?<=' . $ticket->getSubject() . ').*?(?=<\/h3>)/',
						' - ' . sprintf(__('This is a child ticket to <a href="tickets.php?id=%s">parent</a>.'),
						$this->getMaster($ticket)->getId()), $html);
				elseif($this->isMaster($ticket))
					$html = preg_replace('/(?<=' . $ticket->getSubject() . ').*?(?=<\/h3>)/',
						' - ' . sprintf(__('This is a master ticket with %d child ticket(s).'),
						count($this->getChildren($ticket))), $html);
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
            `id` int(11) NOT NULL,
            `agent_id` int(11) NOT NULL,
            `master_id` int(11) NOT NULL,
            `ticket_id` int(11) NOT NULL,
            `date_merged` datetime NOT NULL
        );";
        $result = db_query($sql);
        if(!$result)
            return $result;
        $sql = "ALTER TABLE `" . TICKET_RELATION_TABLE . "` ADD PRIMARY KEY (`id`);";
        $result = db_query($sql);
        if(!$result)
            return $result;
        $sql = "ALTER TABLE `" . TICKET_RELATION_TABLE . "` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;";
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
        $config = $this->getConfig();
        
        if(!$this->canBeMaster($master)){
                ::error(__('Ticket selected for master cannot be one.'));
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
            if(!$this->canBeChild($temp)){
                    ::warning(sprintf(__('Ticket #%s cannot be a child.'), $temp->getNumber()));
                continue;
            }
            if(!$temp->isClosable()){
                    ::warning(sprintf(__('Ticket #%s cannot be closed.'), $temp->getNumber()));
                continue;
            }
            $tickets[] = $temp;
        }
        
        unset($temp);
        
        if(empty($tickets)){
                ::error(__('Select at least one viable ticket'));
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
            $temp->setChild(true);
            $master->logEvent('merged', array('child' => $temp->getSubject(), 'id' => $temp->getId()));
        }
        $master->setMaster(true);
        return true;
    }
    
    function split($master, $tid){
        if($tid == $master->getId())
            return false;
        
        $ticket = Ticket::lookup($tid);
        if(!$ticket || !$this->isChild($ticket))
            return false;
        
        $sql='DELETE FROM '.TICKET_RELATION_TABLE.' WHERE master_id = ' . $master->getId() . ' AND ticket_id = ' . $tid;
        db_query($sql);
        $this->setChild($ticket, false);
        
        if(!$this->getChildren($master))
            $this->setMaster($master, false);
        
        $master->logEvent('split', array('child' => $ticket->getSubject(), 'id' => $tid));
        return true;
    }
    
    function massSplit($tids){
        global $thisstaff;
        
        foreach($tids as $key => $tid){
            if(!($temp = Ticket::lookup($tid)))
                continue;
            if($this->isMaster($temp))
                foreach($this->getChildren($temp) as $ticket)
                    $this->split($temp, $ticket->getId());
            else if($this->isChild($temp))
                $this->split($this->getMaster($temp), $tid);
            else
                    ::warning(sprintf(__('Ticket #%s is not merged.'), $temp->getNumber()));
        }
        return true;
    }
    
    function isMaster($ticket){
        if(!isset($ticket->master)){
            $sql='SELECT ticket_id FROM '.TICKET_RELATION_TABLE.' WHERE master_id = ' . $ticket->getId();
            if($res=db_query($sql))
                $this->setMaster($ticket, db_num_rows($res));
        }
        return $ticket->master;
    }
    
    function canBeMaster($ticket){
        if($this->isMaster($ticket))
            return true;
        if($this->isChild($ticket))
            return false;
        return true;
    }
    
    function isChild($ticket){
        if(!isset($ticket->child)){
            $sql='SELECT ticket_id, date_merged FROM '.TICKET_RELATION_TABLE.' WHERE ticket_id = ' . $ticket->getId();
            
            if($res=db_query($sql)){
                $nr = db_num_rows($res);
                $this->setChild($ticket, $nr);
                if($nr){
                    list($tid, $datem) = db_fetch_row($res);
                    $this->setDateMerged($ticket, $datem);
                }
            }
        }
        return $ticket->child;
    }
    
    function canBeChild($ticket){
        return !$this->isMaster($ticket) && !$this->isChild($ticket);
    }
    
    function setMaster($ticket, $var){
        $ticket->master = (boolean)$var;
    }
    
    function setChild($ticket, $var){
        $ticket->child = (boolean)$var;
    }
    
    function getDateMerged($ticket){
        return (!$this->isChild($ticket) || !isset($ticket->dateMerged)) ? false : $ticket->dateMerged;
    }
    
    function setDateMerged($ticket, $date){
        $ticket->dateMerged = $date;
    }
    
    function getChildren($ticket){
        if(!$this->isMaster($ticket))
            return array();
        
        $sql='SELECT ticket_id, date_merged FROM '.TICKET_RELATION_TABLE.' WHERE master_id = ' . $ticket->getId();
        
        $ret = array();
        if(($res=db_query($sql)) && db_num_rows($res))
            while(list($id, $tmpdate)=db_fetch_row($res))
                if($temp=Ticket::lookup($id)){
                    $this->setDateMerged($temp, $tmpdate);
                    $this->setChild($temp, true);
                    $ret[] = $temp;
                }
        return $ret;
    }
    
    function getMaster($ticket){
        if(!$this->isChild($ticket))
            return array();
        
        $sql='SELECT master_id FROM '.TICKET_RELATION_TABLE.' WHERE ticket_id = ' . $ticket->getId();
        if(($res=db_query($sql)) && db_num_rows($res)) {
            list($id)=db_fetch_row($res);
            if ($temp=Ticket::lookup($id)) {
                $this->setMaster($temp, true);
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