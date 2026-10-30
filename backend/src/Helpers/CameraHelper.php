<?php
// backend/src/Helpers/CameraHelper.php

declare(strict_types=1);

namespace App\Helpers;

class CameraHelper
{
    /**
     * Standard DVR & Central Media Gateway default configurations
     */
    public const DEFAULT_USERNAME = 'admin';
    public const DEFAULT_PASSWORD = 'ZTKBEV';
    public const DEFAULT_IP       = '223.182.122.63';
    public const DEFAULT_DVRNAME  = 'Hikvision';
    public const DEFAULT_RTSP_PORT= '554';
    public const DEFAULT_DVR_PORT = '80';
    public const STREAM_HOST      = '223.182.122.63';
    public const STREAM_PORT      = '7000';

    /**
     * Derive channel number (1 to 4) based on mount position or camera identifier
     */
    public static function resolveChannel(array $camera): int
    {
        if (!empty($camera['channel']) && (int)$camera['channel'] > 0) {
            return (int)$camera['channel'];
        }

        $mount = strtoupper(trim((string)($camera['mount_position'] ?? '')));
        $camId = (string)($camera['camera_id'] ?? '');

        return match (true) {
            $mount === 'FRONT' || str_contains($camId, '01') => 1,
            $mount === 'REAR'  || str_contains($camId, '02') || str_contains($camId, '05') => 2,
            $mount === 'LEFT'  || str_contains($camId, '03') => 3,
            $mount === 'RIGHT' || str_contains($camId, '04') => 4,
            default => 1
        };
    }

    /**
     * Build the live view WebRTC / HTTP streaming URL
     * Format: http://223.182.122.63:7000/?name={user}-{pass}-{ip}-{port}-{dvrname}-{channel}
     */
    public static function buildLiveViewUrl(array $camera): string
    {
        $user = trim((string)($camera['username'] ?? ''));
        $pass = trim((string)($camera['password'] ?? ''));
        $ip = trim((string)($camera['ip'] ?? ''));
        $dvrname = trim((string)($camera['dvrname'] ?? ''));
        $port = trim((string)(!empty($camera['rtsp_port']) ? $camera['rtsp_port'] : ($camera['dvr_port'] ?? '')));

        // Resilient fallback to patrol DVR master defaults if credentials are not explicitly set
        if (empty($user)) $user = self::DEFAULT_USERNAME;
        if (empty($pass)) $pass = self::DEFAULT_PASSWORD;
        if (empty($ip)) $ip = self::DEFAULT_IP;
        if (empty($dvrname)) $dvrname = self::DEFAULT_DVRNAME;
        if (empty($port)) $port = self::DEFAULT_RTSP_PORT;

        $channel = self::resolveChannel($camera);
        $streamHost = self::STREAM_HOST;
        $streamPort = self::STREAM_PORT;

        return "http://{$streamHost}:{$streamPort}/?name={$user}-{$pass}-{$ip}-{$port}-{$dvrname}-{$channel}";
    }

    /**
     * Decorate camera array with complete DVR metadata, live_view_url, and HTTPS proxy stream_url
     */
    public static function formatCamera(array $camera): array
    {
        $channel = self::resolveChannel($camera);

        if (empty($camera['username']))  $camera['username']  = self::DEFAULT_USERNAME;
        if (empty($camera['password']))  $camera['password']  = self::DEFAULT_PASSWORD;
        if (empty($camera['ip']))        $camera['ip']        = self::DEFAULT_IP;
        if (empty($camera['dvrname']))   $camera['dvrname']   = self::DEFAULT_DVRNAME;
        if (empty($camera['rtsp_port'])) $camera['rtsp_port'] = self::DEFAULT_RTSP_PORT;
        if (empty($camera['dvr_port']))  $camera['dvr_port']  = self::DEFAULT_DVR_PORT;
        $camera['channel'] = $channel;

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
