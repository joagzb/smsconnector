<?php
namespace FreePBX\modules\Smsconnector\Provider;

class Inteliquent extends providerBase
{
    private $base_url = "https://messagebroker.inteliquent.com/msgbroker/rest";
    private $configure_auth_url = "/configureAuthorization";
    private $inbound_message_url;
    private $remove_apikey_and_webhook_info = "/removeAuthorization";
    private $outbound_message_url = "/publishMessages";
    private $webhooks_configured_info_url = "/selectAuthorization";

    public function __construct()
    {
        parent::__construct();
        $this->name       = _('Inteliquent');
        $this->nameRaw    = 'inteliquent';
        $this->APIUrlInfo = 'https://portal.inteliquent.com/CustomerPortal/apiDocV2.htm';
        $this->APIVersion = 'v2';

        $this->configInfo = array(
            'api_key' => array(
                'type'        => 'string',
                'label'       => _('API Key'),
                'help'        => _("Enter your Inteliquent API Key"),
                'default'     => '',
                'required'    => true,
                'placeholder' => _('Enter API Key'),
            )
        );

        $this->inbound_message_url = $this->getWebHookUrl();
    }

    /**
     * Configure Inbound Message Webhook
     *
     * @return bool
     * @throws \Exception
     */
    public function configureWebhook($inbound_webhook_url = '')
    {
        $config = $this->getConfig($this->nameRaw);
        $webhook_url = !empty($inbound_webhook_url) ? $inbound_webhook_url : $this->inbound_message_url;

        if (empty($config['api_key']) || empty($webhook_url)) {
            throw new \Exception(_('API Key and Webhook URL are required for webhook configuration.'));
        }

        $headers = array(
            "Authorization" => sprintf("Bearer %s", $config['api_key']),
            "Content-Type"  => "application/json"
        );

        $authorization = array(
            'inboundAuth' => true,
            'webhookUrl'  => $webhook_url,
            'apiKey'      => $config['api_key'],
        );

        if (!empty($config['tn'])) {
            $authorization['tn'] = $config['tn'];
        }

        $payload = array(
            'authorizations' => array($authorization)
        );

        $url = $this->base_url . $this->configure_auth_url;
        $json = json_encode($payload);

        $session = \FreePBX::Curl()->requests($url);
        try {
            $response = $session->post('', $headers, $json, array());
            freepbx_log(FPBX_LOG_INFO, sprintf(_("%s responds: HTTP %s, %s"), $this->nameRaw, $response->status_code, $response->body));

            if ($response->status_code >= 200 && $response->status_code < 300) {
                return true;
            } else {
                throw new \Exception(sprintf(_("HTTP %s, %s"), $response->status_code, $response->body));
            }
        } catch (\Exception $e) {
            freepbx_log(FPBX_LOG_ERROR, sprintf(_('Error configuring webhook: %s'), $e->getMessage()));
            throw new \Exception(sprintf(_('Unable to configure webhook: %s'), $e->getMessage()));
        }
    }

    /**
     * Remove API Key and Webhook Information
     *
     * @param int $authId ID associated with the API key or webhook to be removed
     * @return bool
     * @throws \Exception
     */
    public function removeWebhookConfiguration($authId)
    {
        if (empty($authId)) {
            throw new \Exception(_('Authorization ID (authId) is required to remove API key or webhook.'));
        }

        $config = $this->getConfig($this->nameRaw);

        if (empty($config['api_key'])) {
            throw new \Exception(_('API Key is required for removing authorization.'));
        }

        $url = $this->base_url . $this->remove_apikey_and_webhook_info;

        $headers = array(
            "Authorization" => sprintf("Bearer %s", $config['api_key']),
            "Content-Type"  => "application/json"
        );

        $payload = array(
            'authorizations' => array(
                array('authId' => $authId)
            )
        );

        $json = json_encode($payload);
        if ($json === false) {
            throw new \Exception(_('Failed to encode authorization payload to JSON.'));
        }

        $session = \FreePBX::Curl()->requests($url);

        try {
            $response = $session->post('', $headers, $json, array());

            if ($response->status_code >= 200 && $response->status_code < 300) {
                return true;
            } else {
                throw new \Exception(sprintf(_("HTTP %s, %s"), $response->status_code, $response->body));
            }
        } catch (\Exception $e) {
            freepbx_log(FPBX_LOG_ERROR, sprintf(_('Error removing authorization: %s'), $e->getMessage()));
            throw new \Exception(sprintf(_('Unable to remove authorization: %s'), $e->getMessage()));
        }
    }

