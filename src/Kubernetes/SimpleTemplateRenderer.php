<?php

declare(strict_types=1);

namespace Hi\Kubernetes;

/**
 * 简单的模板渲染引擎
 * 支持基本的占位符替换和循环处理
 */
class SimpleTemplateRenderer
{
    /**
     * 渲染模板内容
     *
     * @param string               $template  模板内容
     * @param array<string, mixed> $variables 变量数组
     *
     * @return string 渲染后的内容
     */
    public function render(string $template, array $variables = []): string
    {
        $result = $template;

        // 处理循环语法 {{#LOOP_NAME}} ... {{/LOOP_NAME}}
        $result = $this->processLoops($result, $variables);

        // 处理普通占位符 {{KEY}}
        return $this->processPlaceholders($result, $variables);
    }

    /**
     * 处理循环语法
     *
     * @param string               $template  模板内容
     * @param array<string, mixed> $variables 变量数组
     *
     * @return string 处理后的内容
     */
    private function processLoops(string $template, array $variables): string
    {
        return \preg_replace_callback(
            '/\{\{#(\w+)\}\}(.*?)\{\{\/\1\}\}/s',
            function ($matches) use ($variables) {
                $loopName = $matches[1];
                $loopTemplate = $matches[2];

                // 检查是否存在对应的循环变量
                if (! isset($variables[$loopName]) || ! \is_array($variables[$loopName])) {
                    return ''; // 如果不存在循环变量，返回空字符串
                }

                $result = '';
                foreach ($variables[$loopName] as $item) {
                    if (\is_array($item)) {
                        // 递归渲染每个循环项
                        $result .= $this->render($loopTemplate, $item);
                    }
                }

                return $result;
            },
            $template,
        );
    }

    /**
     * 处理普通占位符
     *
     * @param string               $template  模板内容
     * @param array<string, mixed> $variables 变量数组
     *
     * @return string 处理后的内容
     */
    private function processPlaceholders(string $template, array $variables): string
    {
        return \preg_replace_callback(
            '/\{\{(\w+)\}\}/',
            static function ($matches) use ($variables) {
                $key = $matches[1];

                // 返回对应的变量值，如果不存在则保留原占位符
                return isset($variables[$key]) ? (string) $variables[$key] : $matches[0];
            },
            $template,
        );
    }

    /**
     * 从文件读取并渲染模板
     *
     * @param string               $templateFile 模板文件路径
     * @param array<string, mixed> $variables    变量数组
     *
     * @return string 渲染后的内容
     *
     * @throws \RuntimeException 当文件不存在时
     */
    public function renderFromFile(string $templateFile, array $variables = []): string
    {
        if (! \file_exists($templateFile)) {
            throw new \RuntimeException("Template file not found: {$templateFile}");
        }

        $template = \file_get_contents($templateFile);
        if (false === $template) {
            throw new \RuntimeException("Failed to read template file: {$templateFile}");
        }

        return $this->render($template, $variables);
    }

    /**
     * 渲染并写入到文件
     *
     * @param string               $template   模板内容
     * @param array<string, mixed> $variables  变量数组
     * @param string               $outputFile 输出文件路径
     *
     * @return bool 写入是否成功
     */
    public function renderToFile(string $template, array $variables, string $outputFile): bool
    {
        $rendered = $this->render($template, $variables);

        // 确保目录存在
        $dir = \dirname($outputFile);
        if (! \is_dir($dir) && ! \mkdir($dir, 0o755, true)) {
            return false;
        }

        return false !== \file_put_contents($outputFile, $rendered);
    }

    /**
     * 从文件渲染并写入到文件
     *
     * @param string               $templateFile 模板文件路径
     * @param array<string, mixed> $variables    变量数组
     * @param string               $outputFile   输出文件路径
     *
     * @return bool 写入是否成功
     */
    public function renderFileToFile(string $templateFile, array $variables, string $outputFile): bool
    {
        $rendered = $this->renderFromFile($templateFile, $variables);

        // 确保目录存在
        $dir = \dirname($outputFile);
        if (! \is_dir($dir) && ! \mkdir($dir, 0o755, true)) {
            return false;
        }

        return false !== \file_put_contents($outputFile, $rendered);
    }
}
