<?php
// backend/src/Helpers/CameraHelper.php

declare(strict_types=1);

namespace App\Helpers;

class CameraHelper
{
    /**
     * Build the live view WebRTC / HTTP streaming URL
     * Format: http://223.182.122.63:7000/?name={user}-{pass}-{ip}-{port}-{dvrname}-{channel}
     */
    public static function buildLiveViewUrl(array $camera): ?string
    {
        $user = trim((string)($camera['username'] ?? ''));
        $pass = trim((string)($camera['password'] ?? ''));
        $ip = trim((string)($camera['ip'] ?? ''));
        $dvrname = trim((string)($camera['dvrname'] ?? ''));
        $channel = trim((string)($camera['channel'] ?? '1'));
        $port = trim((string)(!empty($camera['rtsp_port']) ? $camera['rtsp_port'] : ($camera['dvr_port'] ?? '554')));

        if (empty($user) || empty($pass) || empty($ip)) {
            return null;
        }

        // Dedicated streaming server on port 7000 (defaults to camera IP or central media gateway 223.182.122.63)
        $streamHost = !empty($ip) ? $ip : '223.182.122.63';
        $streamPort = '7000';

        return "http://{$streamHost}:{$streamPort}/?name={$user}-{$pass}-{$ip}-{$port}-{$dvrname}-{$channel}";
    }

    /**
     * Decorate camera array with live_view_url and HTTPS proxy stream_url
     */
    public static function formatCamera(array $camera): array
    {
        $camera['live_view_url'] = self::buildLiveViewUrl($camera);
        $streamId = $camera['id'] ?? ($camera['camera_id'] ?? null);
        $camera['stream_url'] = !empty($streamId) ? "/api/cameras/{$streamId}/stream" : null;
        return $camera;
    }

    /**
     * Decorate a list of camera records
     */
    public static function formatCameras(array $cameras): array
    {
        return array_map([self::class, 'formatCamera'], $cameras);
    }
}

