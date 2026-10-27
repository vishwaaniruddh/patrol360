<?php
// backend/src/Helpers/Response.php

namespace App\Helpers;

class Response
{
    public static function json(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * Polymorphic success response:
     * Accepts (message, data, statusCode) OR (data, message, statusCode)
     */
    public static function success(mixed $arg1 = null, mixed $arg2 = null, int $statusCode = 200): void
    {
        $message = 'Operation successful';
        $payloadData = null;

        if (is_string($arg1) && $arg2 === null) {
            $message = $arg1;
        } elseif (is_string($arg1) && !is_string($arg2)) {
            $message = $arg1;
            $payloadData = $arg2;
        } elseif (!is_string($arg1) && is_string($arg2)) {
            $payloadData = $arg1;
            $message = $arg2;
        } elseif (!is_string($arg1) && $arg2 === null) {
            $payloadData = $arg1;
        } else {
            $message = (string)$arg1;
            $payloadData = $arg2;
        }

        $payload = [
            'success'   => true,
            'message'   => $message,
            'timestamp' => date('c')
        ];

        if ($payloadData !== null) {
            $payload['data'] = $payloadData;
        }

        self::json($payload, $statusCode);
    }

    public static function error(string $message, int $statusCode = 400, mixed $errors = null): void
    {
        $payload = [
            'success'   => false,
            'error'     => $message,
            'timestamp' => date('c')
        ];

        if ($errors !== null) {
            $payload['details'] = $errors;
        }

        self::json($payload, $statusCode);
    }

    public static function paginate(array $items, int $total, int $page, int $limit, string $message = 'Data retrieved successfully'): void
    {
        $totalPages = $limit > 0 ? (int)ceil($total / $limit) : 1;

        self::json([
            'success'    => true,
            'message'    => $message,
            'pagination' => [
                'total'        => $total,
                'page'         => $page,
                'limit'        => $limit,
                'total_pages'  => $totalPages,
                'has_next'     => $page < $totalPages,
                'has_prev'     => $page > 1
            ],
            'data'       => $items,
            'timestamp'  => date('c')
        ], 200);
    }

    public static function paginated(array $items, int $total, int $page, int $limit, string $message = 'Data retrieved successfully'): void
    {
        self::paginate($items, $total, $page, $limit, $message);
    }
}
