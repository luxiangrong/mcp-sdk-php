<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2025 Logiscape LLC <https://logiscape.com>
 *
 * Developed by:
 * - Josh Abbott
 * - Claude 3.7 Sonnet (Anthropic AI model)
 * - ChatGPT o1 pro mode
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @package    logiscape/mcp-sdk-php 
 * @author     Josh Abbott <https://joshabbott.com>
 * @copyright  Logiscape LLC
 * @license    MIT License
 * @link       https://github.com/logiscape/mcp-sdk-php
 *
 * Filename: Server/HttpServerRunner.php
 */

declare(strict_types=1);

namespace Mcp\Server;

use Mcp\Server\Transport\HttpServerTransport;
use Mcp\Server\Transport\Http\HttpMessage;
use Mcp\Server\Transport\Http\Environment;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Runner for HTTP-based MCP servers.
 * 
 * This class extends the base ServerRunner to provide specific
 * functionality for running MCP servers over HTTP.
 */
class HttpServerRunner extends ServerRunner
{
    /**
     * Host for built-in server.
     *
     * @var string
     */
    private string $host;
    
    /**
     * Port for built-in server.
     *
     * @var int
     */
    private int $port;
    
    /**
     * HTTP transport instance.
     *
     * @var HttpServerTransport
     */
    private HttpServerTransport $transport;
    
    /**
     * Server session instance.
     *
     * @var ServerSession|null
     */
    private ?ServerSession $serverSession = null;
    
    /**
     * Constructor.
     *
     * @param Server $server MCP server instance
     * @param InitializationOptions $initOptions Server initialization options
     * @param array $httpOptions HTTP transport options
     * @param LoggerInterface|null $logger Logger
     */
    public function __construct(
        private readonly Server $server,
        private readonly InitializationOptions $initOptions,
        array $httpOptions = [],
        ?LoggerInterface $logger = null
    ) {
        // Set default host and port from options
        $this->host = $httpOptions['host'] ?? 'localhost';
        $this->port = (int)($httpOptions['port'] ?? 8080);
        
        // Create HTTP transport
        $this->transport = new HttpServerTransport($httpOptions);
        
        parent::__construct($server, $initOptions, $logger ?? new NullLogger());
    }
    
    /**
     * Run the server.
     *
     * @return void
     */
    public function run(): void
    {
        // Suppress warnings unless explicitly enabled
        if (!getenv('MCP_ENABLE_WARNINGS')) {
            error_reporting(E_ERROR | E_PARSE);
        }
        
        try {
            // Start the transport
            $this->transport->start();
            
            // Create server session
            $this->serverSession = new ServerSession(
                $this->transport,
                $this->initOptions,
                $this->logger
            );
            
            // Connect server to session
            $this->server->setSession($this->serverSession);
            
            // Add handlers
            $this->serverSession->registerHandlers($this->server->getHandlers());
            $this->serverSession->registerNotificationHandlers($this->server->getNotificationHandlers());
            
            // Start session
            $this->serverSession->start();
            
            $this->logger->info('HTTP Server started');
            
            // If running in CLI mode, start the built-in server
            if (Environment::isCliMode() && php_sapi_name() === 'cli') {
                $this->startBuiltInServer();
            } else {
                // For non-CLI environments, just setup the transport and return
                // The actual handling happens via handleRequest() calls
                $this->logger->info('HTTP Server ready for requests');
            }
        } catch (\Exception $e) {
            $this->logger->error('Server error: ' . $e->getMessage());
            $this->stop();
            throw $e;
        }
    }
    
    /**
     * Handle an HTTP request.
     *
     * @param HttpMessage|null $request Request message (created from globals if null)
     * @return HttpMessage Response message
     */
    public function handleRequest(?HttpMessage $request = null): HttpMessage
    {
        if ($this->serverSession === null) {
            throw new \RuntimeException('Server session not initialized');
        }
        
        // Create request from globals if not provided
        if ($request === null) {
            $request = HttpMessage::fromGlobals();
        }
        
        // Process the request
        $response = $this->transport->handleRequest($request);
        
        return $response;
    }
    
