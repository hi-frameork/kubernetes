<?php

declare(strict_types=1);

namespace Hi\Kubernetes\Data;

/**
 * 命令信息数据模型
 */
readonly class CommandInfo
{
    /**
     * @param string                $name        命令名称
     * @param string                $description 命令描述
     * @param array<string>         $args        命令参数
     * @param array<string, mixed>  $envVars     环境变量
     * @param string                $type        命令类型 (daemon|cronjob)
     * @param string|null           $schedule    cron 调度表达式（仅用于 cronjob）
     * @param int                   $replicas    副本数（仅用于 daemon）
     * @param array<string, string> $resources   资源配置
     */
    public function __construct(
        public string $name,
        public string $description = '',
        public array $args = [],
        public array $envVars = [],
        public string $type = 'daemon',
        public ?string $schedule = null,
        public int $replicas = 1,
        public array $resources = [],
    ) {
    }

    /**
     * 获取默认资源配置
     *
     * @return array<string, string>
     */
    public function getDefaultResources(): array
    {
        return \array_merge([
            'MEMORY_REQUEST' => '128Mi',
            'MEMORY_LIMIT' => '512Mi',
            'CPU_REQUEST' => '100m',
            'CPU_LIMIT' => '500m',
        ], $this->resources);
    }

    /**
     * 获取 Deployment/CronJob 名称
     *
     * @return string
     */
    public function getResourceName(): string
    {
        return \mb_strtolower(\preg_replace('/[^a-zA-Z0-9-]/', '-', $this->name));
    }

    /**
     * 获取模板变量
     *
     * @param GenerateConfig $config 生成配置
     *
     * @return array<string, mixed>
     */
    public function getTemplateVariables(GenerateConfig $config): array
    {
        $variables = [
            'COMMAND_NAME' => $this->name,
            'DAEMON_NAME' => $config->appName . '-' . $this->getResourceName(),
            'CRONJOB_NAME' => $config->appName . '-' . $this->getResourceName(),
            'IMAGE_NAME' => $config->imageName,
            'IMAGE_TAG' => $config->imageTag,
            'APP_ENV' => $config->envName,
            'REPLICAS' => $this->replicas,
            ...$this->getDefaultResources(),
            ...$config->envVars,
        ];

        // 添加命令参数
        if (! empty($this->args)) {
            $variables['COMMAND_ARGS'] = true;
            $variables['ARGS'] = \array_map(static fn ($arg) => ['ARG' => $arg], $this->args);
        }

        // 添加环境变量
        if (! empty($this->envVars)) {
            $envVarArray = [];
            foreach ($this->envVars as $key => $value) {
                $envVarArray[] = ['KEY' => $key, 'VALUE' => $value];
            }
            $variables['ENV_VARS'] = $envVarArray;
        }

        // 添加 cron 调度
        if ('cronjob' === $this->type && null !== $this->schedule) {
            $variables['SCHEDULE'] = $this->schedule;
        }

        return $variables;
    }

    /**
     * 从命令元数据数组创建 CommandInfo 对象
     *
     * @param array<string, mixed> $commandData 命令数据
     *
     * @return self
     */
    public static function fromArray(array $commandData): self
    {
        return new self(
            name: $commandData['name'] ?? 'unknown',
            description: $commandData['description'] ?? '',
            args: $commandData['args'] ?? [],
            envVars: $commandData['envVars'] ?? [],
            type: $commandData['type'] ?? 'daemon',
            schedule: $commandData['schedule'] ?? null,
            replicas: (int) ($commandData['replicas'] ?? 1),
            resources: $commandData['resources'] ?? [],
        );
    }

    /**
     * 验证 cron 调度表达式
     *
     * @return bool
     */
    public function isValidCronSchedule(): bool
    {
        if ('cronjob' !== $this->type || null === $this->schedule) {
            return 'cronjob' !== $this->type; // daemon 不需要 schedule
        }

        // 简单验证 cron 表达式格式 (5 或 6 个字段)
        $parts = \explode(' ', \trim($this->schedule));
        return \count($parts) >= 5 && \count($parts) <= 6;
    }

    /**
     * 判断是否为 daemon 类型
     *
     * @return bool
     */
    public function isDaemon(): bool
    {
        return 'daemon' === $this->type;
    }

    /**
     * 判断是否为 cronjob 类型
     *
     * @return bool
     */
    public function isCronJob(): bool
    {
        return 'cronjob' === $this->type;
    }

    /**
     * 获取显示名称
     *
     * @return string
     */
    public function getDisplayName(): string
    {
        return $this->description ?: $this->name;
    }
}
