<?php
require_once INCLUDE_DIR . 'class.signal.php';
require_once INCLUDE_DIR . 'class.plugin.php';
require_once INCLUDE_DIR . 'class.ticket.php';
require_once INCLUDE_DIR . 'class.osticket.php';
require_once INCLUDE_DIR . 'class.config.php';
require_once INCLUDE_DIR . 'class.format.php';
require_once 'config.php';

class TrelloSimplePlugin extends Plugin
{

    public $config_class = "TrelloSimplePluginConfig";

    /**
     * The entrypoint of the plugin, keep short, always runs.
     */
    public function bootstrap()
    {

        // Listen for osTicket to tell us it's made a new ticket or updated
        Signal::connect('ticket.created', array($this, 'onOstTicketCreatedTrello'));
        // If updated a ticket (ie status is now resolved/archived)
        Signal::connect('model.updated', array($this, 'onOstModelUpdated'));
        // If deleted a ticket
        Signal::connect('model.deleted', array($this, 'onOstModelDeleted'));
    }

    /**
     * What to do with a new Ticket?
     *
     * @global OsticketConfig $cfg
     * @param Ticket $ticket
     * @return type
     */
    public function onOstTicketCreatedTrello(Ticket $ticket)
    {
        global $cfg;
        if (!$cfg instanceof OsticketConfig) {
            error_log("Slack plugin called too early.");
            return;
        }
        $this->postTrelloCard($ticket);
    }

    /**
     * Pulls the new model of data after a card is updated & posts the changes to the right card
     *
     * @param $object - this is the object with the updated data
     * @param $data - this is an object which contains a flag signifying 'old' data
     */
    public function onOstModelUpdated($object, $data)
    {
        // A Ticket was updated
        if (get_class($object) === "Ticket") {
            // model.updated runs twice. 'dirty' object is the old data
            if (isset($data['dirty'])) {
                // rename object for clarity that we're accessing the ticket
                $ticket = $object;
                // If the ['status_id'] index is set then it means it was changed.
                if (isset($data['dirty']['status_id'])) {
                    // Get current (new) status Id
                    $new_status_id = $ticket->getStatusId();
                    // is re/opened
                    if ($new_status_id == 1 || $new_status_id == 6) {
                        // Set card as open state(unarchive if already been archived)
                        $this->setCardState($this->getTicketData($ticket), 'false');
                    } else if ($new_status_id == 2
                        || $new_status_id == 3
                        || $new_status_id == 4
                    ) {
                        // is 'resolved' or 'closed' or 'archived' set card to archived.
                        $this->setCardState($this->getTicketData($ticket), 'true');
                    }
                }
            } else {
                // second model.updated with new data
                $ticket = $object;
                $ticketinfo = $this->getTicketData($ticket);
                $this->updateCardData($ticketinfo);
            }
        }
    }

    /**
     * Runs if card is deleted (archives it)
     *
     * @param $model - object containing info about close state of ticket
     * @param $data
     */
    public function onOstModelDeleted($model)
    {
        if (get_class($model) === "Ticket") {
            // marks the trello card as closed=true
            $this->setCardState($this->getTicketData($ticket), 'true');
        }
    }

    /**
     * Updates the current card 'state' (archived or not)
     *
     * @param $ticketinfo
     * @param $cardstate
     */
    public function setCardState($ticketinfo, $cardstate)
    {
        $trello_list_id = $this->getConfig()->get('trello-simple-list-id');
        $this_card_id = $this->getMatchingCardId($ticketinfo['name'], $trello_list_id, 'all');
        $trello_api_endpoint = "https://api.trello.com/1/cards/$this_card_id/?closed=$cardstate&";
        $trello_api_endpoint_params = array();

        if ($this_card_id) {
            // Send query
            $this->buildSendQuery($trello_api_endpoint, $trello_api_endpoint_params, "PUT");
        }

    }

    /**
     * Finds and updates trello card with new model data
     *
     * @param $ticketinfo
     */
    public function updateCardData($ticketinfo)
    {
        $trello_list_id = $this->getConfig()->get('trello-simple-list-id');
        $this_card_id = $this->getMatchingCardId($ticketinfo['name'], $trello_list_id);

        if ($this_card_id) {

            $ticketinfo['this_card_id'] = $this_card_id;
            // Get array of all the customfields on the board
            $list_of_custom_fields = $this->getCustomFields($this->getBoardId($trello_list_id));

            // cycle through each custom field and update it in the callback
            array_walk($list_of_custom_fields, array($this, mapThroughCustomFields), $ticketinfo);
        }

    }

    /**
     * Prepares & creates a trello card & updates the custom fields once created
     *
     * @global OsticketConfig $cfg
     * @param Ticket $ticket
     * @return boolean
     */