    /**
     * Retrieve API Key and Webhook Information
     *
     * @return array The list of API keys and webhook URLs
     * @throws \Exception
     */
    public function retrieveConfiguredWebhooks()
    {
        $config = $this->getConfig($this->nameRaw);

        if (empty($config['api_key'])) {
            throw new \Exception(_('API Key is required for retrieving authorization info.'));
        }

        $url = $this->base_url . $this->webhooks_configured_info_url;

        $headers = array(
            "Authorization" => sprintf("Bearer %s", $config['api_key']),
            "Content-Type"  => "application/json"
        );


        try {
            $session = \FreePBX::Curl()->requests($url);
            $response = $session->post('', $headers, '{}', array());

            if ($response->status_code >= 200 && $response->status_code < 300) {
                $data = json_decode($response->body, true);

                if (!$data || !isset($data['authConfig']) || !isset($data['authConfig']['authorizations'])) {
                    return [];
                }

                // Filter valid webhook configurations
                $webhookConfigs = array_filter($data['authConfig']['authorizations'], function ($auth) {
                    return isset($auth['inboundAuth']) && $auth['inboundAuth'] === true && !empty($auth['webhookUrl']);
                });

                // Map the filtered results
                return array_map(function ($auth) {
                    return array(
                        'authId'        => $auth['authId'] ?? null,
                        'tn'            => $auth['tn'] ?? null,
                        'webhookUrl'    => $auth['webhookUrl'] ?? null,
                        'headerName'    => $auth['headerName'] ?? null,
                        'headerValue'   => $auth['headerValue'] ?? null,
                    );
                }, $webhookConfigs);
            } else {
                throw new \Exception(sprintf(_("HTTP %s, %s"), $response->status_code, $response->body));
            }
        } catch (\Exception $e) {
            freepbx_log(FPBX_LOG_ERROR, sprintf(_('Error retrieving webhook configuration: %s'), $e->getMessage()));
            throw $e;
        }
    }

    /**
     * Send a text message via Inteliquent
     *
     * @param int $id Message ID
     * @param string $to Recipient phone number
     * @param string $from Sender phone number
     * @param string|null $message Message content
     * @return bool
     * @throws \Exception
     */
    public function sendMessage($id, $to, $from, $message = null)
    {
        if (empty($from)) {
            throw new \Exception(_('Sender phone number (from) is required.'));
        }

        if (empty($to)) {
            throw new \Exception(_('Recipient phone number (to) is required.'));
        }

        if (empty($message)) {
            throw new \Exception(_('Message content is required.'));
        }

        $payload = array(
            'from' => ltrim($from, '+'),
            'to'   => [ltrim($to, '+')],
            'text' => $message
        );

        try {
            $this->sendInteliquent($payload, $id);
            return true;
        } catch (\Exception $e) {
            freepbx_log(FPBX_LOG_ERROR, sprintf(_('Failed to send message (ID: %d): %s'), $id, $e->getMessage()));
            return false;
        }
    }