    /**
     * Send an HTTP response.
     *
     * @param HttpMessage $response Response message
     * @return void
     */
    public function sendResponse(HttpMessage $response): void
    {
        // Send headers
        http_response_code($response->getStatusCode());
        
        foreach ($response->getHeaders() as $name => $value) {
            header("$name: $value");
        }
        
        // Send body
        $body = $response->getBody();
        if ($body !== null) {
            echo $body;
        }
    }
    
    /**
     * Stop the server.
     *
     * @return void
     */
    public function stop(): void
    {
        if ($this->serverSession !== null) {
            try {
                $this->serverSession->close();
            } catch (\Exception $e) {
                $this->logger->error('Error while stopping server session: ' . $e->getMessage());
            }
            $this->serverSession = null;
        }
        
        try {
            $this->transport->stop();
        } catch (\Exception $e) {
            $this->logger->error('Error while stopping transport: ' . $e->getMessage());
        }
        
        $this->logger->info('HTTP Server stopped');
    }
    
    /**
     * Get the transport instance.
     *
     * @return HttpServerTransport Transport instance
     */
    public function getTransport(): HttpServerTransport
    {
        return $this->transport;
    }
    
    /**
     * Get the server session.
     *
     * @return ServerSession|null Server session
     */
    public function getServerSession(): ?ServerSession
    {
        return $this->serverSession;
    }
    
    /**
     * Start built-in PHP web server for development.
     *
     * @return void
     */
    private function startBuiltInServer(): void
    {
        $this->logger->info(sprintf('Starting built-in server at http://%s:%d', $this->host, $this->port));
        
        if (!function_exists('pcntl_fork')) {
            $this->logger->error('PCNTL extension not available, cannot start built-in server');
            return;
        }
        
        // Create router file if it doesn't exist
        $routerPath = sys_get_temp_dir() . '/mcp_http_router.php';
        if (!file_exists($routerPath)) {
            $routerCode = <<<PHP
<?php
// MCP HTTP Server Router
\$requestUri = parse_url(\$_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Handle MCP endpoint
if (\$requestUri === '/mcp') {
    require __DIR__ . '/mcp_http_runner.php';
    exit;
}

// Return 404 for other paths
http_response_code(404);
echo 'Not found';
PHP;
            file_put_contents($routerPath, $routerCode);
        }
        
        // Create runner file
        $runnerPath = sys_get_temp_dir() . '/mcp_http_runner.php';
        $runnerCode = <<<PHP
<?php
// Access global runner instance
global \$mcpHttpRunner;

if (!\$mcpHttpRunner) {
    http_response_code(500);
    echo 'MCP HTTP Server not initialized';
    exit;
}

try {
    \$response = \$mcpHttpRunner->handleRequest();
    \$mcpHttpRunner->sendResponse(\$response);
} catch (Exception \$e) {
    http_response_code(500);
    echo 'Internal server error: ' . \$e->getMessage();
}
PHP;
        file_put_contents($runnerPath, $runnerCode);
        
        // Store global reference to this runner instance
        $GLOBALS['mcpHttpRunner'] = $this;
        
        // Fork process to run the built-in server
        $pid = pcntl_fork();
        
        if ($pid === -1) {
            // Fork failed
            $this->logger->error('Failed to fork process for built-in server');
        } elseif ($pid === 0) {
            // Child process - run the built-in server
            $command = sprintf(
                'php -S %s:%d %s',
                $this->host,
                $this->port,
                escapeshellarg($routerPath)
            );
            
            // Redirect stdout and stderr to /dev/null
            $command .= ' > /dev/null 2>&1';
            
            // Execute server
            exec($command);
            exit;
        } else {
            // Parent process - continue
            $this->logger->info(sprintf('Built-in server started at http://%s:%d (PID: %d)', $this->host, $this->port, $pid));
            
            // Register shutdown function to stop the server
            register_shutdown_function(function () use ($pid) {
                $this->logger->info('Stopping built-in server');
                posix_kill($pid, SIGTERM);
            });
            
            // Wait for incoming requests
            while (true) {
                // Process any pending messages
                $message = $this->transport->readMessage();
                if ($message !== null) {
                    // Handle message
                    $this->serverSession->handleIncomingMessage($message);
                }
                
                // Sleep to avoid busy waiting
                usleep(100000); // 100ms
            }
        }
    }
}