    public function postTrelloCard($ticket)
    {

        $trello_list_id = $this->getConfig()->get('trello-simple-list-id');

        // Endpoint to create a card on a list
        $trello_api_endpoint = 'https://api.trello.com/1/cards/?idList=' . $trello_list_id . '&';

        // Get an array of all info about this ticket
        $ticketinfo = $this->getTicketData($ticket);

        // Build the query to create a card
        $trello_api_endpoint_params = array(
            'name' => $ticketinfo['name'],
            'desc' => $ticketinfo['desc'],
            'pos' => $ticketinfo['pos'],
        );

        // Send query (Create the card)
        $response = $this->buildSendQuery($trello_api_endpoint, $trello_api_endpoint_params, "POST");



        // Optional - runs after New Card is created
        // Process return response to get card ID to update custom fields on that card

        // $response = json_decode($response, true);
        // $this_card_id = $response['id'];
        // if ($this_card_id) {
        //     $ticketinfo['this_card_id'] = $this_card_id;
        //     // Get array of all the customfields on the board
        //     $list_of_custom_fields = $this->getCustomFields($this->getBoardId($trello_list_id));
        //     // cycle through each custom field and update it in the callback
        //     array_walk($list_of_custom_fields, array($this, mapThroughCustomFields), $ticketinfo);
        // }
    }

    /**
     * getTicketData()
     * Compiles an array of formatted data of the ticket from the Osticket system
     * @global OsticketConfig $cfg
     * @param $ticket
     * @return array
     */
    public function getTicketData($ticket)
    {

        global $ost, $cfg;
        if (!$ost instanceof osTicket || !$cfg instanceof OsticketConfig) {
            error_log("Slack plugin called too early.");
            return;
        }

        // $cfg->getUrl() . "scp/tickets.php?id=" . $ticket->getId()
        // $ticket->getNumber()
        // $ticket->getSubject()
        // Format::html2text($ticket->getMessages()[0]->getBody()->getClean())
        // $ticket->getEmail()
        // $ticket->getOwner()->getName()->name
        // $ticket->getEmail()
        // $this->formatDate($this->getCustomFieldValue($ticket, 'customFieldDate'))
        // $this->getCustomFieldValue($ticket, 'customFieldName')

        // build array of all ticket data available that we want to use, including the data for a new card
        $allticketdata = array(
            // new card
            'name' => $ticket->getNumber() . $ticket->getSubject(),
            'desc' => Format::html2text($ticket->getMessages()[0]->getBody()->getClean()),
            'pos' => 'top',
            'due' => 'false',
            'dueComplete' => 'false',
            'idMembers' => 'false',

            // Other information (optional)
            'customFieldName' => $this->formatDate($this->getCustomFieldValue($ticket, 'customFieldDate')),
            'customFieldDate' => $this->getCustomFieldValue($ticket, 'customFieldName'),
        );

        return $allticketdata;
    }

    /**
     * Get the list of available custom fields on a board
     * https://api.trello.com/1/boards/$board_id/customFields
     * @param string $board_id
     * @return array Array of all Trello custom field info
     */
    public function getCustomFields($board_id)
    {
        $trello_api_endpoint = "https://api.trello.com/1/boards/$board_id/customFields/?";
        $trello_api_endpoint_params = array();
        $response = $this->buildSendQuery($trello_api_endpoint, $trello_api_endpoint_params, "GET");
        $response = json_decode($response, true);
        return $response;
    }

    /**
     * Callback to process getCustomFields array & update the custom field when created.
     * See docs for accepted values reference: https://developers.trello.com/v1.0/reference#customfielditemsid
     * @param array $this_custom_field = Array of your custom field's info
     * @param float $key = index for the map
     * @param array $ticketinfo = All info for this ticket
     */
    public function mapThroughCustomFields($this_custom_field, $key, $ticketinfo)
    {

        $new_value = false;

        // Choose which custom field to update by the Custom Field's name

        if ($this_custom_field['name'] == 'Custom Field Name') {
            $new_value = array(
                'value' => array(
                    'text' => $ticketinfo['customFieldName'],
                ),
            );
        } else if ($this_custom_field['name'] == 'Custom Field Date') {
            $new_value = array(
                'value' => array(
                    'date' => date('Y-m-d', strtotime(str_replace("/", "-", $ticketinfo['customFieldDate']))) . 'T01:00:00.000Z',
                ),
            );
        }

        // update this custom field if it has a new_value set
        if ($new_value) {
            $this->updateCustomField($ticketinfo['this_card_id'], $this_custom_field['id'], $new_value);
        }
    }

    /**
     * Updates the value of the custom field of the provided card
     * https://api.trello.com/1/card/{idCard}/customField/{idCustomField}/item
     * See docs for reference: https://developers.trello.com/v1.0/reference#customfielditemsid
     * @param string $card_id
     * @param string $custom_field_id
     * @param string $new_value
     */
    public function updateCustomField($card_id, $custom_field_id, $new_value)
    {

        $trello_api_endpoint = "https://api.trello.com/1/card/$card_id/customField/$custom_field_id/item/?";

        $trello_api_endpoint_params = $new_value;

        $response = $this->buildSendQuery($trello_api_endpoint, $trello_api_endpoint_params, "PUT");
    }

