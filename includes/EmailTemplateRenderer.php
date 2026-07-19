<?php
declare(strict_types=1);

final class EmailTemplateRenderer
{
    private string $templateDir;

    public function __construct(string $templateDir)
    {
        $this->templateDir = rtrim($templateDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    public function render(string $templateName, array $data = []): array
    {
        $basePath = $this->templateDir . $templateName;
        $htmlPath = $basePath . '.html';
        $textPath = $basePath . '.txt';

        if (!is_file($htmlPath)) {
            throw new RuntimeException('Email HTML template not found: ' . $templateName);
        }

        $html = file_get_contents($htmlPath);
        $text = is_file($textPath) ? file_get_contents($textPath) : strip_tags((string) $html);

        if ($html === false || $text === false) {
            throw new RuntimeException('Failed to load email template: ' . $templateName);
        }

        $replacements = [];
        foreach ($data as $key => $value) {
            $stringValue = is_scalar($value) || $value === null ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $replacements['{{' . $key . '}}'] = $stringValue;
        }

        return [
            'html' => strtr($html, $replacements),
            'text' => strtr($text, $replacements),
        ];
    }
}
