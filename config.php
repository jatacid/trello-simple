<?php

require_once INCLUDE_DIR . 'class.plugin.php';

class TrelloSimplePluginConfig extends PluginConfig {

    // Provide compatibility function for versions of osTicket prior to
    // translation support (v1.9.4)
    function translate() {
        if (!method_exists('Plugin', 'translate')) {
            return array(
                function ($x) {
                    return $x;
                },
                function ($x, $y, $n) {
                    return $n != 1 ? $y : $x;
                }
            );
        }
        return Plugin::translate('trello-simple');
    }

    function getOptions() {
        list ($__, $_N) = self::translate();

        return array(
            'trello-simple'                      => new SectionBreakField(array(
                'label' => $__('Trello Simple'),
                'hint'  => $__('A simple integration plugin for Trello cards and Osticket. Creating a new Ticket will create a new Trello card to Trello List of your choice. Updating the Ticket status will find the associated Trello card by the same card name and can archive the card for you. You will need Trello API Keys & Token: https://trello.com/app-key')
                    )),
            'trello-simple-api-key'          => new TextboxField(array(
                'label'         => $__('Trello API Key'),
                'configuration' => array(
                    'size'   => 60,
                    'length' => 200
                ),
                    )),
            'trello-simple-api-token'          => new TextboxField(array(
                'label'         => $__('Trello API Token'),
                'configuration' => array(
                    'size'   => 60,
                    'length' => 200
                ),
                    )),
            'trello-simple-list-id'          => new TextboxField(array(
                'label'         => $__('Trello List ID'),
                'hint'          => $__('You can find your List ID by using your browser Inspect Element on a list selection dropdown. (I.e a card\'s "Move" card Action button).'),
                'configuration' => array(
                    'size'   => 60,
                    'length' => 200
                ),
                    ))
        );
    }

}
