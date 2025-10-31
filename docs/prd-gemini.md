
# PRD: 通用HTTP请求任务Bundle

> **作者:** Gemini
> **日期:** 2025年8月7日
> **版本:** 1.0

## 1. 概述

### 1.1. 项目背景

在我们的业务系统中，存在大量需要对外发送HTTP请求的场景，例如：

*   **Webhook通知:** 当系统内发生特定事件（如订单创建、用户注册）时，需要通过HTTP请求通知外部系统。
*   **API调用:** 需要调用第三方服务的API来完成特定功能（如支付、物流查询）。
*   **数据上报:** 需要将系统内的数据上报到数据分析平台。

目前，这些HTTP请求的发送逻辑散落在各个业务模块中，存在以下问题：

*   **代码重复:** 每个需要发送HTTP请求的地方都需要编写类似的代码，导致代码冗余。
*   **缺乏统一管理:** 无法统一管理和监控所有的HTTP请求任务，难以追踪任务的执行状态。
*   **可靠性差:** 如果请求失败，没有统一的重试和容错机制，容易导致数据不一致。
*   **性能瓶颈:** 大量同步的HTTP请求会阻塞主流程，影响系统性能和用户体验。

为了解决以上问题，我们计划开发一个通用的HTTP请求任务Bundle，专门用于管理和发送HTTP请求任务。

### 1.2. 目标

*   **提供统一的HTTP请求任务管理机制:** 将所有HTTP请求任务纳入统一的管理体系，方便追踪和监控。
*   **实现异步化处理:** 将HTTP请求的发送操作异步化，避免阻塞主流程，提升系统性能。
*   **提供可靠的重试和容错机制:** 当请求失败时，能够自动进行重试，并在多次失败后进行告警或记录，确保任务最终能够成功执行。
*   **简化开发:** 封装复杂的HTTP请求逻辑，提供简洁易用的API，让业务开发人员能够轻松地发送HTTP请求。
*   **提高可扩展性:** Bundle应具备良好的扩展性，方便未来增加新的功能，例如：请求加密、签名、动态配置等。

### 1.3. 范围

#### 1.3.1. In-Scope

*   **任务创建:** 提供API，供业务方创建HTTP请求任务。
*   **任务存储:** 将待执行的任务持久化到数据库中。
*   **任务调度与执行:** 异步执行HTTP请求任务。
*   **失败重试:** 对执行失败的任务进行自动重试。
*   **任务状态管理:** 记录和更新任务的执行状态（待处理、处理中、成功、失败、已取消）。
*   **结果记录:** 记录每次请求的响应内容和状态码。
*   **任务查询:** 提供API，供业务方查询任务的执行状态和结果。
*   **手动触发:** 支持手动触发单个或批量任务的执行。
*   **可配置性:** 允许配置重试策略、并发数、超时时间等。

#### 1.3.2. Out-of-Scope

*   **安全相关:** 请求的认证、授权、加密、防重放等安全机制。
*   **运维监控:** 详细的性能监控、日志分析、告警系统集成。
*   **数据迁移:** 从现有业务逻辑中迁移HTTP请求到本Bundle的方案。
*   **UI管理界面:** 提供给运营或开发人员管理任务的图形化界面。

## 2. 功能需求

### 2.1. 核心功能

| 功能模块 | 需求描述 | 优先级 |
| :--- | :--- | :--- |
| **任务实体 (Task Entity)** | 定义`HttpRequestTask`实体，用于存储请求任务的详细信息。 | 高 |
| **任务服务 (Task Service)** | 提供`createTask`方法，用于创建新的HTTP请求任务。 | 高 |
| **异步处理器 (Async Handler)** | 使用Symfony Messenger组件，创建`HttpRequestTaskHandler`来异步处理任务。 | 高 |
| **重试机制 (Retry Strategy)** | 利用Messenger的重试策略，实现失败任务的自动重试。 | 高 |
| **状态管理 (Status Management)** | 在任务执行的不同阶段，更新任务实体的状态。 | 高 |
| **结果记录 (Result Logging)** | 将每次请求的HTTP状态码和响应体记录到任务实体中。 | 高 |
| **数据库结构 (Database Schema)** | 设计`http_request_task`表，用于持久化任务数据。 | 高 |
| **配置化 (Configuration)** | 允许通过`config/packages/http_request_task.yaml`文件配置Bundle的行为。 | 中 |
| **手动触发命令 (Manual Trigger)** | 提供Symfony Console命令，用于手动重试失败的任务。 | 中 |
| **任务查询API (Task Query API)** | 提供一个服务，允许根据ID或其他条件查询任务信息。 | 中 |
| **取消任务 (Cancel Task)** | 提供API，允许取消处于“待处理”状态的任务。 | 低 |

### 2.2. 详细功能描述

#### 2.2.1. 任务实体 (HttpRequestTask)

`HttpRequestTask` 实体应包含以下字段：

