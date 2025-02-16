<?php

require_once(INCLUDE_DIR . 'class.signal.php');
require_once(INCLUDE_DIR . 'class.plugin.php');
require_once(INCLUDE_DIR . 'class.ticket.php');
require_once(INCLUDE_DIR . 'class.osticket.php');
require_once(INCLUDE_DIR . 'class.config.php');
require_once(INCLUDE_DIR . 'class.format.php');
require_once('config.php');

class ZulipPlugin extends Plugin {

    var $config_class = "ZulipPluginConfig";

    /**
     * The entrypoint of the plugin, keep short, always runs.
     */
    function bootstrap() {
        // Listen for osTicket to tell us it's made a new ticket or updated
        // an existing ticket:
        Signal::connect('ticket.created', array($this, 'onTicketCreated'));
        Signal::connect('threadentry.created', array($this, 'onTicketUpdated'));
    }

    /**
     * What to do with a new Ticket?
     * 
     * @global OsticketConfig $cfg
     * @param Ticket $ticket
     * @return type
     */
    function onTicketCreated(Ticket $ticket) {
        global $cfg;
        if (!$cfg instanceof OsticketConfig) {
            error_log("Zulip plugin called too early.");
            return;
        }

	// Convert any HTML in the message into text
	// If this is a custom form submission, getMessages will be empty, so check for valid contents
	if(is_null($ticket->getMessages()[0]))
	{
		$plaintext = "";
	} else {
		$plaintext = Format::html2text($ticket->getMessages()[0]->getBody()->getClean());
	}

        // Format the messages we'll send.
        $heading = sprintf('%s CONTROLSTART%sscp/tickets.php?id=%d|#%sCONTROLEND %s'
                , __("New Ticket")
                , $cfg->getUrl()
                , $ticket->getId()
                , $ticket->getNumber()
                , __("created"));
	$this->sendToZulip($ticket, $heading, $plaintext);
    }

    /**
     * What to do with an Updated Ticket?
     * 
     * @global OsticketConfig $cfg
     * @param ThreadEntry $entry
     * @return type
     */
    function onTicketUpdated(ThreadEntry $entry) {
        global $cfg;
        if (!$cfg instanceof OsticketConfig) {
            error_log("Zulip plugin called too early.");
            return;
        }
        if (!$entry instanceof MessageThreadEntry) {
            // this was a reply or a system entry.. not a message from a user
            return;
        }

        // Need to fetch the ticket from the ThreadEntry
        $ticket = $this->getTicket($entry);
        if (!$ticket instanceof Ticket) {
            // Admin created ticket's won't work here.
            return;
        }

        // Check to make sure this entry isn't the first (ie: a New ticket)
        $first_entry = $ticket->getMessages()[0];
        if ($entry->getId() == $first_entry->getId()) {
            return;
        }
        // Convert any HTML in the message into text
        $plaintext = Format::html2text($entry->getBody()->getClean());

        // Format the messages we'll send
        $heading = sprintf('%s CONTROLSTART%sscp/tickets.php?id=%d|#%sCONTROLEND %s'
                , __("Ticket")
                , $cfg->getUrl()
                , $ticket->getId()
                , $ticket->getNumber()
                , __("updated"));
        $this->sendToZulip($ticket, $heading, $plaintext, 'warning');
    }

