<?php

declare(strict_types=1);

namespace Hi\Kubernetes;

use Hi\Http\RouterInterface;
use Hi\Kernel\Console\CommandMetadataManagerInterface;
use Hi\Kernel\DirectoriesInterface;
use Hi\Kubernetes\Data\GenerateConfig;
use Hi\Kubernetes\Data\RouteInfo;
use Hi\Kubernetes\Data\CommandInfo;
use Hi\Kubernetes\Exception\KubernetesException;
use Hi\Kubernetes\Exception\TemplateNotFoundException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Kubernetes 资源生成器
 *
 * 负责生成 Kubernetes 部署配置，包括 Ingress、Deployment 和 CronJob
 */
class KubernetesGenerator
{
    private const TEMPLATE_DIR = 'src/Kubernetes/Templates';
    private const USER_TEMPLATE_DIR = 'deploy/base/templates';
    private const USER_DEPLOY_DIR = 'deploy';

    public function __construct(
        private readonly RouterInterface $router,
        private readonly CommandMetadataManagerInterface $commandManager,
        private readonly DirectoriesInterface $directories,
        private readonly SimpleTemplateRenderer $renderer,
        private readonly LoggerInterface $logger = new NullLogger,
    ) {
    }

    /**
     * 初始化 Kubernetes 配置
     *
     * @param array<string> $environments 环境列表
     *
     * @return bool 初始化是否成功
     */
    public function initialize(array $environments = ['production', 'staging']): bool
    {
        try {
            $this->logger->info('开始初始化 Kubernetes 配置', ['environments' => $environments]);

            // 复制基础模板文件
            $this->copyBaseTemplates();

            // 为每个环境生成配置
            foreach ($environments as $env) {
                $this->generateEnvironmentConfig($env);
            }

            $this->logger->info('Kubernetes 配置初始化完成');
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('初始化失败', ['error' => $e->getMessage()]);
            throw new KubernetesException("初始化失败: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * 生成 Ingress 配置
     *
     * @param GenerateConfig $config 生成配置
     *
     * @return string 生成的 YAML 内容
     */
    public function generateIngress(GenerateConfig $config): string
    {
        $this->logger->info('开始生成 Ingress 配置', ['app' => $config->appName]);

        // 获取路由信息
        $routes = $this->extractRouteInfo();

        if (empty($routes)) {
            $this->logger->warning('未找到任何路由，跳过 Ingress 生成');
            return '';
        }

        // 准备模板变量
        $variables = $config->getTemplateVariables();
        $variables['ROUTES'] = \array_map(static fn (RouteInfo $route) => $route->toTemplateArray(), $routes);

        // 获取模板内容
        $templateContent = $this->getUserTemplateContent('ingress-tpl.yaml');

        // 渲染模板
        $yaml = $this->renderer->render($templateContent, $variables);

        // 写入文件
        $outputPath = $this->getDeployDir() . '/base/ingress.yaml';
        $this->renderer->renderToFile($templateContent, $variables, $outputPath);

        // 更新 kustomization.yaml
        $this->addToKustomization('base', 'ingress.yaml');

        $this->logger->info('Ingress 配置生成完成', ['output' => $outputPath]);
        return $yaml;
    }

    /**
     * 生成 Daemon (Deployment) 配置
     *
     * @param GenerateConfig $config       生成配置
     * @param array<string>  $commandNames 命令名称列表，为空时生成所有daemon命令
     *
     * @return array<string, string> 生成的 YAML 内容数组
     */
    public function generateDaemon(GenerateConfig $config, array $commandNames = []): array
    {
        $this->logger->info('开始生成 Daemon 配置', ['app' => $config->appName, 'commands' => $commandNames]);

        $commands = $this->getCommandsInfo('daemon', $commandNames);
        $results = [];

        foreach ($commands as $command) {
            if (! $command->isDaemon()) {
                continue;
            }

            $variables = $command->getTemplateVariables($config);
            $templateContent = $this->getUserTemplateContent('daemon-tpl.yaml');

            $yaml = $this->renderer->render($templateContent, $variables);
            $filename = "daemon-{$command->getResourceName()}.yaml";
            $outputPath = $this->getDeployDir() . "/base/{$filename}";

            $this->renderer->renderToFile($templateContent, $variables, $outputPath);
            $this->addToKustomization('base', $filename);

            $results[$command->name] = $yaml;

            $this->logger->info('Daemon 配置生成完成', [
                'command' => $command->name,
                'output' => $outputPath,
            ]);
        }

        return $results;
    }

    /**
     * 生成 CronJob 配置
     *
     * @param GenerateConfig $config       生成配置
     * @param array<string>  $commandNames 命令名称列表，为空时生成所有cronjob命令
     *
     * @return array<string, string> 生成的 YAML 内容数组
     */
    public function generateCronJob(GenerateConfig $config, array $commandNames = []): array
    {
        $this->logger->info('开始生成 CronJob 配置', ['app' => $config->appName, 'commands' => $commandNames]);

        $commands = $this->getCommandsInfo('cronjob', $commandNames);
        $results = [];

        foreach ($commands as $command) {
            if (! $command->isCronJob() || ! $command->isValidCronSchedule()) {
                $this->logger->warning('跳过无效的 CronJob 命令', [
                    'command' => $command->name,
                    'schedule' => $command->schedule,
                ]);
                continue;
            }

            $variables = $command->getTemplateVariables($config);
            $templateContent = $this->getUserTemplateContent('cronjob-tpl.yaml');

            $yaml = $this->renderer->render($templateContent, $variables);
            $filename = "cronjob-{$command->getResourceName()}.yaml";
            $outputPath = $this->getDeployDir() . "/base/{$filename}";

            $this->renderer->renderToFile($templateContent, $variables, $outputPath);
            $this->addToKustomization('base', $filename);

            $results[$command->name] = $yaml;

            $this->logger->info('CronJob 配置生成完成', [
                'command' => $command->name,
                'output' => $outputPath,
            ]);
        }

        return $results;
    }

    /**
     * 列出将要生成的资源
     *
     * @param GenerateConfig $config 生成配置
     *
     * @return array<string, mixed> 资源列表
     */
    public function listResources(GenerateConfig $config): array
    {
        $routes = $this->extractRouteInfo();
        $daemonCommands = $this->getCommandsInfo('daemon');
        $cronjobCommands = $this->getCommandsInfo('cronjob');

        return [
            'ingress' => [
                'count' => \count($routes),
                'routes' => \array_map(static fn (RouteInfo $route) => [
                    'path' => $route->path,
                    'method' => $route->method,
                    'handler' => $route->handler,
                ], $routes),
            ],
            'daemon' => [
                'count' => \count($daemonCommands),
                'commands' => \array_map(static fn (CommandInfo $cmd) => [
                    'name' => $cmd->name,
                    'description' => $cmd->description,
                    'replicas' => $cmd->replicas,
                ], $daemonCommands),
            ],
            'cronjob' => [
                'count' => \count($cronjobCommands),
                'commands' => \array_map(static fn (CommandInfo $cmd) => [
                    'name' => $cmd->name,
                    'description' => $cmd->description,
                    'schedule' => $cmd->schedule,
                    'valid' => $cmd->isValidCronSchedule(),
                ], $cronjobCommands),
            ],
        ];
    }

    /**
     * 获取用户模板内容
     *
     * @param string $templateName 模板文件名
     *
     * @return string 模板内容
     *
     * @throws TemplateNotFoundException
     */
    public function getUserTemplateContent(string $templateName): string
    {
        $userTemplatePath = $this->directories->get('root') . '/' . self::USER_TEMPLATE_DIR . '/' . $templateName;
        $frameworkTemplatePath = $this->directories->get('root') . '/' . self::TEMPLATE_DIR . '/base/templates/' . $templateName;

        // 优先使用用户模板
        if (\file_exists($userTemplatePath)) {
            $content = \file_get_contents($userTemplatePath);
            if (false === $content) {
                throw new TemplateNotFoundException("无法读取用户模板文件: {$userTemplatePath}");
            }
            return $content;
        }

        // 回退到框架模板
        if (\file_exists($frameworkTemplatePath)) {
            $content = \file_get_contents($frameworkTemplatePath);
            if (false === $content) {
                throw new TemplateNotFoundException("无法读取框架模板文件: {$frameworkTemplatePath}");
            }
            return $content;
        }

        throw new TemplateNotFoundException("模板文件不存在: {$templateName}");
    }

    /**
     * 验证用户模板是否存在
     *
     * @param string $templateName 模板文件名
     *
     * @return bool
     */
    public function validateUserTemplateExists(string $templateName): bool
    {
        $userTemplatePath = $this->directories->get('root') . '/' . self::USER_TEMPLATE_DIR . '/' . $templateName;
        $frameworkTemplatePath = $this->directories->get('root') . '/' . self::TEMPLATE_DIR . '/base/templates/' . $templateName;

        return \file_exists($userTemplatePath) || \file_exists($frameworkTemplatePath);
    }

    /**
     * 获取部署目录
     *
     * @return string
     */
    public function getDeployDir(): string
    {
        return $this->directories->get('root') . '/' . self::USER_DEPLOY_DIR;
    }

    /**
     * 复制基础模板文件到用户目录
     */
    private function copyBaseTemplates(): void
    {
        $sourceDir = $this->directories->get('root') . '/' . self::TEMPLATE_DIR;
        $targetDir = $this->getDeployDir();

        $this->copyDirectory($sourceDir, $targetDir);
    }

    /**
     * 生成环境配置
     *
     * @param string $env 环境名称
     */
    private function generateEnvironmentConfig(string $env): void
    {
        $envDir = $this->getDeployDir() . "/{$env}";

        if (! \is_dir($envDir)) {
            \mkdir($envDir, 0o755, true);
        }

        $this->copyEnvironmentTemplates($env);
    }

    /**
     * 复制环境模板
     *
     * @param string $env 环境名称
     */
    private function copyEnvironmentTemplates(string $env): void
    {
        $sourceDir = $this->directories->get('root') . '/' . self::TEMPLATE_DIR . '/env';
        $targetDir = $this->getDeployDir() . "/{$env}";

        $this->copyDirectory($sourceDir, $targetDir);

        // 处理环境特定的 kustomization.yaml
        $kustomizationFile = $targetDir . '/kustomization.yaml';
        if (\file_exists($kustomizationFile)) {
            $content = \file_get_contents($kustomizationFile);
            $variables = [
                'ENV_NAME' => $env,
                'NAMESPACE' => $env,
                'APP_NAME' => 'app', // 默认应用名
                'IMAGE_TAG' => 'latest',
            ];

            $rendered = $this->renderer->render($content, $variables);
            \file_put_contents($kustomizationFile, $rendered);
        }
    }

    /**
     * 递归复制目录
     *
     * @param string $source 源目录
     * @param string $target 目标目录
     */
    private function copyDirectory(string $source, string $target): void
    {
        if (! \is_dir($source)) {
            throw new \RuntimeException("Source template directory not found: {$source}");
        }

        if (! \is_dir($target)) {
            \mkdir($target, 0o755, true);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            $targetPath = $target . \DIRECTORY_SEPARATOR . $iterator->getSubPathName();

            if ($item->isDir()) {
                if (! \is_dir($targetPath)) {
                    \mkdir($targetPath, 0o755, true);
                }
            } elseif (! \file_exists($targetPath)) {
                \copy($item->getPathname(), $targetPath);
            }
        }
    }

    /**
     * 从路由器提取路由信息
     *
     * @return array<RouteInfo>
     */
    private function extractRouteInfo(): array
    {
        $routes = [];
        $routeData = $this->router->getRoutes();

        foreach ($routeData as $route) {
            $routes[] = RouteInfo::fromArray([
                'path' => $route->getPattern() ?? '/',
                'method' => $route->getMethod() ?? 'GET',
                'handler' => $route->getHandlerClass() ?? 'unknown',
            ]);
        }

        return $routes;
    }

    /**
     * 获取命令信息
     *
     * @param string        $type         命令类型 (daemon|cronjob)
     * @param array<string> $commandNames 指定的命令名称列表
     *
     * @return array<CommandInfo>
     */
    private function getCommandsInfo(string $type, array $commandNames = []): array
    {
        $commands = [];
        $allCommands = $this->commandManager->getAllCommands();

        foreach ($allCommands as $name => $metadata) {
            // 如果指定了命令名称列表，只处理指定的命令
            if (! empty($commandNames) && ! \in_array($name, $commandNames)) {
                continue;
            }

            // 这里需要根据命令的元数据判断类型和调度信息
            // 由于框架的命令系统可能没有直接的类型标识，我们使用一些约定
            $commandType = $this->determineCommandType($name, $metadata);

            if ($commandType !== $type) {
                continue;
            }

            $commands[] = CommandInfo::fromArray([
                'name' => $name,
                'description' => $metadata->getDescription() ?? '',
                'type' => $commandType,
                'schedule' => $this->extractCronSchedule($name, $metadata),
                'replicas' => $this->extractReplicas($name, $metadata),
            ]);
        }

        return $commands;
    }

    /**
     * 确定命令类型
     *
     * @param string $name     命令名称
     * @param mixed  $metadata 命令元数据
     *
     * @return string
     */
    private function determineCommandType(string $name, $metadata): string
    {
        // 根据命令名称的模式判断类型
        if (\str_contains($name, 'cron') || \str_contains($name, 'schedule')) {
            return 'cronjob';
        }

        if (\str_contains($name, 'daemon') || \str_contains($name, 'worker') || \str_contains($name, 'queue')) {
            return 'daemon';
        }

        // 默认为 daemon 类型
        return 'daemon';
    }

    /**
     * 提取 cron 调度表达式
     *
     * @param string $name     命令名称
     * @param mixed  $metadata 命令元数据
     *
     * @return string|null
     */
    private function extractCronSchedule(string $name, $metadata): ?string
    {
        // 这里可以根据命令的约定或配置提取调度信息
        // 例如从描述中提取或使用默认值
        if (\str_contains($name, 'daily')) {
            return '0 0 * * *'; // 每日午夜
        }

        if (\str_contains($name, 'hourly')) {
            return '0 * * * *'; // 每小时
        }

        // 默认每5分钟执行一次
        return '*/5 * * * *';
    }

    /**
     * 提取副本数配置
     *
     * @param string $name     命令名称
     * @param mixed  $metadata 命令元数据
     *
     * @return int
     */
    private function extractReplicas(string $name, $metadata): int
    {
        // 根据命令类型返回合适的副本数
        if (\str_contains($name, 'worker') || \str_contains($name, 'queue')) {
            return 2; // 工作进程通常需要多个副本
        }

        return 1; // 默认单副本
    }

    /**
     * 添加资源到 kustomization.yaml
     *
     * @param string $layer    层级 (base|环境名)
     * @param string $resource 资源文件名
     */
    private function addToKustomization(string $layer, string $resource): void
    {
        $kustomizationFile = $this->getDeployDir() . "/{$layer}/kustomization.yaml";

        if (! \file_exists($kustomizationFile)) {
            $this->logger->warning('kustomization.yaml 文件不存在', ['file' => $kustomizationFile]);
            return;
        }

        $content = \file_get_contents($kustomizationFile);
        if (false === $content) {
            return;
        }

        // 简单的 YAML 处理：检查资源是否已存在
        if (\str_contains($content, "- {$resource}")) {
            return; // 资源已存在
        }

        // 在 resources 部分添加新资源
        if (\preg_match('/^resources:\s*$/m', $content)) {
            $content = \preg_replace('/^resources:\s*$/m', "resources:\n  - {$resource}", $content);
        } else {
            $content = \preg_replace('/^(resources:\s*)$/m', "$1\n  - {$resource}", $content);
        }

        \file_put_contents($kustomizationFile, $content);
    }
}
