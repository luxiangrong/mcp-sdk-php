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
use Mcp\Server\Transport\Http\SessionStoreInterface;
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
     * @param SessionStoreInterface|null $sessionStore Session store
     */
    public function __construct(
        private readonly Server $server,
        private readonly InitializationOptions $initOptions,
        array $httpOptions = [],
        ?LoggerInterface $logger = null,
        ?SessionStoreInterface $sessionStore = null
    ) {
        // Create HTTP transport
        $this->transport = new HttpServerTransport($httpOptions, $sessionStore);
        
        parent::__construct($server, $initOptions, $logger ?? new NullLogger());
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
            // Make sure transport is started
            if (!$this->transport->isStarted()) {
                $this->transport->start();
            }
    
            $this->serverSession = new ServerSession(
                $this->transport,
                $this->initOptions,
                $this->logger
            );
            $this->server->setSession($this->serverSession);
    
            // Register handlers for the new session
            $this->serverSession->registerHandlers($this->server->getHandlers());
            $this->serverSession->registerNotificationHandlers($this->server->getNotificationHandlers());
    
            // Start the session
            $this->serverSession->start();
    
            $this->logger->info('HTTP server session started');
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
    
}
