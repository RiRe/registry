<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;
use Pdp\Domain;
use Pdp\TopLevelDomains;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\Filesystem;
use MatthiasMullie\Scrapbook\Adapters\Flysystem as ScrapbookFlysystem;
use MatthiasMullie\Scrapbook\Psr6\Pool;

/**
 * Sets up and returns a Logger instance.
 * 
 * @param string $logFilePath Full path to the log file.
 * @param string $channelName Name of the log channel (optional).
 * @return Logger
 */
function setupLogger($logFilePath, $channelName = 'app') {
    // Create a log channel
    $log = new Logger($channelName);

    // Set up the console handler
    $consoleHandler = new StreamHandler('php://stdout', Logger::DEBUG);
    $consoleFormatter = new LineFormatter(
        "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
        "Y-m-d H:i:s.u", // Date format
        true, // Allow inline line breaks
        true  // Ignore empty context and extra
    );
    $consoleHandler->setFormatter($consoleFormatter);
    $log->pushHandler($consoleHandler);

    // Set up the file handler
    $fileHandler = new RotatingFileHandler($logFilePath, 0, Logger::DEBUG);
    $fileFormatter = new LineFormatter(
        "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
        "Y-m-d H:i:s.u" // Date format
    );
    $fileHandler->setFormatter($fileFormatter);
    $log->pushHandler($fileHandler);

    return $log;
}

function checkLogin($db, $clID, $pw) {
    $stmt = $db->prepare("SELECT pw FROM registrar WHERE clid = :username");
    $stmt->execute(['username' => $clID]);
    $hashedPassword = $stmt->fetchColumn();

    return password_verify($pw, $hashedPassword);
}

function sendGreeting($conn) {
    global $c;
    $currentDateTime = new DateTime("now", new DateTimeZone("UTC"));
    $currentDate = $currentDateTime->format("Y-m-d\TH:i:s.v\Z");

    $response = [
        'command' => 'greeting',
        'svID' => $c['epp_greeting'],
        'svDate' => $currentDate,
        'version' => '1.0',
        'lang' => 'en',
        'services' => [
            'urn:ietf:params:xml:ns:domain-1.0',
            'urn:ietf:params:xml:ns:contact-1.0',
            'urn:ietf:params:xml:ns:host-1.0'
        ],
        'extensions' => [
            'https://namingo.org/epp/funds-1.0',
            'https://namingo.org/epp/identica-1.0',
            'urn:ietf:params:xml:ns:secDNS-1.1',
            'urn:ietf:params:xml:ns:rgp-1.0',
            'urn:ietf:params:xml:ns:launch-1.0',
            'urn:ietf:params:xml:ns:idn-1.0',
            'urn:ietf:params:xml:ns:epp:fee-1.0',
            'urn:ar:params:xml:ns:price-1.1'
        ],
        'dcp' => [ // Data Collection Policy (optional)
            'access' => ['all'],
            'statement' => [
                'purpose' => ['admin', 'prov'],
                'recipient' => ['ours'],
                'retention' => ['stated']
            ]
        ]
    ];

    $epp = new EPP\EppWriter();
    $xml = $epp->epp_writer($response);
    sendEppResponse($conn, $xml);
}

function sendEppError($conn, $db, $code, $msg, $clTRID = "000", $trans = "0") {
    if (!isset($clTRID)) {
        $clTRID = 'client-not-provided-' . bin2hex(random_bytes(8));
    }
    if (!isset($trans)) {
        $trans = "0";
    }
    $svTRID = generateSvTRID();
    $response = [
        'command' => 'error',
        'resultCode' => $code,
        'human_readable_message' => $msg,
        'clTRID' => $clTRID,
        'svTRID' => $svTRID,
    ];

    $epp = new EPP\EppWriter();
    $xml = $epp->epp_writer($response);
    updateTransaction($db, null, null, 'error', $code, $msg, $svTRID, $xml, $trans);
    sendEppResponse($conn, $xml);
}

function sendEppResponse($conn, $response) {
    $length = strlen($response) + 4; // Total length including the 4-byte header
    $lengthData = pack('N', $length); // Pack the length into 4 bytes

    $conn->send($lengthData . $response);
}