    /**
     * A helper function that sends messages to zulip endpoints. 
     * 
     * @global osTicket $ost
     * @global OsticketConfig $cfg
     * @param Ticket $ticket
     * @param string $heading
     * @param string $body
     * @param string $colour
     * @throws \Exception
     */
    function sendToZulip(Ticket $ticket, $heading, $body, $colour = 'good') {
        global $ost, $cfg;
        if (!$ost instanceof osTicket || !$cfg instanceof OsticketConfig) {
            error_log("Zulip plugin called too early.");
            return;
        }
        $url = $this->getConfig()->get('zulip-webhook-url');
        if (!$url) {
            $ost->logError('Zulip Plugin not configured', 'You need to read the Readme and configure a webhook URL before using this.');
        }

        // Check the subject, see if we want to filter it.
        $regex_subject_ignore = $this->getConfig()->get('zulip-regex-subject-ignore');
        // Filter on subject, and validate regex:
        if ($regex_subject_ignore && preg_match("/$regex_subject_ignore/i", $ticket->getSubject())) {
            $ost->logDebug('Ignored Message', 'Zulip notification was not sent because the subject (' . $ticket->getSubject() . ') matched regex (' . htmlspecialchars($regex_subject_ignore) . ').');
            return;
        } else {
            error_log("$ticket_subject didn't trigger $regex_subject_ignore");
        }

        $heading = $this->format_text($heading);

        // Pull template from config, and use that. 
        $template          = $this->getConfig()->get('message-template');
        // Add our custom var
        $custom_vars       = [
            'zulip_safe_message' => $this->format_text($body),
        ];
        $formatted_message = $ticket->replaceVars($template, $custom_vars);

        // Build the payload with the formatted data:
        $payload = array (
	    'api_key'       => $this->getConfig()->get('zulip-api-token'),
	    'user_name'     => $this->getConfig()->get('zulip-user'),
            'stream'        => $this->getConfig()->get('zulip-stream'),
            'channel_name'  => $this->getConfig()->get('zulip-channel'),
            'text'          => $ticket->getNumber(). " - ". $ticket->getSubject()." [#".$ticket->getNumber()."](". $cfg->getUrl() . 'scp/tickets.php?id=' . $ticket->getId(). ")  ". $formatted_message ,  
        );

	try {
            // Setup curl
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            // Send the payload to zulip:
            if (curl_exec($ch) === false) {
                throw new \Exception($url . ' - ' . curl_error($ch));
            } else {
                $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($statusCode != '200') {
                    throw new \Exception(
                    'Error sending to: ' . $url
                    . ' Http code: ' . $statusCode
                    . ' curl-error: ' . curl_errno($ch));
                }
            }
        } catch (\Exception $e) {
            $ost->logError('Zulip posting issue!', $e->getMessage(), true);
            error_log('Error posting to Zulip. ' . $e->getMessage());
        } finally {
            curl_close($ch);
        }
    }

    /**
     * Fetches a ticket from a ThreadEntry
     *
     * @param ThreadEntry $entry        	
     * @return Ticket
     */
    function getTicket(ThreadEntry $entry) {
        $ticket_id = Thread::objects()->filter([
                    'id' => $entry->getThreadId()
                ])->values_flat('object_id')->first() [0];

        // Force lookup rather than use cached data..
        // This ensures we get the full ticket, with all
        // thread entries etc.. 
        return Ticket::lookup(array(
                    'ticket_id' => $ticket_id
        ));
    }

    /**
     * Formats text according to the 
     * formatting rules:https://api.zulip.com/docs/message-formatting
     * 
     * @param string $text
     * @return string
     */
    function format_text($text) {
        $formatter      = [
            '<' => '&lt;',
            '>' => '&gt;',
            '&' => '&amp;'
        ];
        $formatted_text = str_replace(array_keys($formatter), array_values($formatter), $text);
        // put the <>'s control characters back in
        $moreformatter  = [
            'CONTROLSTART' => '<',
            'CONTROLEND'   => '>'
        ];
        // Replace the CONTROL characters, and limit text length to 500 characters.
        return substr(str_replace(array_keys($moreformatter), array_values($moreformatter), $formatted_text), 0, 500);
    }

    /**
     * Get either a Gravatar URL or complete image tag for a specified email address.
     *
     * @param string $email The email address
     * @param string $s Size in pixels, defaults to 80px [ 1 - 2048 ]
     * @param string $d Default imageset to use [ 404 | mm | identicon | monsterid | wavatar ]
     * @param string $r Maximum rating (inclusive) [ g | pg | r | x ]
     * @param boole $img True to return a complete IMG tag False for just the URL
     * @param array $atts Optional, additional key/value attributes to include in the IMG tag
     * @return String containing either just a URL or a complete image tag
     * @source https://gravatar.com/site/implement/images/php/
     */
    function get_gravatar($email, $s = 80, $d = 'mm', $r = 'g', $img = false, $atts = array()) {
        $url = 'https://www.gravatar.com/avatar/';
        $url .= md5(strtolower(trim($email)));
        $url .= "?s=$s&d=$d&r=$r";
        if ($img) {
            $url = '<img src="' . $url . '"';
            foreach ($atts as $key => $val)
                $url .= ' ' . $key . '="' . $val . '"';
            $url .= ' />';
        }
        return $url;
    }

}
