<?php
namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Grav;
use Grav\Common\Plugin;
use Grav\Common\Filesystem\Folder;
use Grav\Framework\Psr7\Response;
use RocketTheme\Toolbox\Event\Event;

use GuzzleHttp\Client;
use SQLite3;

/**
 * Class IPBlacklistPlugin
 * @package Grav\Plugin
 */
class IPBlacklistPlugin extends Plugin
{

    public static $db;

    /**
     * @return array
     *
     * The getSubscribedEvents() gives the core a list of events
     *     that the plugin wants to listen to. The key of each
     *     array section is the event that the plugin listens to
     *     and the value (in the form of an array) contains the
     *     callable (or function) as well as the priority. The
     *     higher the number the higher the priority.
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => [
                // Uncomment following line when plugin requires Grav < 1.7
                // ['autoload', 100000],
                ['onPluginsInitialized', 0]
            ]
        ];
    }

    /**
     * Composer autoload
     *
     * @return ClassLoader
     */
    public function autoload(): ClassLoader
    {
        return require __DIR__ . '/vendor/autoload.php';
    }

    /**
     * Initialize the plugin
     */
    public function onPluginsInitialized(): void
    {
        // Enable the main events we are interested in
        $this->enable([
            'onRequestHandlerInit' => ['onRequestHandlerInit', 0],
            'onSchedulerInitialized' => ['onSchedulerInitialized', 0],
        ]);

        // Only load admin pages if required
        if (!$this->isAdmin() || !$this->grav['user']->authenticated) {
            return;
        }

        $this->enable([
            'onAdminMenu' => ['onAdminMenu', 0],
            'onAdminTwigTemplatePaths' => ['onAdminTwigTemplatePaths', 0],
        ]);

        return;
    }

    /**
     * Handle admin page requests, and filter requests if desired
     */
    public function onRequestHandlerInit($request, $handler)
    {
        // Handle admin page requests
        $path = $request->getRoute()->getRoute();

        if (
            $this->isAdmin()
            && $this->grav['user']->authenticated
            && $path == '/admin/ip-blacklist/data'
        ) {
            $body = (array) $request->getRequest()->getParsedBody();
            if (!isset($body['action'])) {
                $response = new Response(400, [], json_encode(['Error' => 'No action']));
                $request->setResponse($response);
                return;
            }

            // Make appropriate DB query
            $db = $this->getDatabase();
            switch ($body['action']) {
                case 'last-25':
                    $stmt = $db->prepare('SELECT ("ip") FROM "local" ORDER BY "rowid" DESC LIMIT 25');
                    $result = $stmt->execute();
                    $data = [];
                    while ($row = $result->fetchArray(SQLITE3_NUM)) {
                        array_push($data, $row[0]);
                    }
                    break;

                case 'stats':
                    $stmt = $db->prepare('SELECT COUNT(1) FROM "local"');
                    $result = $stmt->execute();
                    $data = [
                        "Number of IPs" => $result->fetchArray(SQLITE3_NUM)[0],
                        "Size on Disk" => $this->human_filesize(filesize(
                                $this->grav['locator']->findResource('user://data/ip-blacklist/blacklists.sqlite', true)
                            )),
                    ];
                    break;

                case 'search':
                    $stmt = $db->prepare('SELECT ("ip") FROM "local" WHERE "ip"=:ip LIMIT 1');
                    $stmt->bindValue(':ip', $body['ip'], SQLITE3_TEXT);
                    $result = $stmt->execute();
                    $data = (int)$result->fetchArray(SQLITE3_NUM);
                    break;

                case 'add':
                    $stmt = $db->prepare('INSERT INTO "local" ("ip") VALUES (:ip)');
                    $stmt->bindValue(':ip', $body['ip'], SQLITE3_TEXT);
                    $data = (int)(bool)$stmt->execute();
                    break;

                case 'remove':
                    $stmt = $db->prepare('DELETE FROM "local" WHERE ("ip" = :ip)');
                    $stmt->bindValue(':ip', $body['ip'], SQLITE3_TEXT);
                    $data = (int)(bool)$stmt->execute();
                    break;

                default:
                    $response = new Response(400, [], json_encode(['Error' => 'Invalid action']));
                    $request->setResponse($response);
                    return;
            }

            // Parse results and send response
            $response = new Response(200, [], json_encode($data));
            $request->setResponse($response);
            return;
        }

        // Filter incoming requests
        $config = $this->config();
        if (!$config['enable_filtering'] && !$config['enable_blacklisting']) {
            return;
        }
        $ip = $this->getRequestIp();

        // Blacklisting
        if ($config['enable_blacklisting']) {
            if ($this->queryBlacklists($ip)) {
                $this->rejectRequest($request);
                return;
            }
        }

        // Filtering
        if ($config['enable_filtering']) {
            $uri = $this->grav['uri'];
            foreach ($config['filters'] as $filter) {
                // Found abusive request
                if (preg_match('~'.$filter['pattern'].'~', $uri)) {
                    $this->addIpToBlacklist($ip);
                    if ($config['enable_reporting']) {
                        $this->reportIp($ip, $path);
                    }
                    if ($config['enable_blacklisting']) {
                        $this->rejectRequest($request);
                        return;
                    }
                    break;
                }
            }
        }
    }