function generateSvTRID() {
    global $c;
    $prefix = $c['epp_prefix'];

    // Get current timestamp
    $timestamp = time();

    // Generate a random 5-character alphanumeric string
    $randomString = bin2hex(random_bytes(5));

    // Combine the prefix, timestamp, and random string to form the svTRID
    $svTRID = "{$prefix}-{$timestamp}-{$randomString}";

    return $svTRID;
}

function getRegistrarClid(Swoole\Database\PDOProxy $db, $id) {
    $stmt = $db->prepare("SELECT clid FROM registrar WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result['clid'] ?? null;  // Return the clid if found, otherwise return null
}

function getContactIdentifier(Swoole\Database\PDOProxy $db, $id) {
    $stmt = $db->prepare("SELECT identifier FROM contact WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result['identifier'] ?? null;  // Return the identifier if found, otherwise return null
}

function getHost(Swoole\Database\PDOProxy $db, $id) {
    $stmt = $db->prepare("SELECT name FROM host WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result['name'] ?? null;  // Return the name if found, otherwise return null
}

function validate_identifier($identifier) {
    if (!$identifier) {
        return 'Please provide a contact ID';
    }

    $length = strlen($identifier);

    if ($length < 3) {
        return 'Identifier type minLength value=3, maxLength value=16';
    }

    if ($length > 16) {
        return 'Identifier type minLength value=3, maxLength value=16';
    }

    $pattern1 = '/^[A-Z]+\-[0-9]+$/';
    $pattern2 = '/^[A-Za-z][A-Z0-9a-z]*$/';

    if (!preg_match($pattern1, $identifier) && !preg_match($pattern2, $identifier)) {
        return 'The ID of the contact must contain letters (A-Z) (ASCII), hyphen (-), and digits (0-9).';
    }
}

function validate_label($label, $pdo) {
    if (!$label) {
        return 'You must enter a domain name';
    }
    if (strlen($label) > 63) {
        return 'Total length of your domain must be less then 63 characters';
    }
    if (strlen($label) < 2) {
        return 'Total length of your domain must be greater then 2 characters';
    }
    if (strpos($label, 'xn--') === false && preg_match("/(^-|^\.|-\.|\.-|--|\.\.|-$|\.$)/", $label)) {
        return 'Invalid domain name format, cannot begin or end with a hyphen (-)';
    }
    
    // Extract TLD from the domain and prepend a dot
    $parts = extractDomainAndTLD($label);
    $tld = "." . $parts['tld'];

    // Check if the TLD exists in the domain_tld table
    $stmtTLD = $pdo->prepare("SELECT COUNT(*) FROM domain_tld WHERE tld = :tld");
    $stmtTLD->bindParam(':tld', $tld, PDO::PARAM_STR);
    $stmtTLD->execute();
    $tldExists = $stmtTLD->fetchColumn();

    if (!$tldExists) {
        return 'Zone is not supported';
    }

    // Fetch the IDN regex for the given TLD
    $stmtRegex = $pdo->prepare("SELECT idn_table FROM domain_tld WHERE tld = :tld");
    $stmtRegex->bindParam(':tld', $tld, PDO::PARAM_STR);
    $stmtRegex->execute();
    $idnRegex = $stmtRegex->fetchColumn();

    if (!$idnRegex) {
        return 'Failed to fetch domain IDN table';
    }

    if (strpos($parts['domain'], 'xn--') === 0) {
        $label = idn_to_utf8($parts['domain'], IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
    }

    // Check for invalid characters using fetched regex
    if (!preg_match($idnRegex, $label)) {
        $server->send($fd, "Domain name invalid format");
        return 'Invalid domain name format, please review registry policy about accepted labels';
    }
}

function extractDomainAndTLD($urlString) {
    global $c;
    
    $cachePath = '/var/www/cp/cache'; // Cache directory
    $adapter = new LocalFilesystemAdapter($cachePath, null, LOCK_EX);
    $filesystem = new Filesystem($adapter);
    $cache = new Pool(new ScrapbookFlysystem($filesystem));
    $cacheKey = 'tlds_alpha_by_domain';
    $cachedFile = $cache->getItem($cacheKey);
    $fileContent = $cachedFile->get();

    // Load a list of test TLDs used in your QA environment
    $testTlds = explode(',', $c['test_tlds']);

    // Parse the URL to get the host
    $parts = parse_url($urlString);
    $host = $parts['host'] ?? $urlString;
    
    // Sort test TLDs by length (longest first) to match the longest possible TLD
    usort($testTlds, function ($a, $b) {
        return strlen($b) - strlen($a);
    });

    // Check if the TLD is a known test TLD
    foreach ($testTlds as $testTld) {
        if (str_ends_with($host, "$testTld")) {
            // Handle the test TLD case
            $tldLength = strlen($testTld); // No +1 for the dot
            $hostWithoutTld = substr($host, 0, -$tldLength);
            $hostParts = explode('.', $hostWithoutTld);
            $sld = array_pop($hostParts);
            if (strpos($testTld, '.') === 0) {
                $testTld = ltrim($testTld, '.');
            }
            return [
                'domain' => implode('.', $hostParts) ? implode('.', $hostParts) . '.' . $sld : $sld, 
                'tld' => $testTld
            ];
        }
    }

    // Use the PHP Domain Parser library for real TLDs
    $tlds = TopLevelDomains::fromString($fileContent);
    $domain = Domain::fromIDNA2008($host);
    $result = $tlds->resolve($domain);
    $sld = $result->secondLevelDomain()->toString();
    $tld = $result->suffix()->toString();

    return ['domain' => $sld, 'tld' => $tld];
}

function normalize_v4_address($v4) {
    // Remove leading zeros from the first octet
    $v4 = preg_replace('/^0+(\d)/', '$1', $v4);
    
    // Remove leading zeros from successive octets
    $v4 = preg_replace('/\.0+(\d)/', '.$1', $v4);

    return $v4;
}

function normalize_v6_address($v6) {
    // Upper case any alphabetics
    $v6 = strtoupper($v6);
    
    // Remove leading zeros from the first word
    $v6 = preg_replace('/^0+([\dA-F])/', '$1', $v6);
    
    // Remove leading zeros from successive words
    $v6 = preg_replace('/:0+([\dA-F])/', ':$1', $v6);
    
    // Introduce a :: if there isn't one already
    if (strpos($v6, '::') === false) {
        $v6 = preg_replace('/:0:0:/', '::', $v6);
    }

    // Remove initial zero word before a ::
    $v6 = preg_replace('/^0+::/', '::', $v6);
    
    // Remove other zero words before a ::
    $v6 = preg_replace('/(:0)+::/', '::', $v6);

    // Remove zero words following a ::
    $v6 = preg_replace('/:(:0)+/', ':', $v6);

    return $v6;
}

function createTransaction($db, $clid, $clTRID, $clTRIDframe) {
    // Prepare the statement for insertion
    $stmt = $db->prepare("INSERT INTO registryTransaction.transaction_identifier (registrar_id,clTRID,clTRIDframe,cldate,clmicrosecond) VALUES(?,?,?,?,?)");

    // Get date and microsecond for cl transaction
    $currentDateTime = new DateTime("now", new DateTimeZone("UTC"));
    $cldate = $currentDateTime->format("Y-m-d H:i:s.v");
    $dateForClTransaction = microtime(true);
    $clmicrosecond = sprintf("%06d", ($dateForClTransaction - floor($dateForClTransaction)) * 1000000);

    if (empty($clTRID)) {
        // If $clTRID is empty, generate a random string prefixed with "client-not-provided-"
        $clTRID = 'client-not-provided-' . bin2hex(random_bytes(8));  // Generates a 16 character hexadecimal string
    }

    if (empty($clid)) {
        // If $clid is empty, throw an exception
        throw new Exception("Malformed command received.");
    }

    // Execute the statement
    if (!$stmt->execute([
        $clid,
        $clTRID,
        $clTRIDframe,
        $cldate,
        $clmicrosecond
    ])) {
        throw new Exception("Failed to execute createTransaction: " . implode(", ", $stmt->errorInfo()));
    }

    // Return the ID of the newly created transaction
    return $db->lastInsertId();
}

function updateTransaction($db, $cmd, $obj_type, $obj_id, $code, $msg, $svTRID, $svTRIDframe, $transaction_id) {
    // Prepare the statement
    $stmt = $db->prepare("UPDATE registryTransaction.transaction_identifier SET cmd = ?, obj_type = ?, obj_id = ?, code = ?, msg = ?, svTRID = ?, svTRIDframe = ?, svdate = ?, svmicrosecond = ? WHERE id = ?");

    // Get date and microsecond for sv transaction
    $currentDateTime = new DateTime("now", new DateTimeZone("UTC"));
    $svdate = $currentDateTime->format("Y-m-d H:i:s.v");
    $dateForSvTransaction = microtime(true);
    $svmicrosecond = sprintf("%06d", ($dateForSvTransaction - floor($dateForSvTransaction)) * 1000000);

    // Execute the statement
    if (!$stmt->execute([
        $cmd,
        $obj_type,
        $obj_id,
        $code,
        $msg,
        $svTRID,
        $svTRIDframe,
        $svdate,
        $svmicrosecond,
        $transaction_id
    ])) {
        throw new Exception("Failed to execute updateTransaction: " . implode(", ", $stmt->errorInfo()));
    }

    return true;
}

function getClid(Swoole\Database\PDOProxy $db, string $clid): ?int {
    $stmt = $db->prepare("SELECT id FROM registrar WHERE clid = :clid LIMIT 1");
    $stmt->bindParam(':clid', $clid, PDO::PARAM_STR);
    $stmt->execute();
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $result ? (int)$result['id'] : null;
}

/**
 * Calculate ds-rdata from dnskey-rdata
 * For additional information please refer to RFC 5910: http://www.ietf.org/rfc/rfc5910.txt
 * 
 * @param string owner, the coanonical name of the owner (e.g. example.com.)
 * @param int flags, the flags of the dnskey (only 256 or 257)
 * @param int protocol, the protocol of the dnskey (only 3)
 * @param int algoritm, the algorithm of the dnskey (only 3, 5, 6, 7, 8, 10, 12, 13 or 14)
 * @param string publickey, the full publickey base64 encoded (care, no spaces allowed)
 * 
 * @return array, on success
 *   Array (
 *     [owner] => $owner
 *     [keytag] => $keytag
 *     [algorithm] => $algorithm
 *     [digest] => Array (
 *       [] => Array (
 *         [type] => 1
 *         [hash] => $digest_sha1
 *       ),
 *       [] => Array (
 *         [type] => 2
 *         [hash] => $digest_sha256
 *       )
 *     )
 *   )
 * @return int < 0, on failure
 *   -1, unsupported owner
 *   -2, unsupported flags
 *   -3, unsupported protocol
 *   -4, unsupported algorithm
 *   -5, unsupported publickey
 */
function dnssec_key2ds($owner, $flags, $protocol, $algorithm, $publickey) {
    // define paramenter check variants
    $regex_owner = '/^[a-z0-9\-]+\.[a-z]+\.$/';
    $allowed_flags = array(256, 257);
    $allowed_protocol = array(3);
    $allowed_algorithm = array(2, 3, 5, 6, 7, 8, 10, 13, 14, 15, 16);
    $regex_publickey = '/^(?:[A-Za-z0-9+\/]{4})*(?:[A-Za-z0-9+\/]{2}==|[A-Za-z0-9+\/]{3}=|[A-Za-z0-9+\/]{4})$/';
    
    // do parameter checks and break if failed
    if(!preg_match($regex_owner, $owner)) return -1;
    if(!in_array($flags, $allowed_flags)) return -2;
    if(!in_array($protocol, $allowed_protocol)) return -3;
    if(!in_array($algorithm, $allowed_algorithm)) return -4;
    if(!preg_match($regex_publickey, $publickey)) return -5;
    
    // calculate hex of parameters
    $owner_hex = '';
    $parts = explode(".", substr($owner, 0, -1));
    foreach ($parts as $part) {
        $len = dechex(strlen($part));
        $owner_hex .= str_repeat('0', 2 - strlen($len)).$len;
        $part = str_split($part);
        for ($i = 0; $i < count($part); $i++) {
            $byte = strtoupper(dechex(ord($part[$i])));
            $byte = str_repeat('0', 2 - strlen($byte)).$byte;
            $owner_hex .= $byte;
        }
    }
    $owner_hex .= '00';
    $flags_hex = sprintf("%04d", dechex($flags));
    $protocol_hex = sprintf("%02d", dechex($protocol));
    $algorithm_hex = sprintf("%02d", dechex($algorithm));
    $publickey_hex = bin2hex(base64_decode($publickey));
    
    // calculate keytag using algorithm defined in rfc
    $string = hex2bin($flags_hex.$protocol_hex.$algorithm_hex.$publickey_hex);
    $sum = 0;
    for($i = 0; $i < strlen($string); $i++) {
        $b = ord($string[$i]);
        $sum += ($i & 1) ? $b : $b << 8;
    }
    $keytag = 0xffff & ($sum + ($sum >> 16));
    
    // calculate digest using rfc specified hashing algorithms
    $string = hex2bin($owner_hex.$flags_hex.$protocol_hex.$algorithm_hex.$publickey_hex);
    $digest_sha1 = strtoupper(sha1($string));
    $digest_sha256 = strtoupper(hash('sha256', $string));
    
    // return results and also copied parameters
    return array(
        //'debug' => array($owner_hex, $flags_hex, $protocol_hex, $algorithm_hex, $publickey_hex),
        'owner' => $owner,
        'keytag' => $keytag,
        'algorithm' => $algorithm,
        'digest' => array(
            array(
                'type' => 1,
                'hash' => $digest_sha1
            ),
            array(
                'type' => 2,
                'hash' => $digest_sha256
            )
        )
    );
}

// Function to update the permitted IPs from the database
function updatePermittedIPs($pool, $permittedIPsTable) {
    $pdo = $pool->get();
    $query = "SELECT addr FROM registrar_whitelist";
    $stmt = $pdo->query($query);
    $permittedIPs = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    $pool->put($pdo);

    // Manually clear the table by removing each entry
    foreach ($permittedIPsTable as $key => $value) {
        $permittedIPsTable->del($key);
    }

    // Insert new values
    foreach ($permittedIPs as $ip) {
        $permittedIPsTable->set($ip, ['addr' => $ip]);
    }
}

function getDomainPrice($pdo, $domain_name, $tld_id, $date_add = 12, $command = 'create') {
    // Check if the domain is a premium domain
    $stmt = $pdo->prepare("
        SELECT c.category_price 
        FROM premium_domain_pricing p
        JOIN premium_domain_categories c ON p.category_id = c.category_id
        WHERE p.domain_name = ? AND p.tld_id = ?
    ");
    $stmt->execute([$domain_name, $tld_id]);
    if ($stmt->rowCount() > 0) {
        return ['type' => 'premium', 'price' => number_format((float)$stmt->fetch()['category_price'], 2, '.', '')];
    }

    // Check if there is a promotion for the domain
    $currentDate = date('Y-m-d');
    $stmt = $pdo->prepare("
        SELECT discount_percentage, discount_amount 
        FROM promotion_pricing 
        WHERE tld_id = ? 
        AND promo_type = 'full' 
        AND status = 'active' 
        AND start_date <= ? 
        AND end_date >= ?
    ");
    $stmt->execute([$tld_id, $currentDate, $currentDate]);
    if ($stmt->rowCount() > 0) {
        $promo = $stmt->fetch();
        $discount = null;
        
        // Determine discount based on percentage or amount
        if (!empty($promo['discount_percentage'])) {
            $discount = $promo['discount_percentage']; // Percentage discount
        } elseif (!empty($promo['discount_amount'])) {
            $discount = $promo['discount_amount']; // Fixed amount discount
        }
    } else {
        $discount = null;
    }

    // Get regular price for the specified period
    $priceColumn = "m" . $date_add;
    $stmt = $pdo->prepare("SELECT $priceColumn FROM domain_price WHERE tldid = ? AND command = '$command' LIMIT 1");
    $stmt->execute([$tld_id]);
    
    if ($stmt->rowCount() > 0) {
        $regularPrice = $stmt->fetch()[$priceColumn];

        if ($discount !== null) {
            if (isset($promo['discount_percentage'])) {
                $discountAmount = $regularPrice * ($promo['discount_percentage'] / 100);
            } else {
                $discountAmount = $discount;
            }
            $price = $regularPrice - $discountAmount;
            return ['type' => 'promotion', 'price' => number_format((float)$price, 2, '.', '')];
        }
        
        return ['type' => 'regular', 'price' => number_format((float)$regularPrice, 2, '.', '')];
    }

    return ['type' => 'not_found', 'price' => 0];
}