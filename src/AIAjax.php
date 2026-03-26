<?php
/*********************************************************************
 * AI Response Generator Ajax Controller
 *********************************************************************/

require_once(INCLUDE_DIR . 'class.ajax.php');
require_once(INCLUDE_DIR . 'class.ticket.php');
require_once(INCLUDE_DIR . 'class.thread.php');
require_once(__DIR__ . '/../api/OpenAIClient.php');

class AIAjaxController extends AjaxController {

    private function isDebugLoggingEnabled($cfg) {
        return !!$cfg->get('debug_request_logging');
    }

    private function writeDebugRequestLog($cfg, Ticket $ticket, $apiUrl, $model, array $messages) {
        if (!$this->isDebugLoggingEnabled($cfg))
            return;

        $payload = array(
            'model' => (string)$model,
            'messages' => $messages,
            'temperature' => 0.2,
            'max_tokens' => 512,
        );

        $entry = array(
            'timestamp' => date('c'),
            'ticket_id' => (int)$ticket->getId(),
            'ticket_number' => (string)$ticket->getNumber(),
            'api_url' => (string)$apiUrl,
            'payload' => $payload,
        );

        $logDir = dirname(__DIR__) . '/logs';
        $logFile = $logDir . '/request-debug.log';

        if (!is_dir($logDir) && !@mkdir($logDir, 0775, true))
            return;

        if (!is_writable($logDir))
            @chmod($logDir, 0775);

        if (!is_writable($logDir))
            return;

        $line = "--- " . date('Y-m-d H:i:s') . " ---\n"
            . json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
            . "\n\n";

        if (@file_put_contents($logFile, $line, FILE_APPEND) !== false)
            @chmod($logFile, 0664);
    }

    /**
     * Sanitize thread body to remove passwords and sensitive data
     * Prevents leaking credentials to external API
     */
    private function sanitizeThreadBody($body) {
        // Remove content in square brackets like [password: xyz] or [PIN: 1234]
        $body = preg_replace('/\[(?:password|pwd|pin|token|key|secret|api[_\s]key|auth|credentials?)\s*:?\s*[^\]]*\]/i', 
                            '[REDACTED: sensitive data]', $body);
        
        // Remove common password patterns: "password: xyz" (matching until punctuation/newline)
        $body = preg_replace('/(password|pwd|passphrase|pass)\s*[=:]\s*[^\s\n\r,.]*/i', 
                            '$1=[REDACTED]', $body);
        
        // Remove API keys and tokens (common patterns)
        $body = preg_replace('/(api[_\s]key|token|bearer)\s*[=:]\s*[a-z0-9\-_.]*[a-z0-9]{10,}/i', 
                            '$1=[REDACTED]', $body);
        
        // Remove credit card patterns (basic)
        $body = preg_replace('/\b\d{4}[\s\-]?\d{4}[\s\-]?\d{4}[\s\-]?\d{4}\b/', '[REDACTED: CC number]', $body);
        
        // Remove social security / tax ID patterns
        $body = preg_replace('/\b\d{2}\s*\d{2}\s*\d{5}\b/', '[REDACTED: ID number]', $body);
        
        return $body;
    }