    /**
     * Send the payload to Inteliquent API
     *
     * @param array $payload
     * @param int $mid
     * @return void
     * @throws \Exception
     */
    private function sendInteliquent($payload, $mid): void
    {
        $config = $this->getConfig($this->nameRaw);

        if (empty($config['api_key'])) {
            throw new \Exception(_('API Key is required for sending messages.'));
        }

        $url = $this->base_url . $this->outbound_message_url;
        $headers = array(
            "Authorization" => sprintf("Bearer %s", $config['api_key']),
            "Content-Type"  => "application/json"
        );

        $json = json_encode($payload);
        if ($json === false) {
            freepbx_log(FPBX_LOG_ERROR, _('Failed to encode message payload to JSON.'));
            throw new \Exception(_('Failed to encode message payload to JSON.'));
        }

        freepbx_log(FPBX_LOG_INFO, sprintf(_("Sending message from %s to %s"), $payload['from'], json_encode($payload['to'])));

        $session = \FreePBX::Curl()->requests($url);
        $response = $session->post('', $headers, $json, array());

        freepbx_log(FPBX_LOG_INFO, sprintf(
            _("%s responds: HTTP %s, %s"),
            $this->nameRaw,
            $response->status_code,
            $response->body
        ));

        if ($response->status_code < 200 || $response->status_code >= 300) {
            throw new \Exception(sprintf(
                _('HTTP %s: %s'),
                $response->status_code,
                $response->body
            ));
        }

        try {
            $this->setDelivered($mid);
        } catch (\Exception $e) {
            freepbx_log(FPBX_LOG_ERROR, sprintf(
                _('Message sent, but failed to mark message (ID: %d) as delivered: %s'),
                $mid,
                $e->getMessage()
            ));
        }
    }

    /**
     * Send a media message via Inteliquent
     *
     * @param int $id Message ID
     * @param string $to Recipient phone number
     * @param string $from Sender phone number
     * @param string|null $message Optional message content
     * @return bool
     * @throws \Exception
     */
    public function sendMedia($id, $to, $from, $message = null)
    {
        // not available
        return true;
    }

    /**
     * Handle incoming webhook from Inteliquent
     *
     * @param object $connector
     * @return int HTTP status code
     * @throws \Exception
     */
    public function callPublic($connector)
    {
        $return_code = 202;

        if ($_SERVER['REQUEST_METHOD'] !== "POST") {
            freepbx_log(FPBX_LOG_ERROR, _("Invalid request method. Only POST is allowed."));
            return 405;
        }

        $post_data = file_get_contents("php://input");
        $sms = json_decode($post_data);

        freepbx_log(FPBX_LOG_INFO, sprintf(_("Webhook (%s) received: %s"), $this->nameRaw, print_r($post_data, true)));

        if (empty($sms)) {
            return 403;
        }

        if (isset($sms->deliveryReceipt) && $sms->deliveryReceipt === true) { // Handle Delivery Receipts
            $reference_id = $sms->referenceId ?? null;

            if(empty($reference_id)){
                freepbx_log(FPBX_LOG_ERROR, _("Missing referenceId in delivery receipt."));
                return 403;
            }

            try {
                $connector->markMessageAsDelivered($reference_id);
            } catch (\Exception $e) {
                freepbx_log(FPBX_LOG_ERROR, sprintf(_('Unable to process delivery receipt: %s'), $e->getMessage()));
                throw new \Exception(sprintf(_('Unable to process delivery receipt: %s'), $e->getMessage()));
            }

        } else { // Handle Inbound Messages
            $reference_id = $sms->referenceId ?? null;
            $from = isset($sms->from) ? ltrim($sms->from, '+') : null;
            $text = $sms->text ?? '';
            $tos = $sms->to ?? [];

            if (empty($from)) {
                freepbx_log(FPBX_LOG_ERROR, _("Missing 'from' field in inbound message."));
                return 403;
            }

            if (empty($tos) || !is_array($tos)) {
                freepbx_log(FPBX_LOG_ERROR, _("Missing or invalid 'to' field in inbound message."));
                return 403;
            }

            foreach ($tos as $to) {
                $to = ltrim($to, '+');
                if (empty($to)) {
                    continue; // Skip to the next recipient in case of empty number
                }

                try {
                    $msgid = $connector->getMessage($to, $from, '', $text, null, null, $reference_id);
                    $connector->emitSmsInboundUserEvt($msgid, $to, $from, '', $text, null, 'Smsconnector', $reference_id);
                } catch (\Exception $e) {
                    freepbx_log(FPBX_LOG_ERROR, sprintf(_('Unable to process inbound message: %s'), $e->getMessage()));
                    throw new \Exception(sprintf(_('Unable to process inbound message: %s'), $e->getMessage()));
                }
            }
        }

        return $return_code;
    }

}
