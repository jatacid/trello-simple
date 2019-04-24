osTicket-trello-simple
==============
A simple plugin for [osTicket](https://osticket.com) which creates Cards on a [Trello](https://trello.com) board.

Note
--------
This is no longer actively maintained and has been tested up to 1.10.1 for osTicket. It should continue to work for some time and the core principles should easily be modified if desired.

Install
--------
Clone this repo or download the zip file and place the contents into a `\\osticket/include/plugins/trello-simple` folder.
Navigate to your OST plugins manager interface `//scp/plugins.php` and Install the Plugin, then Enable the plugin. Then add your API keys and Trello ListID.


Info
------
This plugin uses CURL but otherwise relies on no third party API wrappers etc.  
It is a uni-directional plugin (from OsTicket to Trello).  
It's been custom developed for a single purpose and this code is just being distributed as it may help others in a similar situation so anticipate some minor PHP coding to modify to your desired effect.  
There is not a whole lot of error checking and fallback defaults so be sure to check it's set up correctly lest you see errors.  
Tested on osTicket-1.10.1  

Features
------
Currently it will create a card on ticket creation to the Trello List of your choice.  
By default will be a new card with same ticket name and the initial content text, but you can customise this in `getTicketData()`  
If you have the `Custom Fields` addon on your board, you can modify the plugin code to update custom fields to include any data you want. See the callback function `mapThroughCustomFields()`.  
If you update an Osticket to 'resolved', 'closed' or 'deleted', it will search for, and delete the matching Trello card (searches by identical name).

Kudos
------
[https://github.com/thammanna/osticket-slack](https://github.com/thammanna/osticket-slack)  
[https://github.com/kyleladd/OSTicket-Trello-Plugin](https://github.com/kyleladd/OSTicket-Trello-Plugin)  


## Requirements
- php_curl
- A Trello account
