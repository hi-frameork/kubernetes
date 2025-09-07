<?php

declare(strict_types=1);

namespace Hi\Kubernetes\Data;

/**
 * 路由信息数据模型
 */
readonly class RouteInfo
{
    /**
     * @param string $path        路由路径
     * @param string $method      HTTP 方法
     * @param string $handler     处理器名称
     * @param string $pathType    路径类型 (Prefix|Exact|ImplementationSpecific)
     * @param string $serviceName 服务名称
     * @param int    $servicePort 服务端口
     */
    public function __construct(
        public string $path,
        public string $method,
        public string $handler,
        public string $pathType = 'Prefix',
        public string $serviceName = 'app-service',
        public int $servicePort = 80,
    ) {
    }

    /**
     * 获取 Ingress 模板变量
     *
     * @return array<string, mixed>
     */
    public function toTemplateArray(): array
    {
        return [
            'PATH' => $this->path,
            'PATH_TYPE' => $this->pathType,
            'SERVICE_NAME' => $this->serviceName,
            'SERVICE_PORT' => $this->servicePort,
            'METHOD' => $this->method,
            'HANDLER' => $this->handler,
        ];
    }

    /**
     * 从路由定义数组创建 RouteInfo 对象
     *
     * @param array<string, mixed> $routeData 路由数据
     *
     * @return self
     */
    public static function fromArray(array $routeData): self
    {
        return new self(
            path: $routeData['path'] ?? '/',
            method: $routeData['method'] ?? 'GET',
            handler: $routeData['handler'] ?? 'unknown',
            pathType: $routeData['pathType'] ?? 'Prefix',
            serviceName: $routeData['serviceName'] ?? 'app-service',
            servicePort: (int) ($routeData['servicePort'] ?? 80),
        );
    }

    /**
     * 规范化路径为 Kubernetes Ingress 格式
     *
     * @return string
     */
    public function getNormalizedPath(): string
    {
        $path = $this->path;

        // 确保以 / 开头
        if (! \str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        // 移除多余的斜杠
        $path = \preg_replace('#/+#', '/', $path);

        // 移除末尾的斜杠（除了根路径）
        if (\mb_strlen($path) > 1 && \str_ends_with($path, '/')) {
            $path = \rtrim($path, '/');
        }

        return $path ?: '/';
    }

    /**
     * 判断是否为根路径
     *
     * @return bool
     */
    public function isRootPath(): bool
    {
        return '/' === $this->getNormalizedPath();
    }

    /**
     * 获取路径类型（优先级 Exact > Prefix）
     *
     * @return string
     */
    public function getOptimizedPathType(): string
    {
        // 如果路径包含参数，使用 Prefix
        if (\str_contains($this->path, '{') || \str_contains($this->path, '*')) {
            return 'Prefix';
        }

        // 根路径使用 Prefix
        if ($this->isRootPath()) {
            return 'Prefix';
        }

        return $this->pathType;
    }
}
