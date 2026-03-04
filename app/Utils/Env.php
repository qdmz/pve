<?php
class DotEnv {
    protected $path;

    public function __construct(string $path) {
        if (!file_exists($path)) {
            return; // .env 文件不存在时不报错
        }
        
        $this->path = $path;
        
        if (!is_readable($path)) {
            throw new \RuntimeException(sprintf('%s file is not readable', $path));
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            // 跳过注释行
            if (strpos(trim($line), '#') === 0) {
                continue;
            }

            if (strpos($line, '=') === false) {
                continue;
            }

            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);

            // 移除引号
            if (in_array($value[0], ['"', "'"])) {
                $value = substr($value, 1, -1);
            }

            // 设置环境变量
            if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
                putenv(sprintf('%s=%s', $name, $value));
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }

    public static function env($key, $default = null) {
        $value = getenv($key);
        
        if ($value === false) {
            return $default;
        }
        
        return $value;
    }
}

// 加载 .env 文件
if (file_exists(__DIR__ . '/../.env')) {
    new DotEnv(__DIR__ . '/../.env');
}

function env($key, $default = null) {
    return DotEnv::env($key, $default);
}
