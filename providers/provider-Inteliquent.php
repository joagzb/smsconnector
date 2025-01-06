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
            ),
            'tn' => array(
                'type'        => 'string',
                'label'       => _('webhook phone number'),
                'help'        => _('(optional) Configure webhook URL for a specific telephone number (e.g. 17044561234); if null, webhook URL will apply to inbound messages to any of your numbers'),
                'default'     => '',
                'required'    => false,
                'placeholder' => _('Enter phone number'),
            ),
        );

        $this->inbound_message_url = $this->getWebHookUrl();
    }

    /**
     * Configure Inbound Message Webhook
     *
     * @return bool
     * @throws \Exception
     */
    public function configureWebhook()
    {
        $config = $this->getConfig($this->nameRaw);

        if (empty($config['api_key'])) {
            throw new \Exception(_('API Key and Webhook URL are required for webhook configuration.'));
        }

        $headers = array(
            "Authorization" => sprintf("Bearer %s", $config['api_key']),
            "Content-Type"  => "application/json"
        );

        $authorization = array(
            'inboundAuth' => true,
            'webhookUrl'  => $this->inbound_message_url,
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
            'from' => $from,
            'to'   => [$to],
            'text' => $message
        );

        return $this->sendInteliquent($payload, $id);
    }

    /**
     * Send the payload to Inteliquent API
     *
     * @param array $payload
     * @param int $mid
     * @return bool
     * @throws \Exception
     */
    private function sendInteliquent($payload, $mid): bool
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
            throw new \Exception(_('Failed to encode message payload to JSON.'));
        }

        $session = \FreePBX::Curl()->requests($url);
        try {
            $response = $session->post('', $headers, $json, array());
            freepbx_log(FPBX_LOG_INFO, sprintf(_("%s responds: HTTP %s, %s"), $this->nameRaw, $response->status_code, $response->body));

            if ($response->status_code >= 200 && $response->status_code < 300) {
                $this->setDelivered($mid);
                return true;
            } else {
                throw new \Exception(sprintf(_("HTTP %s, %s"), $response->status_code, $response->body));
            }
        } catch (\Exception $e) {
            freepbx_log(FPBX_LOG_ERROR, sprintf(_('Error sending message: %s'), $e->getMessage()));
            throw new \Exception(sprintf(_('Unable to send message: %s'), $e->getMessage()));
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
            freepbx_log(FPBX_LOG_WARNING, _("Invalid request method. Only POST is allowed."));
            return 405;
        }

        $post_data = file_get_contents("php://input");
        $sms = json_decode($post_data);

        freepbx_log(FPBX_LOG_INFO, sprintf(_("Webhook (%s) received: %s"), $this->nameRaw, print_r($post_data, true)));

        if (empty($sms)) {
            return 403;
        }

        if (isset($sms->deliveryReceipt) && $sms->deliveryReceipt === true) { // Handle Delivery Receipts
            $referenceId = $sms->referenceId ?? null;

            if ($referenceId) {
                try {
                    $connector->markMessageAsDelivered($referenceId);
                } catch (\Exception $e) {
                    throw new \Exception(sprintf(_('Unable to process delivery receipt: %s'), $e->getMessage()));
                }
            } else {
                freepbx_log(FPBX_LOG_WARNING, _("Missing referenceId in delivery receipt."));
                return 403;
            }
        } else { // Handle Inbound Messages
            $referenceId = $sms->referenceId ?? null;
            $from = $sms->from ?? null;
            $text = $sms->text ?? '';
            $tos = $sms->to ?? [];

            if (empty($from)) {
                freepbx_log(FPBX_LOG_WARNING, _("Missing 'from' field in inbound message."));
                return 403;
            }

            if (empty($tos) || !is_array($tos)) {
                freepbx_log(FPBX_LOG_WARNING, _("Missing or invalid 'to' field in inbound message."));
                return 403;
            }

            foreach ($tos as $to) {
                if (empty($to)) {
                    continue; // Skip to the next recipient in case of empty number
                }

                try {
                    $msgid = $connector->getMessage($to, $from, '', $text, null, null, $referenceId);
                    $connector->emitSmsInboundUserEvt($msgid, $to, $from, '', $text, null, 'Smsconnector', $referenceId);
                } catch (\Exception $e) {
                    freepbx_log(FPBX_LOG_ERROR, sprintf(_('Unable to process inbound message: %s'), $e->getMessage()));
                    throw new \Exception(sprintf(_('Unable to process inbound message: %s'), $e->getMessage()));
                }
            }
        }

        return $return_code;
    }

}
