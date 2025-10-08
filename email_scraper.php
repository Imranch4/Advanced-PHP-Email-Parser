<?php

/**
 * Enhanced Email Parser with Multiple Features
 * Supports attachments, multiple email processing, logging, and error handling
 */

class EmailParser {
    private $inbox;
    private $hostname;
    private $username;
    private $password;
    private $logger;
    private $config;

    public function __construct($config = []) {
        $this->config = array_merge([
            'hostname' => '{imap.gmail.com:993/imap/ssl}INBOX',
            'username' => '',
            'password' => '',
            'max_emails' => 50,
            'processed_flag' => 'PROCESSED',
            'log_file' => 'email_parser.log',
            'attachment_dir' => 'attachments/',
            'patterns' => $this->getDefaultPatterns()
        ], $config);

        $this->initialize();
    }

    private function initialize() {
        // Create attachment directory if it doesn't exist
        if (!is_dir($this->config['attachment_dir'])) {
            mkdir($this->config['attachment_dir'], 0755, true);
        }
        
        $this->connect();
    }

    private function connect() {
        try {
            $this->inbox = imap_open(
                $this->config['hostname'],
                $this->config['username'],
                $this->config['password'],
                0,
                1,
                ['DISABLE_AUTHENTICATOR' => 'GSSAPI']
            );

            if (!$this->inbox) {
                throw new Exception('Failed to connect: ' . imap_last_error());
            }

            $this->log("Connected successfully to mailbox");
        } catch (Exception $e) {
            $this->log("Connection error: " . $e->getMessage(), 'ERROR');
            throw $e;
        }
    }

    private function getDefaultPatterns() {
        return [
            'name' => [
                'pattern' => "@Hi\s+([^,]+),@i",
                'description' => 'Customer name'
            ],
            'username' => [
                'pattern' => "@NAME:\s*(\d+)@i",
                'description' => 'Username/ID'
            ],
            'access_code' => [
                'pattern' => "@ACCESS?:\s*(\d+)@i",
                'description' => 'Access code'
            ],
            'date' => [
                'pattern' => "@Date?:\s*([^<]+)@i",
                'description' => 'Date'
            ],
            'order_number' => [
                'pattern' => "@Order\s*Number?:\s*(\d+)@i",
                'description' => 'Order number'
            ],
            'total_amount' => [
                'pattern' => "@Total?:\s*([^<]+)@i",
                'description' => 'Total amount'
            ],
            'payment_method' => [
                'pattern' => "@via\s+([^<]+)@i",
                'description' => 'Payment method'
            ],
            'email' => [
                'pattern' => "@Email?:\s*([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})@i",
                'description' => 'Email address'
            ],
            'phone' => [
                'pattern' => "@Phone?:\s*([\d\s\-\+\(\)]+)@i",
                'description' => 'Phone number'
            ],
            'transaction_id' => [
                'pattern' => "@Transaction\s*ID?:\s*([a-zA-Z0-9\-]+)@i",
                'description' => 'Transaction ID'
            ]
        ];
    }

    public function processEmails($searchCriteria = 'UNSEEN') {
        try {
            $emails = imap_search($this->inbox, $searchCriteria);
            
            if (!$emails) {
                $this->log("No emails found with criteria: {$searchCriteria}");
                return [];
            }

            $processedEmails = [];
            $emailCount = min(count($emails), $this->config['max_emails']);

            $this->log("Found {$emailCount} emails to process");

            foreach (array_slice($emails, 0, $emailCount) as $emailNumber) {
                $processedEmails[] = $this->processSingleEmail($emailNumber);
            }

            return $processedEmails;

        } catch (Exception $e) {
            $this->log("Error processing emails: " . $e->getMessage(), 'ERROR');
            return [];
        }
    }

    private function processSingleEmail($emailNumber) {
        $emailData = [
            'email_number' => $emailNumber,
            'processed_at' => date('Y-m-d H:i:s'),
            'extracted_data' => [],
            'attachments' => [],
            'headers' => [],
            'status' => 'processed'
        ];

        try {
            $emailData['headers'] = $this->getEmailHeaders($emailNumber);

            $body = $this->getEmailBody($emailNumber);
            $emailData['body_preview'] = substr(strip_tags($body), 0, 200) . '...';

            $emailData['extracted_data'] = $this->extractDataFromText($body);

            $emailData['attachments'] = $this->processAttachments($emailNumber);

            $this->markEmailAsProcessed($emailNumber);

            $this->log("Successfully processed email #{$emailNumber} from: " . 
                      ($emailData['headers']['from'] ?? 'Unknown'));

        } catch (Exception $e) {
            $emailData['status'] = 'error';
            $emailData['error_message'] = $e->getMessage();
            $this->log("Error processing email #{$emailNumber}: " . $e->getMessage(), 'ERROR');
        }

        return $emailData;
    }

    private function getEmailHeaders($emailNumber) {
        $headers = imap_headerinfo($this->inbox, $emailNumber);
        
        return [
            'from' => $headers->from[0]->mailbox . "@" . $headers->from[0]->host,
            'from_name' => $headers->from[0]->personal ?? '',
            'subject' => $headers->subject,
            'date' => date('Y-m-d H:i:s', $headers->udate),
            'message_id' => $headers->message_id
        ];
    }

    private function getEmailBody($emailNumber) {
        $body = '';
        
        $htmlBody = imap_fetchbody($this->inbox, $emailNumber, 1, FT_PEEK);
        if (!empty($htmlBody)) {
            $body = imap_qprint($htmlBody);
        }

        if (empty(trim(strip_tags($body)))) {
            $structure = imap_fetchstructure($this->inbox, $emailNumber);
            $body = $this->getBodyFromStructure($emailNumber, $structure);
        }

        // Clean up the body
        $body = $this->cleanEmailBody($body);

        return $body;
    }

