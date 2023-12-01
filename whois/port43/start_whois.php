<?php
// Include the Swoole extension
if (!extension_loaded('swoole')) {
    die('Swoole extension must be installed');
}

require_once 'helpers.php';
$logFilePath = '/var/log/namingo/whois.log';
$log = setupLogger($logFilePath, 'WHOIS');

// Create a Swoole TCP server
$server = new Swoole\Server('0.0.0.0', 43);
$server->set([
    'daemonize' => false,
    'log_file' => '/var/log/namingo/whois_application.log',
    'log_level' => SWOOLE_LOG_INFO,
    'worker_num' => swoole_cpu_num() * 2,
    'pid_file' => '/var/run/whois.pid',
    'max_request' => 1000,
    'dispatch_mode' => 2,
    'open_tcp_nodelay' => true,
    'max_conn' => 1024,
    'heartbeat_check_interval' => 60,
    'heartbeat_idle_time' => 120,
    'buffer_output_size' => 2 * 1024 * 1024, // 2MB
    'enable_reuse_port' => true,
    'package_max_length' => 8192, // 8KB
    'open_eof_check' => true,
    'package_eof' => "\r\n"
]);
$log->info('server started.');

// Connect to the database
try {
    $c = require_once 'config.php';
    $pdo = new PDO("{$c['db_type']}:host={$c['db_host']};dbname={$c['db_database']}", $c['db_username'], $c['db_password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    $log->error('DB Connection failed: ' . $e->getMessage());
    $server->send($fd, "Error connecting to database");
    $server->close($fd);
}

// Register a callback to handle incoming connections
$server->on('connect', function ($server, $fd) use ($log) {
    $log->info('new client connected: ' . $fd);
});

// Register a callback to handle incoming requests
$server->on('receive', function ($server, $fd, $reactorId, $data) use ($c, $pdo, $log) {
    $privacy = $c['privacy'];
    
    // Validate and sanitize the data
    $data = trim($data);
    
    // Check if the query is for a nameserver
    if (strpos($data, 'nameserver ') === 0) {
        $queryType = 'nameserver';
        $queryData = str_replace('nameserver ', '', $data);
    }
    // Check if the query is for a registrar
    elseif (strpos($data, 'registrar ') === 0) {
        $queryType = 'registrar';
        $queryData = str_replace('registrar ', '', $data);
    }
    // If none of the above, assume it's a domain query
    else {
        $queryType = 'domain';
        $queryData = $data;
    }
    
    // Handle the WHOIS query
    if ($queryType == 'nameserver') {
        // Handle nameserver query
        $nameserver = $queryData;
        
        if (!$nameserver) {
            $server->send($fd, "please enter a nameserver");
            $server->close($fd);
        }
        if (strlen($nameserver) > 63) {
            $server->send($fd, "nameserver is too long");
            $server->close($fd);
        }
        
        if (!preg_match('/^([a-zA-Z0-9\-]+\.)+[a-zA-Z]{2,}$/', $nameserver)) {
            $server->send($fd, "Nameserver contains invalid characters or is not in the correct format.");
            $server->close($fd);
        }
        
        // Perform the WHOIS lookup
        try {
            $query = "SELECT name,clid FROM host WHERE name = :nameserver";
            $stmt = $pdo->prepare($query);
            $stmt->bindParam(':nameserver', $nameserver, PDO::PARAM_STR);
            $stmt->execute();

            if ($f = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $res = "Server Name: ".$f['name'];
                    
            // Fetch the registrar details for this registrar using the id
            $regQuery = "SELECT id,name,iana_id,whois_server,url,abuse_email,abuse_phone FROM registrar WHERE id = :clid";
            $regStmt = $pdo->prepare($regQuery);
            $regStmt->bindParam(':clid', $f['clid'], PDO::PARAM_INT);
            $regStmt->execute();

            if ($registrar = $regStmt->fetch(PDO::FETCH_ASSOC)) {
                // Append the registrar details to the response
                $res .= "\nRegistrar Name: ".$registrar['name'];
                $res .= "\nRegistrar WHOIS Server: ".$registrar['whois_server'];
                $res .= "\nRegistrar URL: ".$registrar['url'];
                $res .= "\nRegistrar IANA ID: ".$registrar['iana_id'];
                $res .= "\nRegistrar Abuse Contact Email: ".$registrar['abuse_email'];
                $res .= "\nRegistrar Abuse Contact Phone: ".$registrar['abuse_phone'];
            }
                    
                $res .= "\nURL of the ICANN Whois Inaccuracy Complaint Form: https://www.icann.org/wicf/";
                $currentDateTime = new DateTime();
                $currentTimestamp = $currentDateTime->format("Y-m-d\TH:i:s.v\Z");
                $res .= "\n>>> Last update of WHOIS database: {$currentTimestamp} <<<";
                $res .= "\n";
                $res .= "\nFor more information on Whois status codes, please visit https://icann.org/epp";
                $res .= "\n\n";
                $res .= "Access to WHOIS information is provided to assist persons in"
                ."\ndetermining the contents of a domain name registration record in the"
                ."\nDomain Name Registry registry database. The data in this record is provided by"
                ."\nDomain Name Registry for informational purposes only, and Domain Name Registry does not"
                ."\nguarantee its accuracy.  This service is intended only for query-based"
                ."\naccess. You agree that you will use this data only for lawful purposes"
                ."\nand that, under no circumstances will you use this data to: (a) allow,"
                ."\nenable, or otherwise support the transmission by e-mail, telephone, or"
                ."\nfacsimile of mass unsolicited, commercial advertising or solicitations"
                ."\nto entities other than the data recipient's own existing customers; or"
                ."\n(b) enable high volume, automated, electronic processes that send"
                ."\nqueries or data to the systems of Registry Operator, a Registrar, or"
                ."\nNIC except as reasonably necessary to register domain names or"
                ."\nmodify existing registrations. All rights reserved. Domain Name Registry reserves"
                ."\nthe right to modify these terms at any time. By submitting this query,"
                ."\nyou agree to abide by this policy."
                ."\n";
                $server->send($fd, $res . "");
                
                $clientInfo = $server->getClientInfo($fd);
                $remoteAddr = $clientInfo['remote_ip'];
                $log->notice('new request from ' . $remoteAddr . ' | ' . $nameserver . ' | FOUND');
                
                try {
                    $stmt = $pdo->prepare("UPDATE settings SET value = value + 1 WHERE name = :name");
                    $settingName = 'whois-43-queries';
                    $stmt->bindParam(':name', $settingName);
                    $stmt->execute();
                } catch (PDOException $e) {
                    $log->error('DB Connection failed: ' . $e->getMessage());
                    $server->send($fd, "Error connecting to the whois database");
                    $server->close($fd);
                } catch (Throwable $e) {
                    $log->error('Error: ' . $e->getMessage());
                    $server->send($fd, "General error");
                    $server->close($fd);
                }

                $server->close($fd);
            } else {
                //NOT FOUND or No match for;
                $server->send($fd, "NOT FOUND");
                
                $clientInfo = $server->getClientInfo($fd);
                $remoteAddr = $clientInfo['remote_ip'];
                $log->notice('new request from ' . $remoteAddr . ' | ' . $nameserver . ' | NOT FOUND');
                
                try {
                    $stmt = $pdo->prepare("UPDATE settings SET value = value + 1 WHERE name = :name");
                    $settingName = 'whois-43-queries';
                    $stmt->bindParam(':name', $settingName);
                    $stmt->execute();
                } catch (PDOException $e) {
                    $log->error('DB Connection failed: ' . $e->getMessage());
                    $server->send($fd, "Error connecting to the whois database");
                    $server->close($fd);
                } catch (Throwable $e) {
                    $log->error('Error: ' . $e->getMessage());
                    $server->send($fd, "General error");
                    $server->close($fd);
                }
                
                $server->close($fd);
            }
            
    } catch (PDOException $e) {
        $log->error('DB Connection failed: ' . $e->getMessage());
        $server->send($fd, "Error connecting to the whois database");
        $server->close($fd);
    } catch (Throwable $e) {
        $log->error('Error: ' . $e->getMessage());
        $server->send($fd, "General error");
        $server->close($fd);
    }
    
    } 
    elseif ($queryType == 'registrar') {
        // Handle registrar query
        $registrar = $queryData;
        
        if (!$registrar) {
            $server->send($fd, "please enter a registrar name");
            $server->close($fd);
        }
        if (strlen($registrar) > 50) {
            $server->send($fd, "registrar name is too long");
            $server->close($fd);
        }
        
        if (!preg_match('/^[a-zA-Z0-9\s\-]+$/', $registrar)) {
            $server->send($fd, "Registrar name contains invalid characters.");
            $server->close($fd);
        }
        
        // Perform the WHOIS lookup
        try {
            $query = "SELECT id,name,iana_id,whois_server,url,abuse_email,abuse_phone FROM registrar WHERE name = :registrar";
            $stmt = $pdo->prepare($query);
            $stmt->bindParam(':registrar', $registrar, PDO::PARAM_STR);
            $stmt->execute();

            if ($f = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $res = "Registrar: ".$f['name']
                    ."\nRegistrar WHOIS Server: ".$f['whois_server']
                    ."\nRegistrar URL: ".$f['url']
                    ."\nRegistrar IANA ID: ".$f['iana_id']
                    ."\nRegistrar Abuse Contact Email: ".$f['abuse_email']
                    ."\nRegistrar Abuse Contact Phone: ".$f['abuse_phone'];
                    
            // Fetch the contact details for this registrar using the id
            $contactQuery = "SELECT * FROM registrar_contact WHERE id = :registrar_id";
            $contactStmt = $pdo->prepare($contactQuery);
            $contactStmt->bindParam(':registrar_id', $f['id'], PDO::PARAM_INT);
            $contactStmt->execute();

            if ($contact = $contactStmt->fetch(PDO::FETCH_ASSOC)) {
                // Append the contact details to the response
                $res .= "\nStreet: " . $contact['street1'];
                $res .= "\nCity: " . $contact['city'];
                $res .= "\nPostal Code: " . $contact['pc'];
                $res .= "\nCountry: " . $contact['cc'];
                $res .= "\nPhone: " . $contact['voice'];
                $res .= "\nFax: " . $contact['fax'];
                $res .= "\nPublic Email: " . $contact['email'];
            }
                    
                $res .= "\nURL of the ICANN Whois Inaccuracy Complaint Form: https://www.icann.org/wicf/";
                $currentDateTime = new DateTime();
                $currentTimestamp = $currentDateTime->format("Y-m-d\TH:i:s.v\Z");
                $res .= "\n>>> Last update of WHOIS database: {$currentTimestamp} <<<";
                $res .= "\n";
                $res .= "\nFor more information on Whois status codes, please visit https://icann.org/epp";
                $res .= "\n\n";
                $res .= "Access to WHOIS information is provided to assist persons in"
                ."\ndetermining the contents of a domain name registration record in the"
                ."\nDomain Name Registry registry database. The data in this record is provided by"
                ."\nDomain Name Registry for informational purposes only, and Domain Name Registry does not"
                ."\nguarantee its accuracy.  This service is intended only for query-based"
                ."\naccess. You agree that you will use this data only for lawful purposes"
                ."\nand that, under no circumstances will you use this data to: (a) allow,"
                ."\nenable, or otherwise support the transmission by e-mail, telephone, or"
                ."\nfacsimile of mass unsolicited, commercial advertising or solicitations"
                ."\nto entities other than the data recipient's own existing customers; or"
                ."\n(b) enable high volume, automated, electronic processes that send"
                ."\nqueries or data to the systems of Registry Operator, a Registrar, or"
                ."\nNIC except as reasonably necessary to register domain names or"
                ."\nmodify existing registrations. All rights reserved. Domain Name Registry reserves"
                ."\nthe right to modify these terms at any time. By submitting this query,"
                ."\nyou agree to abide by this policy."
                ."\n";
                $server->send($fd, $res . "");
                
                $clientInfo = $server->getClientInfo($fd);
                $remoteAddr = $clientInfo['remote_ip'];
                $log->notice('new request from ' . $remoteAddr . ' | ' . $registrar . ' | FOUND');
                
                try {
                    $stmt = $pdo->prepare("UPDATE settings SET value = value + 1 WHERE name = :name");
                    $settingName = 'whois-43-queries';
                    $stmt->bindParam(':name', $settingName);
                    $stmt->execute();
                } catch (PDOException $e) {
                    $log->error('DB Connection failed: ' . $e->getMessage());
                    $server->send($fd, "Error connecting to the whois database");
                    $server->close($fd);
                } catch (Throwable $e) {
                    $log->error('Error: ' . $e->getMessage());
                    $server->send($fd, "General error");
                    $server->close($fd);
                }
                
                $server->close($fd);
            } else {
                //NOT FOUND or No match for;
                $server->send($fd, "NOT FOUND");
                
                $clientInfo = $server->getClientInfo($fd);
                $remoteAddr = $clientInfo['remote_ip'];
                $log->notice('new request from ' . $remoteAddr . ' | ' . $registrar . ' | NOT FOUND');
                
                try {
                    $stmt = $pdo->prepare("UPDATE settings SET value = value + 1 WHERE name = :name");
                    $settingName = 'whois-43-queries';
                    $stmt->bindParam(':name', $settingName);
                    $stmt->execute();
                } catch (PDOException $e) {
                    $log->error('DB Connection failed: ' . $e->getMessage());
                    $server->send($fd, "Error connecting to the whois database");
                    $server->close($fd);
                } catch (Throwable $e) {
                    $log->error('Error: ' . $e->getMessage());
                    $server->send($fd, "General error");
                    $server->close($fd);
                }
                
                $server->close($fd);
            }
            
    } catch (PDOException $e) {
        $log->error('DB Connection failed: ' . $e->getMessage());
        $server->send($fd, "Error connecting to the whois database");
        $server->close($fd);
    } catch (Throwable $e) {
        $log->error('Error: ' . $e->getMessage());
        $server->send($fd, "General error");
        $server->close($fd);
    }

    } 
    else {
        // Handle domain query
        $domain = $queryData;
        
        if (!$domain) {
            $server->send($fd, "please enter a domain name");
            $server->close($fd);
        }
        if (strlen($domain) > 68) {
            $server->send($fd, "domain name is too long");
            $server->close($fd);
        }
        $domain = strtoupper($domain);
        if (preg_match("/(^-|^\.|-\.|\.-|--|\.\.|-$|\.$)/", $domain)) {
            $server->send($fd, "domain name invalid format");
            $server->close($fd);
        }
    
        // Extract TLD from the domain and prepend a dot
        $parts = explode('.', $domain);
        $tld = "." . end($parts);

        // Check if the TLD exists in the domain_tld table
        $stmtTLD = $pdo->prepare("SELECT COUNT(*) FROM domain_tld WHERE tld = :tld");
        $stmtTLD->bindParam(':tld', $tld, PDO::PARAM_STR);
        $stmtTLD->execute();
        $tldExists = $stmtTLD->fetchColumn();

        if (!$tldExists) {
            $server->send($fd, "Invalid TLD. Please search only allowed TLDs");
            $server->close($fd);
            return;
        }
        
        // Check if domain is reserved
        $stmtReserved = $pdo->prepare("SELECT id FROM reserved_domain_names WHERE name = ? LIMIT 1");
        $stmtReserved->execute([$parts[0]]);
        $domain_already_reserved = $stmtReserved->fetchColumn();

        if ($domain_already_reserved) {
            $server->send($fd, "Domain name is reserved or restricted");
            $server->close($fd);
            return;
        }

        // Fetch the IDN regex for the given TLD
        $stmtRegex = $pdo->prepare("SELECT idn_table FROM domain_tld WHERE tld = :tld");
        $stmtRegex->bindParam(':tld', $tld, PDO::PARAM_STR);
        $stmtRegex->execute();
        $idnRegex = $stmtRegex->fetchColumn();

        if (!$idnRegex) {
            $server->send($fd, "Failed to fetch domain IDN table");
            $server->close($fd);
            return;
        }

        // Check for invalid characters using fetched regex
        if (!preg_match($idnRegex, $domain)) {
            $server->send($fd, "Domain name invalid format");
            $server->close($fd);
            return;
        }

        // Perform the WHOIS lookup
        try {
            $query = "SELECT * FROM registry.domain WHERE name = :domain";
            $stmt = $pdo->prepare($query);
            $stmt->bindParam(':domain', $domain, PDO::PARAM_STR);
            $stmt->execute();

            if ($f = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $f['crdate'] = (new DateTime($f['crdate']))->format('Y-m-d\TH:i:s.v\Z');
                if (isset($f['update']) && $f['update'] !== null) {
                    $f['update'] = (new DateTime($f['update']))->format('Y-m-d\TH:i:s.v\Z');
                } else {
                    $f['update'] = '';
                }
                $f['exdate'] = (new DateTime($f['exdate']))->format('Y-m-d\TH:i:s.v\Z');
                
                $query2 = "SELECT tld FROM domain_tld WHERE id = :tldid";
                $stmt2 = $pdo->prepare($query2);
                $stmt2->bindParam(':tldid', $f['tldid'], PDO::PARAM_INT);
                $stmt2->execute();

                $tld = $stmt2->fetch(PDO::FETCH_ASSOC);
            
                $query3 = "SELECT name,iana_id,whois_server,url,abuse_email,abuse_phone FROM registrar WHERE id = :clid";
                $stmt3 = $pdo->prepare($query3);
                $stmt3->bindParam(':clid', $f['clid'], PDO::PARAM_INT);
                $stmt3->execute();

                $clidF = $stmt3->fetch(PDO::FETCH_ASSOC);

                $res = "Domain Name: ".strtoupper($f['name'])
                    ."\nRegistry Domain ID: D".$f['id']."-".$c['roid']
                    ."\nRegistrar WHOIS Server: ".$clidF['whois_server']
                    ."\nRegistrar URL: ".$clidF['url']
                    ."\nUpdated Date: ".$f['update']
                    ."\nCreation Date: ".$f['crdate']
                    ."\nRegistry Expiry Date: ".$f['exdate']
                    ."\nRegistrar: ".$clidF['name']
                    ."\nRegistrar IANA ID: ".$clidF['iana_id']
                    ."\nRegistrar Abuse Contact Email: ".$clidF['abuse_email']
                    ."\nRegistrar Abuse Contact Phone: ".$clidF['abuse_phone'];
                    
                $query4 = "SELECT status FROM domain_status WHERE domain_id = :domain_id";
                $stmt4 = $pdo->prepare($query4);
                $stmt4->bindParam(':domain_id', $f['id'], PDO::PARAM_INT);
                $stmt4->execute();

                while ($f2 = $stmt4->fetch(PDO::FETCH_ASSOC)) {
                    $res .= "\nDomain Status: " . $f2['status'] . " https://icann.org/epp#" . $f2['status'];
                }

                $query5 = "SELECT contact.identifier,contact_postalInfo.name,contact_postalInfo.org,contact_postalInfo.street1,contact_postalInfo.street2,contact_postalInfo.street3,contact_postalInfo.city,contact_postalInfo.sp,contact_postalInfo.pc,contact_postalInfo.cc,contact.voice,contact.fax,contact.email
                FROM contact,contact_postalInfo WHERE contact.id=:registrant AND contact_postalInfo.contact_id=contact.id";
                $stmt5 = $pdo->prepare($query5);
                $stmt5->bindParam(':registrant', $f['registrant'], PDO::PARAM_INT);
                $stmt5->execute();

                $f2 = $stmt5->fetch(PDO::FETCH_ASSOC);
                if ($privacy) {
                $res .= "\nRegistry Registrant ID: REDACTED FOR PRIVACY"
                    ."\nRegistrant Name: REDACTED FOR PRIVACY"
                    ."\nRegistrant Organization: REDACTED FOR PRIVACY"
                    ."\nRegistrant Street: REDACTED FOR PRIVACY"
                    ."\nRegistrant Street: REDACTED FOR PRIVACY"
                    ."\nRegistrant Street: REDACTED FOR PRIVACY"
                    ."\nRegistrant City: REDACTED FOR PRIVACY"
                    ."\nRegistrant State/Province: REDACTED FOR PRIVACY"
                    ."\nRegistrant Postal Code: REDACTED FOR PRIVACY"
                    ."\nRegistrant Country: REDACTED FOR PRIVACY"
                    ."\nRegistrant Phone: REDACTED FOR PRIVACY"
                    ."\nRegistrant Fax: REDACTED FOR PRIVACY"
                    ."\nRegistrant Email: Kindly refer to the RDDS server associated with the identified registrar in this output to obtain contact details for the Registrant, Admin, or Tech associated with the queried domain name.";
                } else {
                $res .= "\nRegistry Registrant ID: C".$f2['identifier']."-".$c['roid']
                    ."\nRegistrant Name: ".$f2['name']
                    ."\nRegistrant Organization: ".$f2['org']
                    ."\nRegistrant Street: ".$f2['street1']
                    ."\nRegistrant Street: ".$f2['street2']
                    ."\nRegistrant Street: ".$f2['street3']
                    ."\nRegistrant City: ".$f2['city']
                    ."\nRegistrant State/Province: ".$f2['sp']
                    ."\nRegistrant Postal Code: ".$f2['pc']
                    ."\nRegistrant Country: ".$f2['cc']
                    ."\nRegistrant Phone: ".$f2['voice']
                    ."\nRegistrant Fax: ".$f2['fax']
                    ."\nRegistrant Email: ".$f2['email'];
                }

                $query6 = "SELECT contact.identifier,contact_postalInfo.name,contact_postalInfo.org,contact_postalInfo.street1,contact_postalInfo.street2,contact_postalInfo.street3,contact_postalInfo.city,contact_postalInfo.sp,contact_postalInfo.pc,contact_postalInfo.cc,contact.voice,contact.fax,contact.email
                FROM domain_contact_map,contact,contact_postalInfo WHERE domain_contact_map.domain_id=:domain_id AND domain_contact_map.type='admin' AND domain_contact_map.contact_id=contact.id AND domain_contact_map.contact_id=contact_postalInfo.contact_id";
                $stmt6 = $pdo->prepare($query6);
                $stmt6->bindParam(':domain_id', $f['id'], PDO::PARAM_INT);
                $stmt6->execute();

                $f2 = $stmt6->fetch(PDO::FETCH_ASSOC);
                if ($privacy) {
                $res .= "\nRegistry Admin ID: REDACTED FOR PRIVACY"
                    ."\nAdmin Name: REDACTED FOR PRIVACY"
                    ."\nAdmin Organization: REDACTED FOR PRIVACY"
                    ."\nAdmin Street: REDACTED FOR PRIVACY"
                    ."\nAdmin Street: REDACTED FOR PRIVACY"
                    ."\nAdmin Street: REDACTED FOR PRIVACY"
                    ."\nAdmin City: REDACTED FOR PRIVACY"
                    ."\nAdmin State/Province: REDACTED FOR PRIVACY"
                    ."\nAdmin Postal Code: REDACTED FOR PRIVACY"
                    ."\nAdmin Country: REDACTED FOR PRIVACY"
                    ."\nAdmin Phone: REDACTED FOR PRIVACY"
                    ."\nAdmin Fax: REDACTED FOR PRIVACY"
                    ."\nAdmin Email: Kindly refer to the RDDS server associated with the identified registrar in this output to obtain contact details for the Registrant, Admin, or Tech associated with the queried domain name.";
                } else {
                $res .= "\nRegistry Admin ID: C".$f2['identifier']."-".$c['roid']
                    ."\nAdmin Name: ".$f2['name']
                    ."\nAdmin Organization: ".$f2['org']
                    ."\nAdmin Street: ".$f2['street1']
                    ."\nAdmin Street: ".$f2['street2']
                    ."\nAdmin Street: ".$f2['street3']
                    ."\nAdmin City: ".$f2['city']
                    ."\nAdmin State/Province: ".$f2['sp']
                    ."\nAdmin Postal Code: ".$f2['pc']
                    ."\nAdmin Country: ".$f2['cc']
                    ."\nAdmin Phone: ".$f2['voice']
                    ."\nAdmin Fax: ".$f2['fax']
                    ."\nAdmin Email: ".$f2['email'];
                }

                $query7 = "SELECT contact.identifier,contact_postalInfo.name,contact_postalInfo.org,contact_postalInfo.street1,contact_postalInfo.street2,contact_postalInfo.street3,contact_postalInfo.city,contact_postalInfo.sp,contact_postalInfo.pc,contact_postalInfo.cc,contact.voice,contact.fax,contact.email
                FROM domain_contact_map,contact,contact_postalInfo WHERE domain_contact_map.domain_id=:domain_id AND domain_contact_map.type='billing' AND domain_contact_map.contact_id=contact.id AND domain_contact_map.contact_id=contact_postalInfo.contact_id";
                $stmt7 = $pdo->prepare($query7);
                $stmt7->bindParam(':domain_id', $f['id'], PDO::PARAM_INT);
                $stmt7->execute();

                $f2 = $stmt7->fetch(PDO::FETCH_ASSOC);
                if ($privacy) {
                $res .= "\nRegistry Billing ID: REDACTED FOR PRIVACY"
                    ."\nBilling Name: REDACTED FOR PRIVACY"
                    ."\nBilling Organization: REDACTED FOR PRIVACY"
                    ."\nBilling Street: REDACTED FOR PRIVACY"
                    ."\nBilling Street: REDACTED FOR PRIVACY"
                    ."\nBilling Street: REDACTED FOR PRIVACY"
                    ."\nBilling City: REDACTED FOR PRIVACY"
                    ."\nBilling State/Province: REDACTED FOR PRIVACY"
                    ."\nBilling Postal Code: REDACTED FOR PRIVACY"
                    ."\nBilling Country: REDACTED FOR PRIVACY"
                    ."\nBilling Phone: REDACTED FOR PRIVACY"
                    ."\nBilling Fax: REDACTED FOR PRIVACY"
                    ."\nBilling Email: Kindly refer to the RDDS server associated with the identified registrar in this output to obtain contact details for the Registrant, Admin, or Tech associated with the queried domain name.";
                } else {
                $res .= "\nRegistry Billing ID: C".$f2['identifier']."-".$c['roid']
                    ."\nBilling Name: ".$f2['name']
                    ."\nBilling Organization: ".$f2['org']
                    ."\nBilling Street: ".$f2['street1']
                    ."\nBilling Street: ".$f2['street2']
                    ."\nBilling Street: ".$f2['street3']
                    ."\nBilling City: ".$f2['city']
                    ."\nBilling State/Province: ".$f2['sp']
                    ."\nBilling Postal Code: ".$f2['pc']
                    ."\nBilling Country: ".$f2['cc']
                    ."\nBilling Phone: ".$f2['voice']
                    ."\nBilling Fax: ".$f2['fax']
                    ."\nBilling Email: ".$f2['email'];
                }

                $query8 = "SELECT contact.identifier,contact_postalInfo.name,contact_postalInfo.org,contact_postalInfo.street1,contact_postalInfo.street2,contact_postalInfo.street3,contact_postalInfo.city,contact_postalInfo.sp,contact_postalInfo.pc,contact_postalInfo.cc,contact.voice,contact.fax,contact.email
                FROM domain_contact_map,contact,contact_postalInfo WHERE domain_contact_map.domain_id=:domain_id AND domain_contact_map.type='tech' AND domain_contact_map.contact_id=contact.id AND domain_contact_map.contact_id=contact_postalInfo.contact_id";
                $stmt8 = $pdo->prepare($query8);
                $stmt8->bindParam(':domain_id', $f['id'], PDO::PARAM_INT);
                $stmt8->execute();

                $f2 = $stmt8->fetch(PDO::FETCH_ASSOC);
                if ($privacy) {
                $res .= "\nRegistry Tech ID: REDACTED FOR PRIVACY"
                    ."\nTech Name: REDACTED FOR PRIVACY"
                    ."\nTech Organization: REDACTED FOR PRIVACY"
                    ."\nTech Street: REDACTED FOR PRIVACY"
                    ."\nTech Street: REDACTED FOR PRIVACY"
                    ."\nTech Street: REDACTED FOR PRIVACY"
                    ."\nTech City: REDACTED FOR PRIVACY"
                    ."\nTech State/Province: REDACTED FOR PRIVACY"
                    ."\nTech Postal Code: REDACTED FOR PRIVACY"
                    ."\nTech Country: REDACTED FOR PRIVACY"
                    ."\nTech Phone: REDACTED FOR PRIVACY"
                    ."\nTech Fax: REDACTED FOR PRIVACY"
                    ."\nTech Email: Kindly refer to the RDDS server associated with the identified registrar in this output to obtain contact details for the Registrant, Admin, or Tech associated with the queried domain name.";
                } else {
                $res .= "\nRegistry Tech ID: C".$f2['identifier']."-".$c['roid']
                    ."\nTech Name: ".$f2['name']
                    ."\nTech Organization: ".$f2['org']
                    ."\nTech Street: ".$f2['street1']
                    ."\nTech Street: ".$f2['street2']
                    ."\nTech Street: ".$f2['street3']
                    ."\nTech City: ".$f2['city']
                    ."\nTech State/Province: ".$f2['sp']
                    ."\nTech Postal Code: ".$f2['pc']
                    ."\nTech Country: ".$f2['cc']
                    ."\nTech Phone: ".$f2['voice']
                    ."\nTech Fax: ".$f2['fax']
                    ."\nTech Email: ".$f2['email'];
                }

                $query9 = "SELECT name FROM domain_host_map,host WHERE domain_host_map.domain_id = :domain_id AND domain_host_map.host_id = host.id";
                $stmt9 = $pdo->prepare($query9);
                $stmt9->bindParam(':domain_id', $f['id'], PDO::PARAM_INT);
                $stmt9->execute();

                $counter = 0;
                while ($counter < 13) {
                    $f2 = $stmt9->fetch(PDO::FETCH_ASSOC);
                    if ($f2 === false) break; // Break if there are no more rows
                     $res .= "\nName Server: ".$f2['name'];
                     $counter++;
                }

                $query_dnssec = "SELECT EXISTS(SELECT 1 FROM secdns WHERE domain_id = :domain_id)";
                $stmt_dnssec = $pdo->prepare($query_dnssec);
                $stmt_dnssec->bindParam(':domain_id', $f['id'], PDO::PARAM_INT);
                $stmt_dnssec->execute();

                $dnssec_exists = $stmt_dnssec->fetchColumn();

                if ($dnssec_exists) {
                    $res .= "\nDNSSEC: signedDelegation";
                } else {
                    $res .= "\nDNSSEC: unsigned";
                }
                $res .= "\nURL of the ICANN Whois Inaccuracy Complaint Form: https://www.icann.org/wicf/";
                $currentDateTime = new DateTime();
                $currentTimestamp = $currentDateTime->format("Y-m-d\TH:i:s.v\Z");
                $res .= "\n>>> Last update of WHOIS database: {$currentTimestamp} <<<";
                $res .= "\n";
                $res .= "\nFor more information on Whois status codes, please visit https://icann.org/epp";
                $res .= "\n\n";
                $res .= "Access to {$tld['tld']} WHOIS information is provided to assist persons in"
                ."\ndetermining the contents of a domain name registration record in the"
                ."\nDomain Name Registry registry database. The data in this record is provided by"
                ."\nDomain Name Registry for informational purposes only, and Domain Name Registry does not"
                ."\nguarantee its accuracy.  This service is intended only for query-based"
                ."\naccess. You agree that you will use this data only for lawful purposes"
                ."\nand that, under no circumstances will you use this data to: (a) allow,"
                ."\nenable, or otherwise support the transmission by e-mail, telephone, or"
                ."\nfacsimile of mass unsolicited, commercial advertising or solicitations"
                ."\nto entities other than the data recipient's own existing customers; or"
                ."\n(b) enable high volume, automated, electronic processes that send"
                ."\nqueries or data to the systems of Registry Operator, a Registrar, or"
                ."\nNIC except as reasonably necessary to register domain names or"
                ."\nmodify existing registrations. All rights reserved. Domain Name Registry reserves"
                ."\nthe right to modify these terms at any time. By submitting this query,"
                ."\nyou agree to abide by this policy."
                ."\n";
                $server->send($fd, $res . "");
                
                $clientInfo = $server->getClientInfo($fd);
                $remoteAddr = $clientInfo['remote_ip'];
                $log->notice('new request from ' . $remoteAddr . ' | ' . $domain . ' | FOUND');
                
                try {
                    $stmt = $pdo->prepare("UPDATE settings SET value = value + 1 WHERE name = :name");
                    $settingName = 'whois-43-queries';
                    $stmt->bindParam(':name', $settingName);
                    $stmt->execute();
                } catch (PDOException $e) {
                    $log->error('DB Connection failed: ' . $e->getMessage());
                    $server->send($fd, "Error connecting to the whois database");
                    $server->close($fd);
                } catch (Throwable $e) {
                    $log->error('Error: ' . $e->getMessage());
                    $server->send($fd, "General error");
                    $server->close($fd);
                }
                
                $server->close($fd);
            } else {
                //NOT FOUND or No match for;
                $server->send($fd, "NOT FOUND");
                
                $clientInfo = $server->getClientInfo($fd);
                $remoteAddr = $clientInfo['remote_ip'];
                $log->notice('new request from ' . $remoteAddr . ' | ' . $domain . ' | NOT FOUND');
                
                try {
                    $stmt = $pdo->prepare("UPDATE settings SET value = value + 1 WHERE name = :name");
                    $settingName = 'whois-43-queries';
                    $stmt->bindParam(':name', $settingName);
                    $stmt->execute();
                } catch (PDOException $e) {
                    $log->error('DB Connection failed: ' . $e->getMessage());
                    $server->send($fd, "Error connecting to the whois database");
                    $server->close($fd);
                } catch (Throwable $e) {
                    $log->error('Error: ' . $e->getMessage());
                    $server->send($fd, "General error");
                    $server->close($fd);
                }
                
                $server->close($fd);
            }
    } catch (PDOException $e) {
        $log->error('DB Connection failed: ' . $e->getMessage());
        $server->send($fd, "Error connecting to the whois database");
        $server->close($fd);
    } catch (Throwable $e) {
        $log->error('Error: ' . $e->getMessage());
        $server->send($fd, "General error");
        $server->close($fd);
    }
    }

    // Close the connection
    $pdo = null;
});

// Register a callback to handle client disconnections
$server->on('close', function ($server, $fd) use ($log) {
    $log->info('client ' . $fd . ' connected.');
});

// Start the server
$server->start();