    /**
     * Add auto_cache event to scheduler
     */
    public function onSchedulerInitialized(Event $e)
    {
        $config = $this->config();
        $scheduler = $e['scheduler'];
        if ($config['enable_auto_clean']) {
            $job = $scheduler->addFunction('Grav\Plugin\IPBlacklistPlugin::cleanBlacklists', [], 'ip-blacklist-auto-clean');
            $job->backlink('plugins/ip-blacklist');
            $job->at('35 2 * * *');
        }
        if ($config['enable_auto_cache'] && $config['enable_blacklisting'] && $config['sources']['abuseipdb']) {
            $job = $scheduler->addFunction('Grav\Plugin\IPBlacklistPlugin::updateAbuseipdbBlacklist', [], 'ip-blacklist-auto-cache');
            $job->backlink('plugins/ip-blacklist');
            $job->at('45 2 * * *');
        }
    }

    /**
     * Add page to admin plugin menu
     */
    public function onAdminMenu()
    {
        $options = [
            'hint' => 'IP Blacklist',
            'route' => 'ip-blacklist',
            'icon' => 'fa-ban',
            'authorize' => 'admin.users',
        ];
        $this->grav['twig']->plugins_hooked_nav['IP Blacklist'] = $options;
    }

    /**
     * Add admin page resources to admin twig templates
     */
    public function onAdminTwigTemplatePaths($event)
    {
        if (in_array($this->grav['uri']->route(), ['/admin/ip-blacklist'])) {
            $event['paths'] = array_merge(
                $event['paths'],
                [__DIR__.'/admin/templates']
            );
        }
        return $event;
    }

    /**
     * Add an IP to the local blacklist
     */
    public function addIpToBlacklist(string $ip)
    {
        $db = $this->getDatabase();
        $stmt = $db->prepare('INSERT OR IGNORE INTO "local" ("ip") VALUES (:ip)');
        $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
        $stmt->execute();
    }

