<?php
/**
 * LMS Mailer Module
 * This module provides a simple SMTP client to send emails using Gmail or any SMTP server.
 */

class LMSMailer {
    private $host, $port, $user, $pass, $from, $fromName;
    private $socket = null;

    public function __construct() {
        $this->host = defined('SMTP_HOST') ? SMTP_HOST : 'smtp.gmail.com';
        $this->port = defined('SMTP_PORT') ? SMTP_PORT : 465;
        $this->user = defined('SMTP_USER') ? SMTP_USER : '';
        $this->pass = defined('SMTP_PASS') ? SMTP_PASS : '';
        $this->from = defined('SMTP_FROM') ? SMTP_FROM : $this->user;
        $this->fromName = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'LMS System';
    }

    private function get_response($socket) {
        $response = "";
        while ($line = fgets($socket, 512)) {
            $response .= $line;
            if (substr($line, 3, 1) == " ") break;
        }
        return $response;
    }

    private function connect() {
        if ($this->socket) return true;
        if (empty($this->user) || empty($this->pass) || $this->pass == 'apppassword_here') return false;

        $protocol = ($this->port == 465) ? "ssl://" : "";
        // Reduced timeout to 5 seconds to prevent PHP script hanging
        $this->socket = @stream_socket_client($protocol . $this->host . ":" . $this->port, $errno, $errstr, 5);
        
        if (!$this->socket) return false;

        $this->get_response($this->socket); 
        fwrite($this->socket, "EHLO " . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "\r\n");
        $this->get_response($this->socket);

        fwrite($this->socket, "AUTH LOGIN\r\n");
        $this->get_response($this->socket); 

        fwrite($this->socket, base64_encode($this->user) . "\r\n");
        $this->get_response($this->socket); 

        fwrite($this->socket, base64_encode($this->pass) . "\r\n");
        $res = $this->get_response($this->socket);
        if (substr($res, 0, 3) != "235") {
            $this->close();
            return false;
        }
        return true;
    }

    public function send($to, $subject, $message) {
        if (!$this->connect()) return false;

        fwrite($this->socket, "MAIL FROM: <" . $this->from . ">\r\n");
        $this->get_response($this->socket);

        fwrite($this->socket, "RCPT TO: <" . $to . ">\r\n");
        $this->get_response($this->socket);

        fwrite($this->socket, "DATA\r\n");
        $this->get_response($this->socket);

        $headers = "From: " . $this->fromName . " <" . $this->from . ">\r\n";
        $headers .= "To: <" . $to . ">\r\n";
        $headers .= "Subject: " . $subject . "\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "Date: " . date('r') . "\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n\r\n";

        fwrite($this->socket, $headers . $message . "\r\n.\r\n");
        $this->get_response($this->socket);
        return true;
    }

    public function close() {
        if ($this->socket) {
            fwrite($this->socket, "QUIT\r\n");
            fclose($this->socket);
            $this->socket = null;
        }
    }
}

/**
 * Global helper function to send email notifications.
 */
function sendLMSMail($to, $subject, $message) {
    static $static_mailer = null;
    if (!$static_mailer) $static_mailer = new LMSMailer();
    return $static_mailer->send($to, $subject, $message);
}

/**
 * Send notification to a specific group of users.
 */
function notifyUsers($pdo, $role, $batch_id, $subject, $message) {
    // Increase time limit just in case
    @set_time_limit(120);

    $sql = "SELECT email FROM users WHERE 1=1";
    $params = [];

    if ($role != 'all') {
        $sql .= " AND role = ?";
        $params[] = $role;
    }
    
    if ($batch_id) {
        $sql .= " AND id IN (SELECT student_id FROM student_batches WHERE batch_id = ?)";
        $params[] = $batch_id;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll();

    if ($users) {
        $mailer = new LMSMailer();
        foreach ($users as $u) {
            $mailer->send($u['email'], $subject, $message);
        }
        $mailer->close();
    }
}
function notifySpecificUser($pdo, $user_id, $subject, $message) {
    $stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $email = $stmt->fetchColumn();
    if ($email) {
        return sendLMSMail($email, $subject, $message);
    }
    return false;
}
?>
