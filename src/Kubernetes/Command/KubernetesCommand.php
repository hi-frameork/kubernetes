<?php

declare(strict_types=1);

namespace Hi\Kubernetes\Command;

use Hi\Kernel\Console\Command\AbstractCommand;
use Hi\Kernel\Console\InputInterface;
use Hi\Kernel\Console\OutputInterface;
use Hi\Kubernetes\KubernetesGenerator;
use Hi\Kubernetes\Data\GenerateConfig;

class KubernetesCommand extends AbstractCommand
{
    protected string $name = 'k8s';
    protected string $desc = 'Kubernetes manifest generator command';
    protected array $actions = [
        'init' => [
            'desc' => 'Init Kubernetes manifest in deploy directory',
            'handler' => 'init',
            'options' => [
                'envs' => [
                    'desc' => 'Environments',
                    'default' => 'local,development,production',
                ],
            ],
        ],
        'ingress' => [
            'desc' => 'Generate Ingress manifest',
            'handler' => 'ingress',
            'options' => [
                'envs' => [
                    'desc' => 'Environments',
                    'default' => 'all',
                ],
                'output' => [
                    'desc' => 'Directory of Kubernetes manifest, like deploy/local, deploy/development, deploy/production',
                    'default' => 'deploy',
                ],
                'list' => [
                    'desc' => 'List Ingress manifest',
                    'shortcut' => 'l',
                ],
            ],
        ],
        'daemon' => [
            'desc' => 'Generate Daemon manifest',
            'handler' => 'daemon',
            'options' => [
                'envs' => [
                    'desc' => 'Environments',
                    'default' => 'all',
                ],
                'list' => [
                    'desc' => 'List Daemon manifest',
                    'shortcut' => 'l',
                ],
            ],
        ],
        'cronjob' => [
            'desc' => 'Generate CronJob manifest',
            'handler' => 'cronjob',
            'options' => [
                'envs' => [
                    'desc' => 'Environments',
                    'default' => 'all',
                ],
                'list' => [
                    'desc' => 'List CronJob manifest',
                    'shortcut' => 'l',
                ],
            ],
        ],
        'generate' => [
            'desc' => 'Generate Kubernetes manifest, shortcut for ingress, daemon, cronjob',
            'handler' => 'generate',
            'options' => [
                'envs' => [
                    'desc' => 'Environments',
                    'default' => 'all',
                ],
            ],
        ],
    ];

    public function init(InputInterface $input, OutputInterface $output, KubernetesGenerator $generator): int
    {
        $envs = $input->getOption('envs');
        $environments = $envs ? \explode(',', $envs) : ['production', 'staging'];

        $output->writeln('<info>正在初始化 Kubernetes 配置...</info>');
        $output->writeln('环境: ' . \implode(', ', $environments));

        try {
            $success = $generator->initialize($environments);
            if ($success) {
                $output->writeln('<info>✅ Kubernetes 配置初始化完成</info>');
                return 0;
            }
            $output->writeln('<error>❌ Kubernetes 配置初始化失败</error>');
            return 1;

        } catch (\Throwable $e) {
            $output->writeln("<error>❌ 初始化失败: {$e->getMessage()}</error>");
            return 1;
        }
    }

    public function ingress(InputInterface $input, OutputInterface $output, KubernetesGenerator $generator): int
    {
        if ($input->getOption('list')) {
            return $this->listIngress($input, $output);
        }

        $config = $this->createConfig($input);

        $output->writeln('<info>正在生成 Ingress 配置...</info>');

        try {
            $yaml = $generator->generateIngress($config);
            if (empty($yaml)) {
                $output->writeln('<warning>⚠️  未生成任何 Ingress 配置（可能没有路由）</warning>');
                return 0;
            }

            $output->writeln('<info>✅ Ingress 配置生成完成</info>');
            return 0;
        } catch (\Throwable $e) {
            $output->writeln("<error>❌ 生成失败: {$e->getMessage()}</error>");
            return 1;
        }
    }

    public function daemon(InputInterface $input, OutputInterface $output, KubernetesGenerator $generator): int
    {
        if ($input->getOption('list')) {
            return $this->listDaemon($input, $output);
        }

        $config = $this->createConfig($input);

        $output->writeln('<info>正在生成 Daemon 配置...</info>');

        try {
            $results = $generator->generateDaemon($config);
            if (empty($results)) {
                $output->writeln('<warning>⚠️  未生成任何 Daemon 配置（可能没有 daemon 命令）</warning>');
                return 0;
            }

            $output->writeln('<info>✅ 生成了 ' . \count($results) . ' 个 Daemon 配置</info>');
            foreach (\array_keys($results) as $command) {
                $output->writeln("  - {$command}");
            }
            return 0;
        } catch (\Throwable $e) {
            $output->writeln("<error>❌ 生成失败: {$e->getMessage()}</error>");
            return 1;
        }
    }

