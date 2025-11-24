<?php
// Additional Craft/Yii web stubs for controller and module testing.

namespace yii\base {
    class Application {}

    class Module
    {
        public $id;
        public $controllerNamespace = null;
        public $basePath = null;

        public function __construct($id = '', $parent = null, array $config = [])
        {
            $this->id = $id;
            $this->controllerNamespace = $config['controllerNamespace'] ?? $this->controllerNamespace;
        }

        public function init()
        {
        }

        public function setBasePath(string $path): void
        {
            $this->basePath = $path;
        }
    }

    class Action
    {
        public string $id;

        public function __construct(string $id)
        {
            $this->id = $id;
        }
    }

    class Event
    {
        public static array $listeners = [];

        public static function on(string $class, string $name, callable $handler): void
        {
            self::$listeners[] = [$class, $name, $handler];
        }
    }

    class InvalidCallException extends \Exception {}
}

namespace yii\web {
    class ForbiddenHttpException extends \RuntimeException
    {
    }
}

namespace craft\console {
    class Application extends \yii\base\Application
    {
    }
}

namespace craft\web {
    class HeaderCollection
    {
        private array $headers = [];

        public function set(string $name, string $value): void
        {
            $this->headers[$name] = $value;
        }

        public function get(string $name): ?string
        {
            return $this->headers[$name] ?? null;
        }
    }

    class Response
    {
        public const FORMAT_JSON = 'json';

        public ?string $format = null;
        public $data;
        public HeaderCollection $headers;

        public function __construct()
        {
            $this->headers = new HeaderCollection();
        }
    }

    class Request
    {
        private array $bodyParams = [];
        private array $queryParams = [];
        private bool $isPost = false;
        private bool $acceptsJson = false;
        private bool $isAjax = false;

        public function setBodyParams(array $params): void
        {
            $this->bodyParams = $params;
        }

        public function setQueryParams(array $params): void
        {
            $this->queryParams = $params;
        }

        public function setIsPost(bool $isPost): void
        {
            $this->isPost = $isPost;
        }

        public function setAcceptsJson(bool $acceptsJson): void
        {
            $this->acceptsJson = $acceptsJson;
        }

        public function setIsAjax(bool $isAjax): void
        {
            $this->isAjax = $isAjax;
        }

        public function getAcceptsJson(): bool
        {
            return $this->acceptsJson;
        }

        public function getIsAjax(): bool
        {
            return $this->isAjax;
        }

        public function getIsPost(): bool
        {
            return $this->isPost;
        }

        public function getBodyParam(string $name, $default = null)
        {
            return $this->bodyParams[$name] ?? $default;
        }

        public function getQueryParam(string $name, $default = null)
        {
            return $this->queryParams[$name] ?? $default;
        }
    }

    class Session
    {
        private bool $active = false;
        private string $id;

        public function __construct()
        {
            $this->id = uniqid('session-', true);
        }

        public function getIsActive(): bool
        {
            return $this->active;
        }

        public function open(): void
        {
            $this->active = true;
        }

        public function close(): void
        {
            $this->active = false;
        }

        public function getId(): string
        {
            return $this->id;
        }
    }

    class Controller
    {
        public $enableCsrfValidation = false;
        public $defaultAction = 'index';

        public function __construct($id = '', $module = null, array $config = [])
        {
        }

        protected function requireCsrfToken(): void
        {
        }

        protected function requireAcceptsJson(): void
        {
        }

        protected function requirePostRequest(): void
        {
        }

        protected function requirePermission($permission): void
        {
        }

        protected function asJson($data): Response
        {
            $response = new Response();
            $response->format = Response::FORMAT_JSON;
            $response->data = $data;
            return $response;
        }

        protected function renderTemplate(string $template, array $variables = []): Response
        {
            $response = new Response();
            $response->data = ['template' => $template, 'variables' => $variables];
            return $response;
        }

        protected function redirect(string $url): Response
        {
            $response = new Response();
            $response->data = ['redirect' => $url];
            return $response;
        }

        public function beforeAction($action): bool
        {
            return true;
        }
    }

    class UploadedFile
    {
        public string $name;
        public string $tempName;
        public ?string $extension;

        private static array $instances = [];

        public function __construct(string $name, string $tempName, ?string $extension = null)
        {
            $this->name = $name;
            $this->tempName = $tempName;
            $this->extension = $extension;
        }

        public static function setInstance(string $name, UploadedFile $file): void
        {
            self::$instances[$name] = $file;
        }

        public static function getInstanceByName(string $name): ?UploadedFile
        {
            return self::$instances[$name] ?? null;
        }
    }

    class UrlManager {}
    class View {}
}

namespace craft\events {
    class RegisterTemplateRootsEvent
    {
        public array $roots = [];
    }

    class RegisterCpNavItemsEvent
    {
        public array $navItems = [];
    }

    class RegisterUrlRulesEvent
    {
        public array $rules = [];
    }
}

namespace craft\web\twig\variables {
    class Cp {}
    class CraftVariable {}
}

namespace craft\helpers {
    class FileHelper
    {
        public static function createDirectory(string $path): void
        {
            if (!is_dir($path)) {
                mkdir($path, 0777, true);
            }
        }

        public static function unlink(string $path): void
        {
            if (file_exists($path)) {
                \unlink($path);
            }
        }
    }
}