| 字段名 | 数据类型 | 描述 | 示例 |
| :--- | :--- | :--- | :--- |
| `id` | `int` | 主键，自增ID | `123` |
| `uuid` | `string` | 唯一标识符，用于外部系统追踪 | `a1b2c3d4-e5f6-7890-1234-567890abcdef` |
| `status` | `string` | 任务状态 | `pending`, `processing`, `completed`, `failed`, `cancelled` |
| `method` | `string` | HTTP请求方法 | `POST`, `GET`, `PUT` |
| `url` | `string` | 请求URL | `https://example.com/webhook` |
| `headers` | `json` | HTTP请求头 | `{"Content-Type": "application/json"}` |
| `payload` | `json` | HTTP请求体 | `{"order_id": "SN123456"}` |
| `max_attempts` | `int` | 最大重试次数 | `5` |
| `attempts` | `int` | 当前已尝试次数 | `2` |
| `last_attempt_at` | `datetime` | 最后一次尝试时间 | `2025-08-07 10:00:00` |
| `last_response_code` | `int` | 最后一次请求的HTTP状态码 | `500` |
| `last_response_body` | `text` | 最后一次请求的响应体 | `{"error": "Internal Server Error"}` |
| `created_at` | `datetime` | 创建时间 | `2025-08-07 09:00:00` |
| `updated_at` | `datetime` | 更新时间 | `2025-08-07 10:00:00` |

#### 2.2.2. 任务服务 (HttpRequestTaskService)

提供一个服务 `HttpRequestTaskService`，包含核心方法：

```php
public function createTask(
    string $method,
    string $url,
    array $payload = [],
    array $headers = [],
    int $maxAttempts = 5
): HttpRequestTask;
```

该方法会创建一个`HttpRequestTask`实体，设置其初始状态为`pending`，并将其存入数据库，然后分发一个`HttpRequestTaskMessage`到消息总线。

#### 2.2.3. 异步处理 (Messenger Handler)

*   **消息 (Message):** 创建一个`HttpRequestTaskMessage`类，其中包含`taskId`。
*   **处理器 (Handler):** 创建`HttpRequestTaskHandler`，它会订阅`HttpRequestTaskMessage`。处理逻辑如下：
    1.  根据`taskId`从数据库中获取`HttpRequestTask`实体。
    2.  如果任务状态不是`pending`或`failed`，则直接忽略。
    3.  更新任务状态为`processing`。
    4.  使用`Symfony\Contracts\HttpClient\HttpClientInterface`发送HTTP请求。
    5.  记录尝试次数和最后尝试时间。
    6.  根据请求结果更新任务状态：
        *   **成功 (2xx):** 状态更新为`completed`，记录响应码和响应体。
        *   **失败 (非2xx或异常):** 状态更新为`failed`，记录响应码和响应体。如果当前尝试次数小于最大次数，Messenger的重试机制会自动重新投递消息。

#### 2.2.4. 配置 (Configuration)

在`packages/http-request-task-bundle/src/DependencyInjection/Configuration.php`中定义配置项，并允许在`config/packages/http_request_task.yaml`中覆盖：

```yaml
http_request_task:
    default_max_attempts: 5 # 默认最大重试次数
    default_timeout: 30 # 默认请求超时时间（秒）
    messenger_transport_name: 'async_http_request' # 使用的Messenger transport名称
```

#### 2.2.5. 手动触发命令

创建一个Symfony命令 `http-request-task:retry <taskId>`，允许手动重试一个失败的任务。该命令会：
1.  找到指定的`HttpRequestTask`。
2.  检查其状态是否为`failed`。
3.  重新分发`HttpRequestTaskMessage`到消息总线。

## 3. 技术方案

### 3.1. 技术选型

*   **核心框架:** Symfony
*   **异步处理:** Symfony Messenger Component
*   **HTTP客户端:** Symfony HttpClient Component
*   **数据库交互:** Doctrine ORM

### 3.2. 数据库设计

创建一个名为`http_request_task`的表，字段定义见[2.2.1. 任务实体 (HttpRequestTask)](#221-任务实体-httprequesttask)。

需要为以下字段添加索引：
*   `status`：用于快速查询特定状态的任务。
*   `uuid`：用于外部系统查询。
*   `created_at`：按时间排序。

### 3.3. 目录结构

```
packages/http-request-task-bundle/
├───src/
│   ├───Command/
│   │   └───RetryTaskCommand.php
│   ├───DependencyInjection/
│   │   ├───Configuration.php
│   │   └───HttpRequestTaskExtension.php
│   ├───Entity/
│   │   └───HttpRequestTask.php
│   ├───Message/
│   │   └───HttpRequestTaskMessage.php
│   ├───MessageHandler/
│   │   └───HttpRequestTaskHandler.php
│   ├───Repository/
│   │   └───HttpRequestTaskRepository.php
│   ├───Service/
│   │   └───HttpRequestTaskService.php
│   └───HttpRequestTaskBundle.php
├───config/
│   └───services.yaml
├───docs/
│   └───prd-gemini.md
├───tests/
│   └───...
└───composer.json
```

## 4. 里程碑 (Milestones)

| 里程碑 | 预计完成时间 | 主要产出 |
| :--- | :--- | :--- |
| **M1: 核心功能开发** | 2周 | - `HttpRequestTask`实体和Repository<br>- `HttpRequestTaskService`用于创建任务<br>- `HttpRequestTaskMessage`和`HttpRequestTaskHandler`<br>- 基础的成功/失败逻辑 |
| **M2: 可靠性与配置** | 1周 | - 实现Messenger的失败重试策略<br>- 完成Bundle的配置化功能<br>- 编写单元测试和集成测试 |
| **M3: 辅助功能与文档** | 1周 | - 开发`http-request-task:retry`命令<br>- 提供任务查询服务<br>- 完善Bundle的README和使用文档 |
| **M4: 内部测试与发布** | 1周 | - 在一个试点项目中集成并进行测试<br>- 修复Bug并发布1.0.0版本 |

---
**文档结束**
