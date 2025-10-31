# HttpTaskBundle 产品需求文档（PRD）

## 1. 引言

### 1.1 目的

HttpTaskBundle 是一个专为 Symfony 应用程序设计的包，旨在管理大量 HTTP 请求任务（如 Webhook）。它提供了一个通用的框架，用于创建、调度、执行和监控
HTTP 请求任务，满足业务系统对外发送大量请求的需求。

### 1.2 范围

该包专注于以下功能：

- 创建和调度 HTTP 请求任务。
- 异步执行任务，支持并发处理。
- 处理失败重试和日志记录。
- 提供任务状态监控。
  根据用户要求，不考虑安全、运维或数据迁移问题。

## 2. 功能需求

### 2.1 任务管理

- **任务创建**：用户可以创建 HTTP 请求任务，包含以下配置：
    - **HTTP 方法**：支持 GET、POST、PUT、DELETE 等。
    - **URL**：目标请求的 URL。
    - **头信息**：自定义 HTTP 头（如 Content-Type、Authorization）。
    - **正文**：请求正文（适用于 POST、PUT 等）。
    - **认证信息**：支持基本认证或其他认证方式（可选）。
- **任务调度**：支持以下调度方式：
    - 立即执行。
    - 延迟执行（例如，延迟 5 分钟）。
    - 定时执行（例如，每天 10:00）。
- **任务存储**：任务存储在消息队列中（通过 Symfony Messenger），无需数据库持久化。

### 2.2 任务执行

- **异步处理**：使用 Symfony Messenger 组件将任务作为消息分发到队列，异步处理。
- **HTTP 请求发送**：通过 Symfony Webhook 组件的客户端功能发送 HTTP 请求，确保标准化和高效。
- **并发处理**：支持多个任务并发执行，利用 Messenger 的多工作进程支持。

### 2.3 重试和失败处理

- **重试机制**：
    - 配置重试次数（默认 3 次）。
    - 配置重试间隔（例如，指数退避）。
- **失败处理**：
    - 记录失败请求的详细信息。
    - 提供重新发送失败任务的机制。

### 2.4 日志和监控

- **日志记录**：使用 Symfony 的日志系统记录所有请求结果，包括：
    - 成功请求的响应状态码和时间。
    - 失败请求的错误信息。
    - 重试尝试的记录。
- **监控**：提供命令行工具查看任务状态，例如：
    - 待处理任务数量。
    - 已发送任务数量。
    - 失败任务列表。

### 2.5 灵活性

- **自定义处理器**：允许用户定义自定义请求处理器，扩展 Webhook 组件的功能。
- **可配置性**：通过配置文件支持自定义消息队列传输方式（如 AMQP、Doctrine）和重试策略。

## 3. 非功能需求

- **性能**：能够高效处理大量 HTTP 请求，优化资源使用。
- **可扩展性**：支持通过增加工作进程实现水平扩展。
- **易用性**：提供简单直观的 API 和命令行工具，降低使用门槛。

## 4. 技术设计

### 4.1 架构

- **核心组件**：
    - **Symfony Messenger**：用于任务的异步处理和队列管理。
    - **Symfony Webhook 组件**：用于发送 HTTP 请求。
- **任务表示**：每个 HTTP 请求任务封装为一个 `HttpTaskMessage` 消息，包含所有请求配置。
- **执行流程**：
    1. 用户通过 `HttpTaskManager` 创建任务并分发到消息队列。
    2. 工作进程处理队列中的消息，使用 Webhook 组件发送请求。
    3. 请求结果记录到日志，失败任务根据配置进行重试。

### 4.2 关键组件

| 组件名称                   | 描述                                              |
|------------------------|-------------------------------------------------|
| `HttpTaskMessage`      | 消息类，封装 HTTP 请求任务的配置（方法、URL、头信息、正文等）。            |
| `HttpTaskHandler`      | 消息处理器，负责处理 `HttpTaskMessage`，使用 Webhook 组件发送请求。 |
| `HttpTaskManager`      | 服务类，负责创建、调度和管理任务。                               |
| `SendHttpTasksCommand` | 命令行工具，触发待处理任务的执行。                               |
| `TaskStatusCommand`    | 命令行工具，查看任务状态（待处理、已发送、失败）。                       |

### 4.3 配置

- **消息队列传输**：支持配置不同的传输方式（如 AMQP、Doctrine）。
- **重试策略**：通过配置文件设置重试次数和间隔。
- **日志配置**：支持自定义日志级别和输出目标。

### 4.4 用户接口

- **命令行工具**：
    - `app:http-task:send`：处理消息队列中的待处理任务。
    - `app:http-task:status`：显示任务状态统计。
- **API**：提供服务类方法，允许程序化创建和调度任务。

## 5. 开发计划

### 5.1 里程碑

| 里程碑 | 任务描述                                     | 预计时间 |
|-----|------------------------------------------|------|
| 1   | 设置包结构，配置和服务初始化                           | 1 周  |
| 2   | 实现 `HttpTaskMessage` 和 `HttpTaskHandler` | 1 周  |
| 3   | 集成 Messenger 和 Webhook 组件                | 1 周  |
| 4   | 实现重试逻辑和日志记录                              | 1 周  |
| 5   | 添加任务管理命令                                 | 1 周  |

### 5.2 测试

- **单元测试**：测试 `HttpTaskMessage` 和 `HttpTaskHandler` 的功能。
- **集成测试**：模拟 HTTP 请求，验证任务处理、重试和日志记录。

## 6. 结论

HttpTaskBundle 提供了一个高效、可扩展的解决方案，用于在 Symfony 应用程序中管理大量 HTTP 请求任务。通过集成 Symfony 的
Messenger 和 Webhook 组件，该包确保了异步处理、并发执行和灵活配置，满足业务系统对外发送 Webhook 等请求的需求。

## 7. 参考资料

- [Symfony Webhook 组件文档](https://symfony.com/doc/current/webhook.html)
- [Symfony Messenger 组件文档](https://symfony.com/doc/current/messenger.html)
- [Symfony Webhook 组件 Packagist](https://packagist.org/packages/symfony/webhook)
- [Symfony 发送 Webhook 示例](https://jeandaviddaviet.fr/symfony/creer-un-serveur-denvoi-de-webhooks-avec-symfony-et-le-composant-webhook)