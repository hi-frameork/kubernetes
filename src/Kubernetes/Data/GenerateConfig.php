<?php

declare(strict_types=1);

namespace Hi\Kubernetes\Data;

/**
 * Kubernetes 生成配置数据模型
 */
readonly class GenerateConfig
{
    /**
     * @param string                $appName    应用名称
     * @param string                $imageName  镜像名称
     * @param string                $imageTag   镜像标签
     * @param string                $domain     域名
     * @param string                $namespace  命名空间
     * @param string                $envName    环境名称
     * @param array<string, mixed>  $envVars    环境变量
     * @param array<string, string> $resources  资源配置
     * @param int                   $replicas   副本数
     * @param string                $deployPath 部署路径
     */
    public function __construct(
        public string $appName,
        public string $imageName,
        public string $imageTag,
        public string $domain,
        public string $namespace,
        public string $envName,
        public array $envVars = [],
        public array $resources = [],
        public int $replicas = 1,
        public string $deployPath = 'deploy',
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
            'MEMORY_REQUEST' => '64Mi',
            'MEMORY_LIMIT' => '512Mi',
            'CPU_REQUEST' => '100m',
            'CPU_LIMIT' => '500m',
        ], $this->resources);
    }

    /**
     * 获取模板变量
     *
     * @return array<string, mixed>
     */
    public function getTemplateVariables(): array
    {
        return [
            'APP_NAME' => $this->appName,
            'IMAGE_NAME' => $this->imageName,
            'IMAGE_TAG' => $this->imageTag,
            'DOMAIN' => $this->domain,
            'NAMESPACE' => $this->namespace,
            'ENV_NAME' => $this->envName,
            'APP_ENV' => $this->envName,
            'REPLICAS' => $this->replicas,
            'SERVICE_NAME' => $this->appName . '-service',
            'SERVICE_PORT' => 80,
            ...$this->getDefaultResources(),
            ...$this->envVars,
        ];
    }

    /**
     * 创建默认配置
     *
     * @param string $appName 应用名称
     * @param string $envName 环境名称
     *
     * @return self
     */
    public static function createDefault(string $appName, string $envName = 'production'): self
    {
        return new self(
            appName: $appName,
            imageName: $appName,
            imageTag: 'latest',
            domain: $appName . '.example.com',
            namespace: $envName,
            envName: $envName,
        );
    }
}
