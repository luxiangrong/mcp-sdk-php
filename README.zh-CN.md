# PHP版本的模型上下文协议(MCP) SDK

[English](README.md) | 中文

这个包提供了[模型上下文协议(Model Context Protocol)](https://modelcontextprotocol.io)的PHP实现，使应用程序能够以标准化的方式为大语言模型(LLM)提供上下文。它将提供上下文的关注点与实际的LLM交互分离开来。

## 概述

这个PHP SDK实现了完整的MCP规范，可以轻松地：
* 构建能连接到任何MCP服务器的MCP客户端
* 创建暴露资源、提示和工具的MCP服务器
* 使用标准传输方式如stdio和SSE
* 处理所有MCP协议消息和生命周期事件

本SDK基于模型上下文协议的官方[Python SDK](https://github.com/modelcontextprotocol/python-sdk)开发。

这个SDK主要面向从事前沿AI集成解决方案的开发者。某些功能可能尚未完善，在生产环境使用前，实现应该由经验丰富的开发者进行彻底的测试和安全审查。

## 安装

您可以通过composer安装此包：

```bash
composer require logiscape/mcp-sdk-php
```

### 系统要求
* PHP 8.1 或更高版本
* ext-curl
* ext-pcntl (可选，推荐在CLI环境中使用)

## 基本用法

### 创建MCP服务器

以下是创建提供提示功能的MCP服务器的完整示例：

```php
<?php

// 一个带有测试用提示列表的基本示例服务器

require 'vendor/autoload.php';

use Mcp\Server\Server;
use Mcp\Server\ServerRunner;
use Mcp\Types\Prompt;
use Mcp\Types\PromptArgument;
use Mcp\Types\PromptMessage;
use Mcp\Types\ListPromptsResult;
use Mcp\Types\TextContent;
use Mcp\Types\Role;
use Mcp\Types\GetPromptResult;
use Mcp\Types\GetPromptRequestParams;

// 创建服务器实例
$server = new Server('example-server');

// 注册提示处理器
$server->registerHandler('prompts/list', function($params) {
    $prompt = new Prompt(
        name: 'example-prompt',
        description: '示例提示模板',
        arguments: [
            new PromptArgument(
                name: 'arg1',
                description: '示例参数',
                required: true
            )
        ]
    );
    return new ListPromptsResult([$prompt]);
});

$server->registerHandler('prompts/get', function(GetPromptRequestParams $params) {
    $name = $params->name;
    $arguments = $params->arguments;

    if ($name !== 'example-prompt') {
        throw new \InvalidArgumentException("未知提示: {$name}");
    }

    // 安全获取参数值
    $argValue = $arguments ? $arguments->arg1 : 'none';

    $prompt = new Prompt(
        name: 'example-prompt',
        description: '示例提示模板',
        arguments: [
            new PromptArgument(
                name: 'arg1',
                description: '示例参数',
                required: true
            )
        ]
    );

    return new GetPromptResult(
        messages: [
            new PromptMessage(
                role: Role::USER,
                content: new TextContent(
                    text: "示例提示文本，参数值为: $argValue"
                )
            )
        ],
        description: '示例提示'
    );
});

// 创建初始化选项并运行服务器
$initOptions = $server->createInitializationOptions();
$runner = new ServerRunner($server, $initOptions);
$runner->run();
```

将此代码保存为 `example_server.php`

### 创建MCP客户端

以下是如何创建连接到示例服务器的客户端：

```php
<?php

// 一个连接到example_server.php并输出提示的基本示例客户端

require 'vendor/autoload.php';

use Mcp\Client\Client;
use Mcp\Client\Transport\StdioServerParameters;
use Mcp\Types\TextContent;

// 创建stdio连接的服务器参数
$serverParams = new StdioServerParameters(
    command: 'php',  // 可执行文件
    args: ['example_server.php'],  // 服务器文件路径
    env: null  // 可选环境变量
);

// 创建客户端实例
$client = new Client();

try {
    echo("开始连接\n");
    // 使用stdio传输连接到服务器
    $session = $client->connect(
        commandOrUrl: $serverParams->getCommand(),
        args: $serverParams->getArgs(),
        env: $serverParams->getEnv()
    );

    echo("开始获取可用提示\n");
    // 列出可用提示
    $promptsResult = $session->listPrompts();

    // 输出提示列表
    if (!empty($promptsResult->prompts)) {
        echo "可用提示：\n";
        foreach ($promptsResult->prompts as $prompt) {
            echo "  - 名称: " . $prompt->name . "\n";
            echo "    描述: " . $prompt->description . "\n";
            echo "    参数:\n";
            if (!empty($prompt->arguments)) {
                foreach ($prompt->arguments as $argument) {
                    echo "      - " . $argument->name . " (" . ($argument->required ? "必需" : "可选") . "): " . $argument->description . "\n";
                }
            } else {
                echo "      (无)\n";
            }
        }
    } else {
        echo "没有可用提示。\n";
    }

} catch (\Exception $e) {
    echo "错误: " . $e->getMessage() . "\n";
    exit(1);
} finally {
    // 关闭服务器连接
    if (isset($client)) {
        $client->close();
    }
}
```

将此代码保存为 `example_client.php` 并运行：
```bash
php example_client.php
```

## 用于调试的高级日志记录

### 使用日志记录

您可以在客户端、服务器端或两者同时启用详细日志记录。完整的日志记录示例代码请参考英文版README中的相应部分。

## MCP Web客户端

"webclient"目录包含了一个用于测试MCP服务器的基于Web的应用程序。它旨在展示一个能在典型Web托管环境中运行的MCP客户端。

### 设置Web客户端

要设置Web客户端，请将"webclient"目录的内容上传到Web目录（例如cPanel服务器上的public_html）。确保通过运行本README安装部分中的Composer命令在同一目录中安装MCP SDK for PHP。

### 使用Web客户端

Web客户端上传到Web目录后，导航到index.php打开界面。要连接到包含的MCP测试服务器，在Command字段中输入`php`，在Arguments字段中输入`test_server.php`，然后点击`Connect to Server`。该界面允许您测试提示、工具和资源。还有一个调试面板，允许您查看客户端和服务器之间发送的JSON-RPC消息。

### Web客户端注意事项和限制

此MCP Web客户端旨在供开发者测试MCP服务器，不建议在未经额外安全性、错误处理和资源管理测试的情况下将其作为公共Web界面使用。

虽然MCP通常作为有状态的会话协议实现，但典型的基于PHP的Web托管环境限制了长时间运行的进程。为了最大化兼容性，MCP Web客户端将为每个请求初始化客户端和服务器之间的新连接，并在请求完成后关闭该连接。

## 文档

有关模型上下文协议的详细信息，请访问[官方文档](https://modelcontextprotocol.io)。

## 致谢

这个PHP SDK由以下人员开发：
- [Josh Abbott](https://joshabbott.com)
- Claude 3.5 Sonnet (Anthropic AI模型)

Josh Abbott使用OpenAI ChatGPT o1 pro模式进行了额外的调试和重构。

基于模型上下文协议的原始[Python SDK](https://github.com/modelcontextprotocol/python-sdk)开发。

## 许可证

MIT许可证 (MIT)。更多信息请查看[许可证文件](LICENSE)。 