    function generate() {
        global $thisstaff;
        $this->staffOnly();

        $ticket_id = (int) ($_POST['ticket_id'] ?? $_GET['ticket_id'] ?? 0);
        if (!$ticket_id || !($ticket = Ticket::lookup($ticket_id)))
            Http::response(404, $this->encode(array('ok' => false, 'error' => __('Unknown ticket'))));

        // Permission check: must be able to reply
        $role = $ticket->getRole($thisstaff);
        if (!$role || !$role->hasPerm(Ticket::PERM_REPLY))
            Http::response(403, $this->encode(array('ok' => false, 'error' => __('Access denied'))));

        // Load plugin config from active instance
        // Support per-instance selection via instance_id
        $cfg = null;
        $iid = (int)($_POST['instance_id'] ?? $_GET['instance_id'] ?? 0);
        if ($iid) {
            $all = AIResponseGeneratorPlugin::getAllConfigs();
            if (isset($all[$iid]))
                $cfg = $all[$iid];
        }
        if (!$cfg)
            $cfg = AIResponseGeneratorPlugin::getActiveConfig();
        if (!$cfg)
            Http::response(500, $this->encode(array('ok' => false, 'error' => __('Plugin not configured'))));

        $api_url = rtrim($cfg->get('api_url'), '/');
        $api_key = $cfg->get('api_key');
        $model   = $cfg->get('model');

        if (!$api_url || !$model)
            Http::response(400, $this->encode(array('ok' => false, 'error' => __('Missing API URL or model'))));

        // Build prompt using latest thread entries
        $thread = $ticket->getThread();
        $entries = $thread ? $thread->getEntries() : array();
        $messages = array();
        $count = 0;
        foreach ($entries as $E) {
            // Cap to recent context to avoid huge prompts
            if ($count++ > 20) break;
            $type = $E->getType();
            $body = ThreadEntryBody::clean($E->getBody());
            
            // SECURITY: Sanitize sensitive data (passwords, tokens, credit cards, etc.)
            $body = $this->sanitizeThreadBody($body);
            
            $who  = $E->getPoster();
            $who  = is_object($who) ? $who->getName() : 'User';
            $role = ($type == 'M') ? 'user' : 'assistant';
            $messages[] = array('role' => $role, 'content' => sprintf('[%s] %s', $who, $body));
        }

        // Append instruction for the model (from config or default)
        $system = trim((string)$cfg->get('system_prompt')) ?: "You are a helpful support agent. Draft a concise, professional reply. Quote the relevant ticket details when appropriate. Keep HTML minimal.";
        
        // Add agent notes/remarks if provided
        $agent_notes = trim((string)($_POST['agent_notes'] ?? ''));
        if ($agent_notes) {
            $system .= "\n\nAGENT'S SPECIFIC INSTRUCTIONS FOR THIS RESPONSE:\n" . $agent_notes;
        }
        
        array_unshift($messages, array('role' => 'system', 'content' => $system));

        // Load RAG documents content (if any)
        $rag_text = $this->loadRagDocuments($cfg);
        if ($rag_text)
            $messages[] = array('role' => 'system', 'content' => "Additional knowledge base context:\n".$rag_text);

        try {
            $this->writeDebugRequestLog($cfg, $ticket, $api_url, $model, $messages);

            $client = new OpenAIClient($api_url, $api_key);
            $reply = $client->generateResponse($model, $messages);
            if (!$reply)
                throw new Exception(__('Empty response from model'));

            // Apply response template if provided
            $tpl = trim((string)$cfg->get('response_template'));
            if ($tpl) {
                global $thisstaff;
                $tpl = $this->expandTemplate($tpl, $ticket, $reply, $thisstaff);
                $reply = $tpl;
            }

            return $this->encode(array('ok' => true, 'text' => $reply));
        }
        catch (Throwable $t) {
            return $this->encode(array('ok' => false, 'error' => $t->getMessage()));
        }
    }

    private function loadRagDocuments($cfg) {
        $rag = trim((string)$cfg->get('rag_content'));
        if (!$rag) return '';
        // Optionally limit to 20,000 chars
        $limit_chars = 20000;
        if (strlen($rag) > $limit_chars) {
            $rag = substr($rag, 0, $limit_chars) . "\n... (truncated)";
        }
        return $rag;
    }

    private function expandTemplate($template, Ticket $ticket, $aiText, $staff=null) {
        $user = $ticket->getOwner();
        $agentName = '';
        if ($staff && is_object($staff)) {
            // Prefer display name, fallback to name
            $agentName = method_exists($staff, 'getName') ? (string)$staff->getName() : '';
        }
        $replacements = array(
            '{ai_text}' => (string)$aiText,
            '{ticket_number}' => (string)$ticket->getNumber(),
            '{ticket_subject}' => (string)$ticket->getSubject(),
            '{user_name}' => $user ? (string)$user->getName() : '',
            '{user_email}' => $user ? (string)$user->getEmail() : '',
            '{agent_name}' => $agentName,
        );
        return strtr($template, $replacements);
    }
}
