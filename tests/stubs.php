<?php

/**
 * Minimal WordPress class stubs for unit testing.
 * These provide just enough structure for type hints and property access.
 */

if (!class_exists('WP_Post')) {
    class WP_Post
    {
        public int $ID = 0;
        public string $post_type = 'post';
        public string $post_status = 'publish';
        public string $post_password = '';
        public string $post_title = '';
        public string $post_content = '';
        public int $post_author = 0;

        public function __construct(?\stdClass $post = null)
        {
            if ($post !== null) {
                foreach (get_object_vars($post) as $key => $value) {
                    $this->$key = $value;
                }
            }
        }
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        private string $code;
        private string $message;
        private mixed $data;

        public function __construct(string $code = '', string $message = '', mixed $data = '')
        {
            $this->code = $code;
            $this->message = $message;
            $this->data = $data;
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }

        public function get_error_data(): mixed
        {
            return $this->data;
        }
    }
}

if (!class_exists('WP_REST_Server')) {
    class WP_REST_Server
    {
        public const READABLE = 'GET';
        public const CREATABLE = 'POST';
    }
}

if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request implements ArrayAccess
    {
        private array $params = [];

        public function __construct(array $params = [])
        {
            $this->params = $params;
        }

        public function offsetExists(mixed $offset): bool
        {
            return isset($this->params[$offset]);
        }

        public function offsetGet(mixed $offset): mixed
        {
            return $this->params[$offset] ?? null;
        }

        public function offsetSet(mixed $offset, mixed $value): void
        {
            $this->params[$offset] = $value;
        }

        public function offsetUnset(mixed $offset): void
        {
            unset($this->params[$offset]);
        }
    }
}

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        public mixed $data;

        public function __construct(mixed $data = null)
        {
            $this->data = $data;
        }
    }
}

if (!class_exists('WP_Query')) {
    class WP_Query
    {
        /** @var array Test hook: set this before constructing to control $posts */
        public static array $stubPosts = [];

        public array $posts = [];

        public function __construct(array $args = [])
        {
            $this->posts = self::$stubPosts;
        }
    }
}

if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

if (!function_exists('is_wp_error')) {
    function is_wp_error(mixed $thing): bool
    {
        return $thing instanceof WP_Error;
    }
}

if (!function_exists('add_shortcode')) {
    function add_shortcode(string $tag, callable $callback): void
    {
        $GLOBALS['test_shortcodes'][$tag] = $callback;
    }
}