    /**
     * Returns the board ID from a provided List ID
     * @param string $this_trello_list_id = the list ID you want to get the board ID of
     * @return string Board ID
     */
    public function getBoardId($this_trello_list_id)
    {

        $trello_api_endpoint = "https://api.trello.com/1/lists/$this_trello_list_id/board/?";

        $trello_api_endpoint_params = array(
            'fields' => 'idBoard',
        );

        $response = $this->buildSendQuery($trello_api_endpoint, $trello_api_endpoint_params, "GET");
        $response = json_decode($response, true);
        return $response['id'];
    }

    /**
     * Builds and sends a c_url request based on provided parameters
     *
     * @param string $trello_api_endpoint = This call's endpoint url (must end in `?`)
     * @param array $trello_api_endpoint_params = The body parameters you want sent
     * @param string $ticketinfo = POST type/GET/PUT
     * @return json result or error message
     */
    public function buildSendQuery($trello_api_endpoint, $trello_api_endpoint_params, $request_type = "POST")
    {

        $trello_api_key = $this->getConfig()->get('trello-simple-api-key');
        $trello_api_token = $this->getConfig()->get('trello-simple-api-token');
        // construct the query with key/token params
        $query = $trello_api_endpoint . "key=" . $trello_api_key . "&token=" . $trello_api_token;
        // encode the params
        $params = http_build_query($trello_api_endpoint_params);
        $data_string = utf8_encode(json_encode($trello_api_endpoint_params));

        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $query . '&' . $params);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $request_type);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            // Vary the curl_opts based on request_type for Trello
            if ($request_type == "GET") {
                curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen($data_string))
                );
            } else if ($request_type == "PUT") {
                // For PUT requests send raw json in CURLOPT_POSTFIELDS instead of in CURLOPT_URL
                curl_setopt($ch, CURLOPT_URL, $query);
                curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                    'Content-Type: application/json')
                );
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($trello_api_endpoint_params, JSON_HEX_QUOT));
            } else if ($request_type == "POST") {
                curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                    'Content-Type: application/json')
                );
                curl_setopt($ch, CURLOPT_POSTFIELDS, false);
            }
            // Send the cUrl
            $result = curl_exec($ch);

            if ($result === false) {
                throw new \Exception($query . ' - ' . curl_error($ch));
            } else {
                $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($statusCode != '200') {
                    throw new \Exception(
                        'Error sending to: ' . $query
                        . ' Http code: ' . $statusCode
                        . ' curl-error: ' . curl_errno($ch));
                }
            }
        } catch (\Exception $e) {
            error_log('Error contacting Trello: ' . $e->getMessage());
        } finally {
            curl_close($ch);
        }

        return $result;
    }
    /**
     * Gets a matched cardID by searching for the name
     * (works only if card names aren't changed in Trello)
     *
     * @param string $cardName
     * @param string $trello_list_id
     * @param string $openorclosed = Valid filter values: all, closed, none, open, visible.
     */
    public function getMatchingCardId($cardName, $trello_list_id, $openorclosed = 'open')
    {

        $board_id = $this->getBoardId($trello_list_id);
        $trello_api_endpoint = "https://trello.com/1/boards/$board_id/cards/$openorclosed/?";
        $trello_api_endpoint_params = array();

        // Send query
        $response = $this->buildSendQuery($trello_api_endpoint, $trello_api_endpoint_params, "GET");
        // Process return response to get card IDs
        $response = json_decode($response, true);
        $trelloCards = $response;

        // find the matching card by searching name
        $matchingTrelloCard = $this->searchArrayByProperty($trelloCards, 'name', $cardName);
        // return its id
        return $matchingTrelloCard['id'];
    }

    /**
     * Searches the array for the matching result.
     */
    public function searchArrayByProperty($array, $property, $value)
    {
        try {
            $item = null;
            foreach ($array as $struct) {
                if ($value == $struct[$property]) {
                    $item = $struct;
                    break;
                }
            }
            return $item;
        } catch (Exception $e) {
            return null;
        }
    }
    /**
     * Formats date according to desired formatting.
     * @param string $text
     * @return string
     */
    public function formatDate($date)
    {
        $date = explode('-', $date);
        $day = preg_split('/[\s]/', $date[2])[0];
        $month = $date[1];
        $year = $date[0];
        return sprintf('%s/%s/%s', $day, $month, $year);
    }
    /**
     * Extracts the value of the customfield from returned array
     * @param obj $ticket
     * @param string $text
     * @return string
     */
    public function getCustomFieldValue($ticket, $field)
    {
        $customfieldvalue = $ticket->_answers[$field]->value;
        return $customfieldvalue;
    }
    /**
     * Formats text according to the
     * formatting rules:https://api.slack.com/docs/message-formatting
     *
     * @param string $text
     * @return string
     */
    public function format_text($text)
    {
        $formatter = [
            '<' => '&lt;',
            '>' => '&gt;',
            '&' => '&amp;',
        ];
        $formatted_text = str_replace(array_keys($formatter), array_values($formatter), $text);
        // put the <>'s control characters back in
        $moreformatter = [
            'CONTROLSTART' => '<',
            'CONTROLEND' => '>',
        ];
        // Replace the CONTROL characters, and limit text length to 500 characters.
        return substr(str_replace(array_keys($moreformatter), array_values($moreformatter), $formatted_text), 0, 500);
    }

}
