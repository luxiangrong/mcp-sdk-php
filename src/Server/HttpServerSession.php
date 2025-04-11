<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2024 Logiscape LLC <https://logiscape.com>
 *
 * Based on the Python SDK for the Model Context Protocol
 * https://github.com/modelcontextprotocol/python-sdk
 *
 * PHP conversion developed by:
 * - Josh Abbott
 * - Claude 3.5 Sonnet (Anthropic AI model)
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
 * Filename: Server/HttpServerSession.php
 */

declare(strict_types=1);

namespace Mcp\Server;

use Mcp\Server\ServerSession;
use Mcp\Shared\BaseSession;

class HttpServerSession extends ServerSession
{
    protected function startMessageProcessing(): void
    {
        $this->isInitialized = true;

        if ($this->transport->getConfig()->isSseEnabled() && $this->transport->clientRequestedSse()) {
            // SSE path:
            // Keep open, periodically flush new events, until a break condition or client closes.
            while ($this->isInitialized) {
                $message = $this->readNextMessage();
                if ($message !== null) {
                    $this->handleIncomingMessage($message);
                }
                // Possibly sleep or do logic to push SSE events out
                // Then break if a certain time expires, or a "complete" message arrives, etc.
            }
        } else {
            // Normal HTTP path:
            // Process just one batch, then break.
            while ($this->isInitialized) {
                $message = $this->transport->readMessage();
                if ($message === null) {
                    break; 
                }
                $this->handleIncomingMessage($message);
            }
            $this->close();
        }
    }
}