    public function cronjob(InputInterface $input, OutputInterface $output, KubernetesGenerator $generator): int
    {
        if ($input->getOption('list')) {
            return $this->listCronJob($input, $output);
        }

        $config = $this->createConfig($input);

        $output->writeln('<info>正在生成 CronJob 配置...</info>');

        try {
            $results = $generator->generateCronJob($config);
            if (empty($results)) {
                $output->writeln('<warning>⚠️  未生成任何 CronJob 配置（可能没有 cronjob 命令）</warning>');
                return 0;
            }

            $output->writeln('<info>✅ 生成了 ' . \count($results) . ' 个 CronJob 配置</info>');
            foreach (\array_keys($results) as $command) {
                $output->writeln("  - {$command}");
            }
            return 0;
        } catch (\Throwable $e) {
            $output->writeln("<error>❌ 生成失败: {$e->getMessage()}</error>");
            return 1;
        }
    }

    public function generate(InputInterface $input, OutputInterface $output, KubernetesGenerator $generator): int
    {
        $config = $this->createConfig($input);

        $output->writeln('<info>正在生成所有 Kubernetes 配置...</info>');

        $totalSuccess = 0;
        $totalErrors = 0;

        // 生成 Ingress
        try {
            $yaml = $generator->generateIngress($config);
            if (! empty($yaml)) {
                $output->writeln('✅ Ingress 配置生成完成');
                $totalSuccess++;
            } else {
                $output->writeln('⚠️  跳过 Ingress（没有路由）');
            }
        } catch (\Throwable $e) {
            $output->writeln("<error>❌ Ingress 生成失败: {$e->getMessage()}</error>");
            $totalErrors++;
        }

        // 生成 Daemon
        try {
            $results = $generator->generateDaemon($config);
            if (! empty($results)) {
                $output->writeln('✅ 生成了 ' . \count($results) . ' 个 Daemon 配置');
                $totalSuccess += \count($results);
            } else {
                $output->writeln('⚠️  跳过 Daemon（没有 daemon 命令）');
            }
        } catch (\Throwable $e) {
            $output->writeln("<error>❌ Daemon 生成失败: {$e->getMessage()}</error>");
            $totalErrors++;
        }

        // 生成 CronJob
        try {
            $results = $generator->generateCronJob($config);
            if (! empty($results)) {
                $output->writeln('✅ 生成了 ' . \count($results) . ' 个 CronJob 配置');
                $totalSuccess += \count($results);
            } else {
                $output->writeln('⚠️  跳过 CronJob（没有 cronjob 命令）');
            }
        } catch (\Throwable $e) {
            $output->writeln("<error>❌ CronJob 生成失败: {$e->getMessage()}</error>");
            $totalErrors++;
        }

        if ($totalErrors > 0) {
            $output->writeln("<error>🔥 生成完成，但有 {$totalErrors} 个错误</error>");
            return 1;
        }
        $output->writeln("<info>🎉 所有配置生成完成！总共生成了 {$totalSuccess} 个资源</info>");
        return 0;

    }

    /**
     * 列出 Ingress 资源预览
     */
    private function listIngress(InputInterface $input, OutputInterface $output): int
    {
        $config = $this->createConfig($input);
        $resources = $generator->listResources($config);

        $output->writeln('<info>📋 Ingress 资源预览</info>');

        if (empty($resources['ingress']['routes'])) {
            $output->writeln('  <comment>暂无路由</comment>');
            return 0;
        }

        foreach ($resources['ingress']['routes'] as $route) {
            $output->writeln("  - {$route['method']} {$route['path']} -> {$route['handler']}");
        }

        return 0;
    }

    /**
     * 列出 Daemon 资源预览
     */
    private function listDaemon(InputInterface $input, OutputInterface $output): int
    {
        $config = $this->createConfig($input);
        $resources = $generator->listResources($config);

        $output->writeln('<info>📋 Daemon 资源预览</info>');

        if (empty($resources['daemon']['commands'])) {
            $output->writeln('  <comment>暂无 daemon 命令</comment>');
            return 0;
        }

        foreach ($resources['daemon']['commands'] as $command) {
            $output->writeln("  - {$command['name']} (副本: {$command['replicas']}) - {$command['description']}");
        }

        return 0;
    }

    /**
     * 列出 CronJob 资源预览
     */
    private function listCronJob(InputInterface $input, OutputInterface $output): int
    {
        $config = $this->createConfig($input);
        $resources = $generator->listResources($config);

        $output->writeln('<info>📋 CronJob 资源预览</info>');

        if (empty($resources['cronjob']['commands'])) {
            $output->writeln('  <comment>暂无 cronjob 命令</comment>');
            return 0;
        }

        foreach ($resources['cronjob']['commands'] as $command) {
            $status = $command['valid'] ? '✅' : '❌';
            $schedule = $command['schedule'] ?? 'N/A';
            $output->writeln("  - {$status} {$command['name']} ({$schedule}) - {$command['description']}");
        }

        return 0;
    }

    /**
     * 创建生成配置
     */
    private function createConfig(InputInterface $input): GenerateConfig
    {
        // 这里可以根据输入参数创建配置，现在使用默认配置
        return GenerateConfig::createDefault('app', 'production');
    }
}
