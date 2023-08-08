<?php

$hostname = '{your_imap_server:993/imap/ssl}INBOX';
$username = 'your_email@example.com';
$password = 'your_password';

// Connect to mailbox
$inbox = imap_open($hostname, $username, $password) or die('Cannot connect to mailbox: ' . imap_last_error());

// Search for emails from the two URS providers
$emailsFromProviderA = imap_search($inbox, 'FROM "providerA@example.com" UNSEEN');
$emailsFromProviderB = imap_search($inbox, 'FROM "providerB@example.com" UNSEEN');

// Combine the arrays of email IDs
$allEmails = array_merge($emailsFromProviderA, $emailsFromProviderB);

// Connect to the database using PDO
$pdo = new PDO("mysql:host=your_host;dbname=your_db", "db_username", "db_password");

foreach ($allEmails as $emailId) {
    $header = imap_headerinfo($inbox, $emailId);
    $from = $header->from[0]->mailbox . "@" . $header->from[0]->host;
    $subject = $header->subject;
    $date = date('Y-m-d H:i:s', strtotime($header->date));

    // Determine the URS provider based on the email sender
    $ursProvider = ($from == 'providerA@example.com') ? 'URSPA' : 'URSPB';

    // Extract domain name or relevant info from the email (you'd need more specific code here based on the email content)
    $body = imap_fetchbody($inbox, $emailId, 1);
    $domainName = extractDomainNameFromEmail($body); // You'd have to define this function

    // Insert into the database
    $stmt = $pdo->prepare("INSERT INTO urs_actions (domain_name, urs_provider, action_date, status) VALUES (?, ?, ?, ?)");
    $stmt->execute([$domainName, $ursProvider, $date, 'Suspended']);
}

imap_close($inbox);

function extractDomainNameFromEmail($emailBody) {
    // Placeholder function; you'd extract the domain name based on the email format/content
    // This is just a basic example
    preg_match("/domain: (.*?) /i", $emailBody, $matches);
    return $matches[1] ?? '';
}