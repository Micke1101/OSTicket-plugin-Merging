<?php
require_once INCLUDE_DIR . 'class.plugin.php';

class MergingPluginConfig extends PluginConfig
{

    // Provide compatibility function for versions of osTicket prior to
    // translation support (v1.9.4)
    function translate()
    {
        if (! method_exists('Plugin', 'translate')) {
            return array(
                function ($x) {
                    return $x;
                },
                function ($x, $y, $n) {
                    return $n != 1 ? $y : $x;
                }
            );
        }
        return Plugin::translate('merging');
    }

    /**
     * Build an Admin settings page.
     *
     * {@inheritdoc}
     *
     * @see PluginConfig::getOptions()
     */
    function getOptions()
    {
        list ($__, $_N) = self::translate();
		$statuses = array();
		foreach(TicketStatusList::getStatuses() as $status){
			$statuses[$status->getId()] = $status->getName();
		}
        return array(
            'childstatus' => new ChoiceField([
                'label' => $__('Child status'),
                'required' => true,
                'hint' => $__('What status do you want child tickets to get.'),
                'default' => '',
                'choices' => $statuses
            ]),
            'copyrecipients' => new BooleanField([
                'label' => $__('Copy recipients'),
                'required' => false,
                'hint' => $__('When merging tickets bring the owner of the child ticket and collaborators.'),
                'default' => true
            ]),
        );
    }
}