    private function getBodyFromStructure($emailNumber, $structure, $partNumber = '') {
        $body = '';

        if ($structure->type == 0) {
            $content = imap_fetchbody($this->inbox, $emailNumber, $partNumber ?: 1, FT_PEEK);
            if ($structure->encoding == 4) {
                $content = imap_qprint($content);
            } elseif ($structure->encoding == 3) {
                $content = imap_base64($content);
            }
            
            if ($structure->subtype == 'HTML') {
                $body = strip_tags($content);
            } else {
                $body = $content;
            }
        } elseif ($structure->type == 1) {
            foreach ($structure->parts as $index => $subStruct) {
                $prefix = $partNumber ? $partNumber . '.' : '';
                $body .= $this->getBodyFromStructure($emailNumber, $subStruct, $prefix . ($index + 1));
            }
        }

        return $body;
    }

    private function extractDataFromText($text) {
        $extracted = [];

        foreach ($this->config['patterns'] as $key => $patternConfig) {
            $matches = [];
            if (preg_match($patternConfig['pattern'], $text, $matches)) {
                $extracted[$key] = [
                    'value' => trim($matches[1]),
                    'description' => $patternConfig['description']
                ];
            }
        }

        return $extracted;
    }

    private function processAttachments($emailNumber) {
        $attachments = [];
        $structure = imap_fetchstructure($this->inbox, $emailNumber);

        if (!isset($structure->parts)) {
            return $attachments;
        }

        foreach ($structure->parts as $partIndex => $part) {
            if (isset($part->disposition) && $part->disposition == 'attachment') {
                $attachment = $this->saveAttachment($emailNumber, $partIndex + 1, $part);
                if ($attachment) {
                    $attachments[] = $attachment;
                }
            }
        }

        return $attachments;
    }

    private function saveAttachment($emailNumber, $partNumber, $part) {
        $filename = $this->getAttachmentFilename($part);
        if (!$filename) return null;

        $filepath = $this->config['attachment_dir'] . uniqid() . '_' . $filename;
        $content = imap_fetchbody($this->inbox, $emailNumber, $partNumber, FT_PEEK);
        
        if ($part->encoding == 3) {
            $content = imap_base64($content);
        } elseif ($part->encoding == 4) {
            $content = imap_qprint($content);
        }

        if (file_put_contents($filepath, $content)) {
            return [
                'filename' => $filename,
                'filepath' => $filepath,
                'size' => filesize($filepath),
                'saved_at' => date('Y-m-d H:i:s')
            ];
        }

        return null;
    }

    private function getAttachmentFilename($part) {
        if ($part->ifdparameters) {
            foreach ($part->dparameters as $param) {
                if (strtolower($param->attribute) == 'filename') {
                    return $param->value;
                }
            }
        }

        if ($part->ifparameters) {
            foreach ($part->parameters as $param) {
                if (strtolower($param->attribute) == 'name') {
                    return $param->value;
                }
            }
        }

        return null;
    }

    private function markEmailAsProcessed($emailNumber) {
        imap_setflag_full($this->inbox, $emailNumber, "\\{$this->config['processed_flag']}");
    }

    private function cleanEmailBody($body) {
        $body = preg_replace('/\s+/', ' ', $body);
        $body = html_entity_decode($body);
        return trim($body);
    }

    private function log($message, $level = 'INFO') {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
        
        error_log($logMessage, 3, $this->config['log_file']);
        
        if (php_sapi_name() === 'cli') {
            echo $logMessage;
        }
    }

    public function getMailboxInfo() {
        $info = imap_mailboxmsginfo($this->inbox);
        return [
            'total_emails' => $info->Nmsgs,
            'recent_emails' => $info->Recent,
            'unread_emails' => $info->Unread,
            'size' => round($info->Size / 1024, 2) . ' KB'
        ];
    }

    public function __destruct() {
        if ($this->inbox) {
            imap_close($this->inbox);
            $this->log("Disconnected from mailbox");
        }
    }
}

$config = [
    'hostname' => '{imap.gmail.com:993/imap/ssl}INBOX',
    'username' => 'your_email@gmail.com',
    'password' => 'your_app_password',
    'max_emails' => 10,
    'attachment_dir' => __DIR__ . '/email_attachments/',
    'log_file' => __DIR__ . '/email_parser.log'
];

try {
    $parser = new EmailParser($config);
    
    $mailboxInfo = $parser->getMailboxInfo();
    echo "Mailbox Info: " . json_encode($mailboxInfo, JSON_PRETTY_PRINT) . "\n\n";
    
    $processedEmails = $parser->processEmails('UNSEEN');
    
    foreach ($processedEmails as $email) {
        echo "Processed email from: " . ($email['headers']['from_name'] ?? $email['headers']['from']) . "\n";
        echo "Subject: " . $email['headers']['subject'] . "\n";
        
        if (!empty($email['extracted_data'])) {
            echo "Extracted data:\n";
            foreach ($email['extracted_data'] as $key => $data) {
                echo "  - {$data['description']}: {$data['value']}\n";
            }
        }
        
        if (!empty($email['attachments'])) {
            echo "Attachments:\n";
            foreach ($email['attachments'] as $attachment) {
                echo "  - {$attachment['filename']} ({$attachment['size']} bytes)\n";
            }
        }
        
        echo "Status: {$email['status']}\n";
        echo str_repeat("-", 50) . "\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

?>