    /**
     * Query blacklists for IP
     */
    public function queryBlacklists(string $ip): bool
    {
        $config = $this->config();
        $db = $this->getDatabase();

        // Ensure AbuseIPDB blacklist is in-date
        if ($config['sources']['abuseipdb']) {
            $result = $db->query('SELECT ("updated") FROM "updated" WHERE "table" = "abuseipdb" LIMIT 1');
            $updated = $result->fetchArray()['updated'];
            if ($updated < time()-86400) {
                $this->updateAbuseipdbBlacklist();
            }
        }

        foreach ($config['sources'] as $source => $enabled) {
            if ($enabled) {
                $stmt = $db->prepare("SELECT 1 FROM {$source} WHERE ip=:ip");
                $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
                $result = $stmt->execute();
                if (!empty($result->fetchArray())) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Updates the AbuseIPDB blacklist using the AbuseIPDB API
     */
    public static function updateAbuseipdbBlacklist()
    {
        $db = self::getDatabase();
        $config = Grav::instance()['config']->get('plugins.ip-blacklist');
        if (!isset($config['abuseipdb_key'])) {
            Grav::instance()['log']->debug('Cannot update AbuseIPDB blacklist without API key.');
            return;
        }

        // Fetch plaintext blacklist
        $client = new Client([
            'base_uri' => 'https://api.abuseipdb.com/api/v2/'
        ]);
        $response = $client->request('GET', 'blacklist', [
            // TODO: implement customisable blacklist params e.g. exceptCountries, limit, confidenceMinimum
            'query' => [
                'limit' => '500000',
            ],
            'headers' => [
                'Accept' => 'text/plain',
                'Key' => $config['abuseipdb_key'],
            ],
        ]);
        if ($response->getStatusCode() != 200) {
            // TODO: update plugin config to disable AbuseIPDB if api unauthorised
            Grav::instance()['log']->debug('Could not update AbuseIPDB blacklist: '.$response->getBody());
            return;
        }
        $result = $response->getBody();

        // Delete existing rows and add new ones iteratively
        $db->exec('DELETE FROM "abuseipdb"');
        $stmt = $db->prepare('INSERT OR IGNORE INTO "abuseipdb" ("ip") VALUES (:ip)');
        $stmt->bindParam(':ip', $line, SQLITE3_TEXT);
        $line = strtok($result, "\r\n");
        while ($line !== false) {
            $stmt->execute();
            $line = strtok("\r\n");
        }
        // Update updated table entry
        $updated = time();
        $stmt = $db->prepare('INSERT OR REPLACE INTO "updated" ("table","updated") VALUES ("abuseipdb",:updated)');
        $stmt->bindValue(':updated', $updated, SQLITE3_INTEGER);
        $stmt->execute();
    }

    /**
     * Report IP to AbuseIPDB
     */
    public function reportIp(string $ip, string $path)
    {
        $config = $this->config();
        $client = new Client([
            'base_uri' => 'https://api.abuseipdb.com/api/v2/'
        ]);
        $response = $client->request('POST', 'report', [
            'form_params' => [
                'ip' => $ip,
                'categories' => '21',
                'comment' => 'Probe for vulnerabilities. Path attempted: '.$path,
            ],
            'headers' => [
                'Accept' => 'application/json',
                'Key' => $config['abuseipdb_key'],
            ],
        ]);
        if ($response->getStatusCode() == 200) {
            return;
        } else {
            // TODO: change plugin config to disable reporting if unauthorised api response
            $this->grav['log']->debug('AbuseIPDB report failed ('.$ip.'): '.$response->getBody());
        }
    }

    /**
     * Returns a reference to the database, creating and opening it if required
     */
    public static function getDatabase(): SQLite3
    {
        // Return reference to database if already initialised
        if (isset(self::$db)) {
            return self::$db;
        }
        // Get/create plugin data folder
        $data_dir = Grav::instance()['locator']->findResource('user://data', true).'/ip-blacklist';
        if (!file_exists($data_dir)) {
            Folder::create($data_dir);
        }
        // Get/create db and timestamps
        self::$db = new SQLite3($data_dir.'/blacklists.sqlite');
        self::$db->exec('CREATE TABLE IF NOT EXISTS "local" ("ip" TEXT NOT NULL UNIQUE, PRIMARY KEY("ip"))');
        self::$db->exec('CREATE TABLE IF NOT EXISTS "abuseipdb" ("ip" TEXT NOT NULL UNIQUE, PRIMARY KEY("ip"))');
        self::$db->exec('CREATE TABLE IF NOT EXISTS "updated" ("table" TEXT NOT NULL UNIQUE, "updated" INT NOT NULL DEFAULT 0, PRIMARY KEY("table"))');
        self::$db->exec('INSERT OR IGNORE INTO "updated" ("table") VALUES ("local"),("abuseipdb")');

        return self::$db;
    }

    /**
     * Trim local blacklist to size and vacuum database
     */
    public static function cleanBlacklists()
    {
        // Get config details
        $config = Grav::instance()['config']->get('plugins.ip-blacklist');
        $limit = $config['auto_clean_len'];

        // Trim local blacklist
        $db = self::getDatabase();
        $stmt = $db->prepare('DELETE FROM "local" WHERE "rowid" NOT IN (SELECT "rowid" FROM "local" ORDER BY "rowid" DESC LIMIT :limit)');
        $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
        $stmt->execute();

        // Vacuum blacklist db
        $db->exec('VACUUM');
    }

    /**
     * Reject request with appropriate HTTP response
     */
    function rejectRequest($request)
    {
        $text = [
            400 => 'Bad Request',
            403 => 'Forbidden',
            418 => 'I\'m a teapot',
            503 => 'Service Unavailable',
        ];
        $config = $this->config();
        $response = new Response($config['response'], [], $text[$config['response']]);
        $request->setResponse($response);
    }

    /**
     * Get the IP of a user, even if they are behind cloudflare
     * https://github.com/francodacosta/grav-plugin-page-stats/blame/47ff58a7de94860244ffe24c2cb82bc83841ce85/page-stats.php#L99
     */
    function getRequestIp(): string
    {
        if (isset($_SERVER["HTTP_CF_CONNECTING_IP"])) {
            $_SERVER['REMOTE_ADDR'] = $_SERVER["HTTP_CF_CONNECTING_IP"];
            $_SERVER['HTTP_CLIENT_IP'] = $_SERVER["HTTP_CF_CONNECTING_IP"];
        }
        $client  = @$_SERVER['HTTP_CLIENT_IP'];
        $forward = @$_SERVER['HTTP_X_FORWARDED_FOR'];
        $remote  = $_SERVER['REMOTE_ADDR'];

        if (filter_var($client, FILTER_VALIDATE_IP)) {
            $ip = $client;
        } elseif (filter_var($forward, FILTER_VALIDATE_IP)) {
            $ip = $forward;
        } else {
            $ip = $remote;
        }
        return $ip;
    }

    function human_filesize($bytes, $decimals = 0) {
        $sz = 'BKMGTP';
        $factor = floor((strlen($bytes) - 1) / 3);
        return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . @$sz[$factor];
    }

}
